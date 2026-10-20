<?php

declare(strict_types=1);

/**
 * Hand-rolled MTProto 2.0 client — TCP intermediate transport, DH handshake,
 * encrypted RPC, update stream. Single-threaded cooperative design:
 * invoke() pumps ALL registered clients while waiting for its result, so two
 * clients (bot + assistant) live happily in one PHP process.
 */
final class MtProto
{
    public const LAYER = 230;

    /** @var string[][] production DC endpoints */
    public const DC = [
        ['149.154.175.50', '443'],
        ['149.154.167.51', '443'],
        ['149.154.175.100', '443'],
        ['149.154.167.91', '443'],
        ['91.108.56.130', '443'],
    ];

    /** @var MtProto[] all live clients pumped together */
    private static array $clients = [];
    /** @var array<string, int> unknown ctor message → hit count (log once) */
    private static array $unknownCtor = [];

    public string $host;
    public int $port;
    /** @var resource|null */
    private $sock = null;
    private string $rbuf = '';
    private string $wbuf = '';

    private ?string $authKey = null;
    private string $authKeyId = "\0\0\0\0\0\0\0\0";
    private int $salt = 0;
    private int $sessionId = 0;
    private int $lastMsgId = 0;
    private int $seqNo = 0;
    private int $timeOffsetMs = 0;
    private bool $inited = false;

    /** @var array<int, object{node:?TlNode,done:bool,err:?string}> */
    private array $pending = [];
    /** @var array<int, string> */
    private array $pendingBodies = [];
    /** @var callable|null function(TlNode $update): void */
    public $onUpdate = null;
    /** @var callable|null function(): void */
    public $onDisconnect = null;
    /** @var callable|null function(int dc): void */
    public $onMigrate = null;

    public string $tag = 'mtproto';

    public function __construct(public readonly int $apiId, public readonly string $apiHash, int $dc = 2)
    {
        [$h, $p] = self::DC[max(0, min(4, $dc - 1))];
        $this->host = $h;
        $this->port = (int) $p;
        self::$clients[] = $this;
    }

    public static function clients(): array
    {
        return self::$clients;
    }

    public function connected(): bool
    {
        return $this->sock !== null;
    }

    public function authKeyAvailable(): bool
    {
        return $this->authKey !== null;
    }

    public function currentDc(): int
    {
        foreach (self::DC as $i => [$h, $p]) {
            if ($h === $this->host) {
                return $i + 1;
            }
        }
        return 2;
    }

    // ── connection ───────────────────────────────────────────────────────────

    public function connect(): void
    {
        $this->closeSocket();
        $sock = stream_socket_client(
            'tcp://' . $this->host . ':' . $this->port,
            $errno, $errstr, 15.0,
            STREAM_CLIENT_CONNECT
        );
        if ($sock === false) {
            throw new RuntimeException("connect: $errstr ($errno)");
        }
        stream_set_blocking($sock, true);
        // TCP full requires nothing special: use intermediate transport 0xeeeeeeee
        fwrite($sock, "\xee\xee\xee\xee");
        $this->sock = $sock;
        $this->rbuf = '';
        $this->wbuf = '';
        $this->inited = false;
        // session_id is per-connection: randomise now (handshake overwrites later)
        $this->sessionId = unpack('P', self::random(8))[1];
        $this->seqNo = 0;
    }

    /** DH handshake — fresh auth key. */
    public function handshake(): void
    {
        $res = $this->sendUnencrypted(TlCodec::write((new TlNode('req_pq_multi'))
            ->put('nonce', self::random(16))));
        if ($res->name !== 'resPQ') {
            throw new RuntimeException('handshake: expected resPQ, got ' . $res->name);
        }
        $nonce = $res->bytes('nonce');
        $serverNonce = $res->bytes('server_nonce');
        $pq = gmp_init(bin2hex($res->bytes('pq')), 16);

        [$p, $q] = self::factorize($pq);
        if (gmp_cmp($p, $q) > 0) {
            [$p, $q] = [$q, $p];
        }

        $newNonce = self::random(32);
        $inner = (new TlNode('p_q_inner_data'))
            ->put('pq', self::gmpBytes($pq))
            ->put('p', self::gmpBytes($p))
            ->put('q', self::gmpBytes($q))
            ->put('nonce', $nonce)
            ->put('server_nonce', $serverNonce)
            ->put('new_nonce', $newNonce);
        $encrypted = RsaKey::pad(TlCodec::write($inner));

        $dh = $this->sendUnencrypted(TlCodec::write((new TlNode('req_DH_params'))
            ->put('nonce', $nonce)
            ->put('server_nonce', $serverNonce)
            ->put('p', self::gmpBytes($p))
            ->put('q', self::gmpBytes($q))
            ->put('public_key_fingerprint', RsaKey::FINGERPRINT)
            ->put('encrypted_data', $encrypted)));
        if ($dh->name !== 'server_DH_params_ok') {
            throw new RuntimeException('handshake: DH params rejected (' . $dh->name . ')');
        }

        $answer = (new AesIge(self::dhKey($newNonce, $serverNonce)))
            ->decrypt(self::dhIv($newNonce, $serverNonce), $dh->bytes('encrypted_answer'));
        $ar = new TlReader($answer);
        $ar->raw(20);
        $dhInner = TlCodec::readFrom($ar);
        $g = gmp_init($dhInner->i('g'));
        $dhPrime = gmp_init(bin2hex($dhInner->bytes('dh_prime')), 16);
        $gA = gmp_init(bin2hex($dhInner->bytes('g_a')), 16);
        $serverTime = $dhInner->l('server_time') * 1000;
        $this->timeOffsetMs = $serverTime - (int) (microtime(true) * 1000);

        $b = gmp_init(bin2hex(self::random(256)), 16);
        $gB = gmp_powm($g, $b, $dhPrime);
        $key = gmp_powm($gA, $b, $dhPrime);
        $this->authKey = self::toFixed(gmp_export($key), 256);
        $this->authKeyId = substr(sha1($this->authKey, true), 12, 8);

        $cInner = TlCodec::write((new TlNode('client_DH_inner_data'))
            ->put('nonce', $nonce)
            ->put('server_nonce', $serverNonce)
            ->put('retry_id', 0)
            ->put('g_b', self::gmpBytes($gB)));
        $payload = sha1($cInner, true) . $cInner;
        $pad = (16 - (strlen($payload) % 16)) % 16;
        if ($pad > 0) {
            $payload .= self::random($pad);
        }
        $enc = (new AesIge(self::dhKey($newNonce, $serverNonce)))->encrypt(self::dhIv($newNonce, $serverNonce), $payload);

        $gen = $this->sendUnencrypted(TlCodec::write((new TlNode('set_client_DH_params'))
            ->put('nonce', $nonce)
            ->put('server_nonce', $serverNonce)
            ->put('encrypted_data', $enc)));
        if ($gen->name !== 'dh_gen_ok') {
            throw new RuntimeException('handshake: DH generation failed (' . $gen->name . ')');
        }
        $checkHash = sha1($newNonce . "\x01" . substr(sha1($this->authKey, true), 0, 8), true);
        if (substr($checkHash, 4, 16) !== $gen->bytes('new_nonce_hash1')) {
            throw new RuntimeException('handshake: auth key mismatch (new_nonce_hash1)');
        }

        $this->sessionId = unpack('P', self::random(8))[1];
        $this->seqNo = 0;
        $this->lastMsgId = 0;
        echo "[{$this->tag}] handshake ok — dc={$this->host} layer=" . self::LAYER . "\n";
    }

    private static function dhKey(string $newNonce, string $serverNonce): string
    {
        $h1 = sha1($newNonce . $serverNonce, true);
        $h2 = sha1($serverNonce . $newNonce, true);
        return substr($h1, 0, 20) . substr($h2, 0, 12);
    }

    private static function dhIv(string $newNonce, string $serverNonce): string
    {
        $h2 = sha1($serverNonce . $newNonce, true);
        $h3 = sha1($newNonce . $newNonce, true);
        return substr($h2, 12, 8) . substr($h3, 0, 20) . substr($newNonce, 0, 4);
    }

    // ── session import / export ──────────────────────────────────────────────

    /** Accepts "v1\ndc\n<hex>\n" or base64url (MadelineProto-style) strings. */
    public function importSession(string $s): bool
    {
        $decoded = self::decodeSession($s);
        if ($decoded === null) {
            return false;
        }
        [$dc, $key] = $decoded;
        [$h, $p] = self::DC[$dc - 1];
        $this->host = $h;
        $this->port = (int) $p;
        $this->authKey = $key;
        $this->authKeyId = substr(sha1($key, true), 12, 8);
        $this->salt = 0;
        // session_id must be fresh & random per connection — a zero session_id
        // makes the server reject the key outright (transport error -404)
        $this->sessionId = unpack('P', self::random(8))[1];
        $this->seqNo = 0;
        $this->lastMsgId = 0;
        $this->inited = false;
        return true;
    }

    /** @return array{0:int,1:string}|null [dc, authKey] */
    public static function decodeSession(string $s): ?array
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (str_starts_with($s, 'v1')) {
            $parts = preg_split('/\R/', $s);
            if (count($parts) < 3) {
                return null;
            }
            $dc = (int) trim($parts[1]);
            $key = @hex2bin(trim($parts[2]));
            if ($key === false || strlen($key) !== 256 || $dc < 1 || $dc > 5) {
                return null;
            }
            return [$dc, $key];
        }
        $raw = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - (strlen($s) % 4)) % 4), true);
        if ($raw === false || strlen($raw) < 258 || ord($raw[0]) < 1 || ord($raw[0]) > 5) {
            return null;
        }
        return [ord($raw[0]), substr($raw, 6, 256)];
    }

    public function exportSession(): ?string
    {
        if ($this->authKey === null) {
            return null;
        }
        return "v1\n" . $this->currentDc() . "\n" . bin2hex($this->authKey) . "\n";
    }

    // ── RPC ──────────────────────────────────────────────────────────────────

    public function invoke(TlNode $query, int $timeoutSeconds = 30): TlNode
    {
        if (!$this->connected()) {
            throw new RuntimeException($this->tag . ': not connected');
        }
        $floodTries = 0;
        while (true) {
            try {
                return $this->invokeOnce($query, $timeoutSeconds);
            } catch (RuntimeException $e) {
                // Transparent FLOOD_WAIT handling — the server tells us exactly
                // how long to wait; retrying is always correct for idempotent RPCs.
                if ($floodTries < 2 && preg_match('/FLOOD_WAIT_(\d+)/', $e->getMessage(), $m)) {
                    $wait = min((int) $m[1] + 1, 300);
                    echo "[{$this->tag}] flood wait {$wait}s ({$query->name})\n";
                    sleep($wait);
                    $floodTries++;
                    continue;
                }
                throw $e;
            }
        }
    }

    private function invokeOnce(TlNode $query, int $timeoutSeconds): TlNode
    {
        $body = TlCodec::write($query);
        $bare = in_array($query->name, ['ping', 'pong', 'ping_delay_disconnect', 'getFutureSalts'], true);
        if (!$this->inited && !$bare) {
            $init = (new TlNode('initConnection'))
                ->put('api_id', $this->apiId)
                ->put('device_model', 'YumiMusic')
                ->put('system_version', 'Linux')
                ->put('app_version', '1.0')
                ->put('system_lang_code', 'en')
                ->put('lang_pack', '')
                ->put('lang_code', 'en')
                ->put('query', $query);
            $body = TlCodec::write((new TlNode('invokeWithLayer'))
                ->put('layer', self::LAYER)
                ->put('query', $init));
            $this->inited = true;
        }
        $msgId = $this->nextMsgId();
        $slot = new stdClass();
        $slot->node = null;
        $slot->done = false;
        $slot->err = null;
        $this->pending[$msgId] = $slot;
        $this->pendingBodies[$msgId] = $body;
        $this->sendEncrypted($body, true, $msgId);

        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($slot->done) {
                unset($this->pendingBodies[$msgId]);
                if ($slot->err !== null) {
                    throw new RuntimeException($slot->err);
                }
                return $slot->node;
            }
            self::pump(0.15, $this);
        }
        unset($this->pending[$msgId], $this->pendingBodies[$msgId]);
        throw new RuntimeException('mtproto: rpc timeout (' . $query->name . ')');
    }

    /**
     * Pump every registered client's socket: read + handle packets, ack, etc.
     * Runs until $wake client's pending slot for its $watchMsgId completes,
     * or the timeout elapses.
     */
    public static function pump(float $timeout, ?MtProto $wake = null): void
    {
        $read = [];
        foreach (self::$clients as $c) {
            if ($c->sock !== null) {
                $read[] = $c->sock;
            }
        }
        if ($read === []) {
            usleep((int) ($timeout * 1_000_000));
            return;
        }
        $except = null;
        $write = [];
        foreach (self::$clients as $c) {
            if ($c->sock !== null && $c->wbuf !== '') {
                $write[] = $c->sock;
            }
        }
        $ready = @stream_select($read, $write, $except, (int) $timeout, (int) (($timeout * 1e6) % 1_000_000));
        if ($ready === false || $ready === 0) {
            return;
        }
        foreach (self::$clients as $c) {
            if ($c->sock === null) {
                continue;
            }
            if ($c->sock !== null && in_array($c->sock, $write, true)) {
                $n = @fwrite($c->sock, $c->wbuf);
                if ($n !== false) {
                    $c->wbuf = substr($c->wbuf, $n);
                }
            }
            if (in_array($c->sock, $read, true) && $c->sock !== null) {
                $c->drain();
            }
        }
    }

    private function sendEncrypted(string $body, bool $content, ?int $withMsgId = null): int
    {
        $msgId = $withMsgId ?? $this->nextMsgId();
        if ($content) {
            $this->seqNo++;
        }
        $seq = $this->seqNo * 2 - ($content ? 1 : 0);
        $total = 32 + strlen($body);
        $pad = 16 - ($total % 16);
        if ($pad < 12) {
            $pad += 16;
        }
        $plain = pack('P3', $this->salt, $this->sessionId, $msgId)
            . pack('VV', $seq, strlen($body))
            . $body
            . self::random($pad);
        $msgKey = substr(hash('sha256', substr($this->authKey, 88, 32) . $plain, true), 8, 16);
        [$key, $iv] = self::calcKey($this->authKey, $msgKey, true);
        $cipher = (new AesIge($key))->encrypt($iv, $plain);
        $this->writePacket($this->authKeyId . $msgKey . $cipher);
        return $msgId;
    }

    private function sendUnencrypted(string $body): TlNode
    {
        $msgId = $this->nextMsgId();
        $this->writePacket(pack('P', 0) . pack('P', $msgId) . pack('V', strlen($body)) . $body);
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            $pkt = $this->tryReadPacket(0.2);
            if ($pkt === null) {
                continue;
            }
            if (strlen($pkt) === 4) {
                throw new RuntimeException('handshake: transport error ' . self::readI32($pkt));
            }
            if (strlen($pkt) < 20) {
                continue;
            }
            $keyId = substr($pkt, 0, 8);
            if ($keyId !== "\0\0\0\0\0\0\0\0") {
                throw new RuntimeException('handshake: unexpected encrypted reply');
            }
            $len = unpack('V', substr($pkt, 16, 4))[1];
            $payload = substr($pkt, 20, $len);
            if (getenv('YUMI_TL_DEBUG')) {
                echo "[{$this->tag}] plain reply len=$len hex=" . bin2hex($payload) . "\n";
            }
            $n = TlCodec::read($payload);
            if ($n->name === 'msg_container') {
                foreach ($n->list('messages') as $m) {
                    $inner = TlCodec::read($m->bytes('body'));
                    if ($inner->name !== 'pong') {
                        return $inner;
                    }
                }
                continue;
            }
            if ($n->name === 'pong') {
                continue;
            }
            return $n;
        }
        throw new RuntimeException('handshake: timeout');
    }

    private function writePacket(string $payload): void
    {
        $pkt = pack('V', strlen($payload)) . $payload;
        if ($this->sock === null) {
            throw new RuntimeException($this->tag . ': socket closed');
        }
        stream_set_blocking($this->sock, true);
        $n = @fwrite($this->sock, $pkt);
        if ($n !== strlen($pkt)) {
            $this->wbuf = substr($pkt, $n === false ? 0 : $n);
        }
        stream_set_blocking($this->sock, false);
    }

    /** Read all available packets from the socket (non-blocking). */
    private function drain(): void
    {
        stream_set_blocking($this->sock, false);
        while (true) {
            if ($this->sock === null) {
                break;
            }
            $chunk = @fread($this->sock, 65536);
            if ($chunk === false || $chunk === '') {
                if (feof($this->sock)) {
                    $this->handleDisconnect('connection closed by server');
                }
                break;
            }
            $this->rbuf .= $chunk;
            while (($pkt = $this->popPacket()) !== null) {
                $this->handlePacket($pkt);
            }
        }
    }

    /** @return string|null one complete transport packet from rbuf */
    private function popPacket(): ?string
    {
        if (strlen($this->rbuf) < 4) {
            return null;
        }
        $n = unpack('V', substr($this->rbuf, 0, 4))[1];
        if ($n === 0 || $n > (1 << 24)) {
            $this->rbuf = substr($this->rbuf, 4);
            echo "[{$this->tag}] transport error $n (" . self::transportErrorName($n) . ")\n";
            if ($n === -404 || $n === -444) {
                $this->handleDisconnect('fatal transport error');
            }
            return $this->popPacket();
        }
        if (strlen($this->rbuf) < 4 + $n) {
            return null;
        }
        $pkt = substr($this->rbuf, 4, $n);
        $this->rbuf = substr($this->rbuf, 4 + $n);
        return $pkt;
    }

    private function tryReadPacket(float $waitSec): ?string
    {
        if ($this->sock === null) {
            throw new RuntimeException('socket closed during handshake');
        }
        $deadline = microtime(true) + $waitSec;
        while (strlen($this->rbuf) < 4 || strlen($this->rbuf) < 4 + unpack('V', substr($this->rbuf, 0, 4))[1]) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                return null;
            }
            stream_set_blocking($this->sock, true);
            stream_set_timeout($this->sock, 0, (int) ($left * 1e6));
            $chunk = @fread($this->sock, 65536);
            if ($chunk === false || $chunk === '') {
                if (feof($this->sock)) {
                    throw new RuntimeException('connection closed during handshake');
                }
                continue;
            }
            $this->rbuf .= $chunk;
        }
        return $this->popPacket();
    }

    private function handlePacket(string $pkt): void
    {
        if (strlen($pkt) === 4) {
            $err = self::readI32($pkt);
            echo "[{$this->tag}] transport error $err (" . self::transportErrorName($err) . ")\n";
            if ($err === -404 || $err === -444) {
                $this->handleDisconnect('fatal transport error');
            }
            return;
        }
        if (strlen($pkt) < 24) {
            return;
        }
        $keyId = substr($pkt, 0, 8);
        if ($keyId === "\0\0\0\0\0\0\0\0") {
            return;
        }
        if ($keyId !== $this->authKeyId) {
            echo "[{$this->tag}] packet for foreign auth key\n";
            return;
        }
        $msgKey = substr($pkt, 8, 16);
        $cipher = substr($pkt, 24);
        [$key, $iv] = self::calcKey($this->authKey, $msgKey, false);
        $plain = (new AesIge($key))->decrypt($iv, $cipher);
        $check = substr(hash('sha256', substr($this->authKey, 96, 32) . $plain, true), 8, 16);
        if ($check !== $msgKey) {
            echo "[{$this->tag}] bad msg_key, dropping packet\n";
            return;
        }
        $msgId = unpack('P', substr($plain, 16, 8))[1];
        $seq = unpack('V', substr($plain, 24, 4))[1];
        $len = unpack('V', substr($plain, 28, 4))[1];
        if (32 + $len > strlen($plain)) {
            return;
        }
        $body = substr($plain, 32, $len);
        if (($seq & 1) === 1) {
            $this->acks[] = $msgId;
        }
        $this->handle($body);
        if ($this->acks !== []) {
            $ids = $this->acks;
            $this->acks = [];
            $vec = new TlNode('vector');
            $node = (new TlNode('msgs_ack'))->put('msg_ids', $ids);
            try {
                $this->sendEncrypted(TlCodec::write($node), false);
            } catch (Throwable $ignored) {
            }
        }
    }

    /** @var int[] */
    private array $acks = [];

    private function handle(string $body): void
    {
        try {
            $n = TlCodec::read($body);
        } catch (Throwable $e) {
            // Unknown constructors arrive constantly on busy accounts (new
            // server-side update types). Log each one once, never spam.
            $key = $e->getMessage();
            if (str_contains($key, 'unknown constructor')) {
                if (!isset(self::$unknownCtor[$key])) {
                    self::$unknownCtor[$key] = 0;
                    echo "[{$this->tag}] {$key} (further occurrences silenced)\n";
                }
                self::$unknownCtor[$key]++;
                return;
            }
            echo "[{$this->tag}] cannot parse update: " . $key . "\n";
            return;
        }
        try {
            $this->dispatch($n);
        } catch (Throwable $e) {
            // A single unparseable update must never abort an in-flight RPC.
            $this->noteUnknown($e);
        }
    }

    /** Log unknown-constructor noise once per signature. */
    private function noteUnknown(Throwable $e): void
    {
        $key = $e->getMessage();
        if (str_contains($key, 'unknown constructor')) {
            if (!isset(self::$unknownCtor[$key])) {
                self::$unknownCtor[$key] = 0;
                echo "[{$this->tag}] {$key} (further occurrences silenced)\n";
            }
            self::$unknownCtor[$key]++;
            return;
        }
        echo "[{$this->tag}] cannot parse update: " . $key . "\n";
    }

    private function dispatch(TlNode $n): void
    {
        switch ($n->name) {
            case 'msg_container':
                foreach ($n->list('messages') as $m) {
                    try {
                        $this->handle($m->bytes('body'));
                    } catch (Throwable $e) {
                        $this->noteUnknown($e);
                    }
                }
                return;
            case 'gzip_packed':
                $inner = gzdecode($n->bytes('packed_data'));
                if ($inner !== false) {
                    $this->handle($inner);
                }
                return;
            case 'rpc_result':
                $reqId = (int) $n->l('req_msg_id');
                $res = $n->node('result');
                if ($res !== null && $res->name === 'gzip_packed') {
                    $inner = gzdecode($res->bytes('packed_data'));
                    try {
                        $res = $inner === false ? null : TlCodec::read($inner);
                    } catch (Throwable $e) {
                        if (getenv('YUMI_DUMP_RAW')) {
                            echo "[{$this->tag}] raw($reqId)=" . bin2hex(substr((string) $inner, 0, 600)) . "\n";
                        }
                        $slot = $this->pending[$reqId] ?? null;
                        if ($slot !== null) {
                            $slot->err = $e->getMessage();
                            $slot->done = true;
                        }
                        return;
                    }
                }
                $slot = $this->pending[$reqId] ?? null;
                if ($slot === null) {
                    return;
                }
                if ($res !== null && $res->name === 'rpc_error') {
                    $slot->err = 'rpc ' . $res->i('error_code') . ': ' . $res->str('error_message');
                } else {
                    $slot->node = $res;
                }
                $slot->done = true;
                return;
            case 'bad_server_salt':
                $this->salt = (int) $n->l('new_server_salt');
                $badId = (int) $n->l('bad_msg_id');
                echo "[{$this->tag}] bad_server_salt for $badId, retrying\n";
                $retry = $this->pendingBodies[$badId] ?? null;
                if ($retry !== null) {
                    unset($this->pendingBodies[$badId]);
                    $slot = $this->pending[$badId] ?? null;
                    unset($this->pending[$badId]);
                    $newId = $this->nextMsgId();
                    if ($slot !== null) {
                        $this->pending[$newId] = $slot; // same object: invoke() keeps watching it
                        $this->pendingBodies[$newId] = $retry;
                    }
                    try {
                        $this->sendEncrypted($retry, true, $newId);
                    } catch (Throwable $e) {
                        echo "[{$this->tag}] salt retry failed: " . $e->getMessage() . "\n";
                    }
                }
                return;
            case 'new_session_created':
                $this->salt = (int) $n->l('server_salt');
                return;
            case 'pong':
                // bare ping is answered with a bare pong (msg_id echoes the ping's msg_id)
                $pid = (int) $n->l('msg_id');
                $slot = $this->pending[$pid] ?? null;
                if ($slot !== null) {
                    unset($this->pendingBodies[$pid]);
                    $slot->node = $n;
                    $slot->done = true;
                }
                return;
            case 'bad_msg_notification':
                echo "[{$this->tag}] bad_msg " . $n->i('error_code') . " for " . $n->l('bad_msg_id') . "\n";
                return;
            case 'msgs_ack':
            case 'msgs_state_info':
            case 'msgs_all_info':
                return;
            default:
                if ($this->onUpdate !== null) {
                    ($this->onUpdate)($n);
                }
        }
    }

    public function getLastError(int $msgId): ?array
    {
        return null;
    }

    private function handleDisconnect(string $why): void
    {
        echo "[{$this->tag}] disconnected: $why\n";
        $this->closeSocket();
        if ($this->onDisconnect !== null) {
            ($this->onDisconnect)();
        }
    }

    public function pingLoopTick(): void
    {
        // ping_delay_disconnect sent by the app layer on a timer
    }

    public function sendPing(): void
    {
        try {
            $this->sendEncrypted(TlCodec::write((new TlNode('ping_delay_disconnect'))
                ->put('ping_id', (int) (microtime(true) * 1e6))
                ->put('disconnect_delay', 75)), false);
        } catch (Throwable $ignored) {
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    public function nextMsgId(): int
    {
        $nowSec = (int) (((microtime(true) * 1000) + $this->timeOffsetMs) / 1000);
        $id = ($nowSec << 32) | (int) ((microtime(true) * 1e6) & 0xfffffffc);
        if ($id <= $this->lastMsgId) {
            $id = $this->lastMsgId + 4;
        }
        $this->lastMsgId = $id;
        return $id;
    }

    private static function transportErrorName(int $code): string
    {
        return match ($code) {
            -1 => 'bad checksum / unknown',
            -2 => 'msg_id too high or too low (clock skew)',
            -3 => 'bad message length',
            -4 => 'bad seq_no',
            -404 => 'auth key not found',
            -444 => 'invalid dc / auth key',
            default => 'unknown',
        };
    }

    /** MTProto 2.0 AES key/IV derivation (x=0 client, x=8 server). */
    private static function calcKey(string $authKey, string $msgKey, bool $client): array
    {
        $x = $client ? 0 : 8;
        $sha256a = hash('sha256', $msgKey . substr($authKey, $x, 36), true);
        $sha256b = hash('sha256', substr($authKey, $x + 40, 36) . $msgKey, true);
        $key = substr($sha256a, 0, 8) . substr($sha256b, 8, 16) . substr($sha256a, 24, 8);
        $iv = substr($sha256b, 0, 8) . substr($sha256a, 8, 16) . substr($sha256b, 24, 8);
        return [$key, $iv];
    }

    /** Pollard rho factorisation of pq (GMP). */
    private static function factorize(\GMP $n): array
    {
        if (gmp_mod($n, 2) == 0) {
            return [gmp_init(2), gmp_div_q($n, 2)];
        }
        $x = gmp_init(random_int(2, 1000));
        $y = $x;
        $c = gmp_init(random_int(1, 1000));
        $d = gmp_init(1);
        while (gmp_cmp($d, 1) === 0) {
            $x = gmp_mod(gmp_add(gmp_mul($x, $x), $c), $n);
            $y = gmp_mod(gmp_add(gmp_mul($y, $y), $c), $n);
            $y = gmp_mod(gmp_add(gmp_mul($y, $y), $c), $n);
            $d = gmp_gcd(gmp_abs(gmp_sub($x, $y)), $n);
        }
        if (gmp_cmp($d, $n) === 0) {
            return self::factorize($n);
        }
        return [$d, gmp_div_q($n, $d)];
    }

    private static function gmpBytes(\GMP $v): string
    {
        $b = gmp_export($v);
        if ($b === false) {
            return "\0";
        }
        $trimmed = ltrim($b, "\0");
        return $trimmed === '' ? "\0" : $trimmed;
    }

    private static function toFixed(string $b, int $size): string
    {
        if (strlen($b) >= $size) {
            return substr($b, -$size);
        }
        return str_repeat("\0", $size - strlen($b)) . $b;
    }

    private static function random(int $n): string
    {
        return random_bytes($n);
    }

    private static function readI32(string $b): int
    {
        return unpack('l', $b)[1];
    }

    private function closeSocket(): void
    {
        if ($this->sock !== null) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    /** Reconnects to another DC after NETWORK_MIGRATE_X: fresh DH handshake there. */
    public function migrateTo(int $dc): void
    {
        $this->closeSocket();
        foreach ($this->pending as $slot) {
            $slot->err = 'mtproto: migrated to dc' . $dc;
            $slot->done = true;
        }
        $this->pending = [];
        $this->pendingBodies = [];
        [$h, $p] = self::DC[max(0, min(4, $dc - 1))];
        $this->host = $h;
        $this->port = (int) $p;
        $this->authKey = null;
        $this->authKeyId = "\0\0\0\0\0\0\0\0";
        $this->salt = 0;
        $this->seqNo = 0;
        $this->lastMsgId = 0;
        $this->inited = false;
        $this->connect();
        $this->handshake();
        echo "[{$this->tag}] migrated to dc$dc ($h)\n";
    }

    public function close(): void
    {
        $this->closeSocket();
        self::$clients = array_values(array_filter(self::$clients, fn($c) => $c !== $this));
    }
}
