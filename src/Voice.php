<?php

declare(strict_types=1);

/**
 * Voice engine per assistant: ntgcalls instance + VC join/leave/play.
 * Streaming flow: sources are configured BEFORE ntg_create so the
 * SDP offer carries the video m-line (Telegram discards video frames from
 * audio-only offers), then phone.joinGroupCall(offer) → ntg_connect(answer).
 */
final class Voice
{
    public Ntg $ntg;
    public readonly MtProto $mt;
    private Peers $peers;
    /** @var array<int, TlNode> chatId → inputGroupCall */
    private array $groupCalls = [];
    /** @var array<int, bool> chats whose voice chat this assistant opened */
    private array $createdCalls = [];
    /** @var array<int, array{cmd:string, w:int, h:int, fps:int, start:float, next:float, proc:resource}> */
    private array $cameras = [];

    public function __construct(
        MtProto $mt,
        private string $tag = 'yumi-voice',
        private bool $videoEnabled = false,
    ) {
        $this->mt = $mt;
        $this->ntg = new Ntg();
        $this->peers = new Peers($mt, $tag);
    }

    public function peers(): Peers
    {
        return $this->peers;
    }

    // ── group call resolution ────────────────────────────────────────────────

    private function groupCall(int $chatId): ?TlNode
    {
        // Transient parse/transport hiccups and stale peer caches are common
        // right after a reconnect, so the lookup gets three attempts: refresh
        // the peer cache, then reopen the socket, then give up.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                if ($chatId <= -1000000000000) {
                    $res = $this->mt->invoke((new TlNode('channels.getFullChannel'))
                        ->put('channel', $this->peers->inputPeer($chatId)), 25);
                    $call = $res->node('full_chat')->node('call');
                } else {
                    $res = $this->mt->invoke((new TlNode('messages.getFullChat'))
                        ->put('chat_id', -$chatId), 25);
                    $call = $res->node('full_chat')->node('call');
                }
                if ($call === null || $call->name !== 'inputGroupCall') {
                    echo "[{$this->tag}] no active call in $chatId\n";
                    return null;
                }
                $this->groupCalls[$chatId] = $call;
                return $call;
            } catch (Throwable $e) {
                echo "[{$this->tag}] groupCall $chatId (try " . ($attempt + 1) . "): " . $e->getMessage() . "\n";
                if ($attempt === 0) {
                    try {
                        $this->peers->refresh();
                    } catch (Throwable $e2) {
                        echo "[{$this->tag}] peer refresh failed: " . $e2->getMessage() . "\n";
                    }
                } else {
                    try {
                        $this->mt->close();
                        $this->mt->connect();
                    } catch (Throwable $e2) {
                        echo "[{$this->tag}] reconnect failed: " . $e2->getMessage() . "\n";
                    }
                }
                usleep(300000);
            }
        }
        return null;
    }

    /**
     * Resolve the chat's group call, opening one when the voice chat is empty.
     * Telegram returns no `call` in getFullChannel until somebody starts the
     * VC, so a plain lookup made /play fail with "no group call answer" in
     * every chat where the bot was expected to start the call itself.
     */
    private function ensureCall(int $chatId): ?TlNode
    {
        $call = $this->groupCall($chatId);
        if ($call !== null) {
            return $call;
        }
        try {
            echo "[{$this->tag}] opening voice chat in $chatId\n";
            $this->mt->invoke((new TlNode('phone.createGroupCall'))
                ->put('peer', $this->peers->inputPeer($chatId))
                ->put('random_id', random_int(1, PHP_INT_MAX)), 30);
            $this->createdCalls[$chatId] = true;
        } catch (Throwable $e) {
            echo "[{$this->tag}] createGroupCall $chatId: " . $e->getMessage() . "\n";
            return null;
        }
        // The new call is visible to getFullChannel a moment later.
        for ($i = 0; $i < 4; $i++) {
            usleep(400000);
            $call = $this->groupCall($chatId);
            if ($call !== null) {
                return $call;
            }
        }
        echo "[{$this->tag}] voice chat created but not resolvable in $chatId\n";
        return null;
    }

    /** @return string SDP answer ("" on failure) */
    public function joinGroupCall(int $chatId, string $offer, bool $video): string
    {
        try {
            $call = $this->ensureCall($chatId);
            if ($call === null) {
                return '';
            }
            echo "[{$this->tag}] joining call $chatId (video=$video, offer=" . strlen($offer) . "B)\n";
            $req = (new TlNode('phone.joinGroupCall'))
                ->put('call', (new TlNode('inputGroupCall'))
                    ->put('id', $call->l('id'))
                    ->put('access_hash', $call->l('access_hash')))
                ->put('join_as', $this->peers->inputPeerSelf())
                ->put('params', (new TlNode('dataJSON'))->put('data', $offer));
            // TL flags are presence-encoded: video_stopped must ONLY appear
            // when video is OFF — putting it at all sets the flag bit.
            if (!$video) {
                $req->put('video_stopped', true);
            }
            try {
                $updates = $this->mt->invoke($req, 45);
            } catch (Throwable $flood) {
                // FLOOD_WAIT_X on join is common after rapid rejoins — wait and retry once
                if (preg_match('/FLOOD_WAIT_(\d+)/', $flood->getMessage(), $m)) {
                    $wait = min((int) $m[1] + 1, 30);
                    echo "[{$this->tag}] joinGroupCall FLOOD_WAIT {$wait}s — retrying\n";
                    sleep($wait);
                    $updates = $this->mt->invoke($req, 45);
                } else {
                    throw $flood;
                }
            }
            $this->groupCalls[$chatId] = $call;
            return $this->findAnswer($updates);
        } catch (Throwable $e) {
            echo "[{$this->tag}] joinGroupCall: " . $e->getMessage() . "\n";
            unset($this->groupCalls[$chatId]);
            return '';
        }
    }

    private static function findAnswer(?TlNode $updates): string
    {
        if ($updates === null) {
            return '';
        }
        $stack = [$updates];
        while ($stack !== []) {
            $n = array_pop($stack);
            if (!$n instanceof TlNode) {
                continue;
            }
            if ($n->name === 'updateGroupCallConnection') {
                return $n->node('params')->str('data');
            }
            foreach (['updates', 'chats', 'users'] as $key) {
                foreach ($n->list($key) as $o) {
                    if ($o instanceof TlNode) {
                        $stack[] = $o;
                    }
                }
            }
        }
        return '';
    }

    public function leaveGroupCall(int $chatId): void
    {
        try {
            $call = $this->groupCalls[$chatId] ?? $this->groupCall($chatId);
            if ($call === null) {
                return;
            }
            $this->mt->invoke((new TlNode('phone.leaveGroupCall'))
                ->put('call', (new TlNode('inputGroupCall'))
                    ->put('id', $call->l('id'))
                    ->put('access_hash', $call->l('access_hash'))), 30);
        } catch (Throwable $e) {
            echo "[{$this->tag}] leaveGroupCall: " . $e->getMessage() . "\n";
        } finally {
            unset($this->groupCalls[$chatId]);
        }
    }

    // ── playback ─────────────────────────────────────────────────────────────

    /**
     * Join (if needed) and start streaming a local file.
     * @return string error ("" on success)
     */
    public function playFile(int $chatId, string $path, bool $video = false, int $pos = 0, float $speed = 1.0): string
    {
        try {
            // A stale ntg connection (previous track/replay) makes create() fail
            // with -202 "Connection cannot be initialized more than once".
            try {
                $this->ntg->stop($chatId);
            } catch (Throwable $ignored) {
            }
            $cam = ($video && $this->videoEnabled) ? ['width' => 1280, 'height' => 720, 'fps' => 30] : null;
            $audio = self::ffmpegAudioCmd($path, $pos, $speed);
            // Sources BEFORE create make the SDP offer carry the video m-line —
            // but ntgcalls 3.x rejects them until the connection exists, so the
            // pre-set is best-effort and re-applied after create.
            $pre = true;
            try {
                $this->ntg->setStreamSources($chatId, $audio, $cam);
            } catch (Throwable $ignored) {
                $pre = false;
            }
            $offer = $this->ntg->create($chatId);
            if (!$pre) {
                $this->ntg->setStreamSources($chatId, $audio, $cam);
            }
            $answer = $this->joinGroupCall($chatId, $offer, $cam !== null);
            if ($answer === '') {
                return 'no group call answer';
            }
            $this->ntg->connect($chatId, $answer);
            if ($cam !== null) {
                $this->startCamera($chatId, $path);
            }
            return '';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /** ntgcalls resolves the binary through wordexp/PATH — a bare name works,
     *  a full path fails in this build (vfork_launcher ENOENT). */
    private static function ffmpegBin(): string
    {
        return getenv('FFMPEG_BIN') ?: 'ffmpeg';
    }

    /** Shell pipeline: ffmpeg decodes the file to raw PCM on stdout (SOURCE_SHELL). */
    private static function ffmpegAudioCmd(string $path, int $pos, float $speed = 1.0): string
    {
        $b = self::ffmpegBin() . ' ';
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $b .= '-reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 ';
        }
        if ($pos > 0) {
            $b .= "-ss $pos ";
        }
        $b .= "-v warning -i \"$path\" ";
        // atempo only accepts 0.5–2.0 per instance — chain it for faster rates.
        $sp = $speed;
        if ($sp > 1.001 || $sp < 0.999) {
            $filters = [];
            while ($sp > 2.0) {
                $filters[] = 'atempo=2.0';
                $sp /= 2.0;
            }
            while ($sp < 0.5) {
                $filters[] = 'atempo=0.5';
                $sp /= 0.5;
            }
            $filters[] = 'atempo=' . rtrim(rtrim(number_format($sp, 4, '.', ''), '0'), '.');
            $b .= '-filter:a "' . implode(',', $filters) . '" ';
        }
        $b .= "-f s16le -ac 2 -ar 96000 pipe:1";
        return $b;
    }

    /** External camera pusher: ffmpeg rawvideo → ntg_send_external_frame at fps. */
    private function startCamera(int $chatId, string $path): void
    {
        $w = 1280;
        $h = 720;
        $fps = 30;
        $cmd = sprintf(
            'ffmpeg -i %s -f rawvideo -pix_fmt yuv420p -s %dx%d -r %d pipe:1 2>/dev/null',
            escapeshellarg($path), $w, $h, $fps
        );
        $proc = popen($cmd, 'rb');
        if (!is_resource($proc)) {
            return;
        }
        stream_set_blocking($proc, false);
        $this->cameras[$chatId] = [
            'cmd' => $cmd, 'w' => $w, 'h' => $h, 'fps' => $fps,
            'start' => microtime(true), 'next' => microtime(true), 'proc' => $proc,
        ];
        echo "[{$this->tag}] camera pusher started chat=$chatId {$w}x{$h}@{$fps}\n";
    }

    public function stopCamera(int $chatId): void
    {
        if (isset($this->cameras[$chatId])) {
            @pclose($this->cameras[$chatId]['proc']);
            unset($this->cameras[$chatId]);
        }
    }

    /** One pusher tick — called from the main loop at high frequency. */
    public function cameraTick(): void
    {
        foreach ($this->cameras as $chatId => $cam) {
            $now = microtime(true);
            if ($now < $cam['next']) {
                continue;
            }
            $frameSize = (int) ($cam['w'] * $cam['h'] * 1.5);
            $frame = '';
            while (strlen($frame) < $frameSize) {
                $chunk = @fread($cam['proc'], $frameSize - strlen($frame));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $frame .= $chunk;
            }
            if (strlen($frame) < $frameSize) {
                echo "[{$this->tag}] camera stream ended chat=$chatId\n";
                $this->stopCamera($chatId);
                continue;
            }
            $ts = (int) (($now - $cam['start']) * 1000);
            $this->ntg->sendExternalFrame($chatId, $frame, $ts, $cam['w'], $cam['h']);
            $cam['next'] = $now + 1.0 / $cam['fps'];
            $this->cameras[$chatId] = $cam;
        }
    }

    public function hasCamera(int $chatId): bool
    {
        return isset($this->cameras[$chatId]);
    }

    public function stop(int $chatId): void
    {
        $this->stopCamera($chatId);
        $this->ntg->stop($chatId);
    }

    public function leave(int $chatId): void
    {
        $this->stop($chatId);
        $call = $this->groupCalls[$chatId] ?? null;
        $created = $this->createdCalls[$chatId] ?? false;
        $this->leaveGroupCall($chatId);
        if ($created && $call !== null) {
            // The assistant opened this voice chat, so /end should close it
            // instead of leaving an empty call behind.
            try {
                $this->mt->invoke((new TlNode('phone.discardGroupCall'))
                    ->put('call', (new TlNode('inputGroupCall'))
                        ->put('id', $call->l('id'))
                        ->put('access_hash', $call->l('access_hash'))), 30);
            } catch (Throwable $e) {
                echo "[{$this->tag}] discardGroupCall: " . $e->getMessage() . "\n";
            }
        }
        unset($this->createdCalls[$chatId]);
    }
}
