<?php

declare(strict_types=1);

/**
 * YumiMusic application — exact Nandani-parity UI/UX engine:
 * Locales templates (Nandani en.yml), signature stylish() outputs,
 * photo now-playing cards with progress-bar button rows, help
 * sections, language picker, autoplay panel, stop-confirm — all
 * driven through raw MTProto on the single cooperative process.
 */
final class App
{
    public MtProto $bot;
    public Peers $botPeers;
    public ?Voice $voice = null;
    public Iota $iota;
    public int $ownerId;
    private string $downloads;
    private Locales $loc;
    private Db $db;
    private string $botName;
    private string $ntgVer = '3.0.4';

    /** @var array<int, Room> */
    public array $rooms = [];

    /** @var array<int, array<int, array>> chatId → last search results */
    private array $searchCache = [];
    private int $lastPing = 0;
    private int $lastTick = 0;
    private int $lastCardEdit = 0;
    private int $started;

    public function __construct(
        public readonly string $token,
        public readonly int $apiId,
        public readonly string $apiHash,
        string $iotaUrl,
        string $iotaKey,
        ?string $assistantSession,
        private readonly bool $vcEnabled,
    ) {
        $this->downloads = dirname(__DIR__) . '/data/downloads';
        if (!is_dir($this->downloads)) {
            mkdir($this->downloads, 0777, true);
        }
        $this->started = time();
        $this->iota = new Iota($iotaUrl, $iotaKey);
        $this->ownerId = (int) (getenv('OWNER_ID') ?: 0);
        $this->botName = (string) (getenv('BOT_NAME') ?: '𝐘‌υϻɪ 𝘅 ϻ‌υsɪᴄ');

        $this->loc = Locales::load(dirname(__DIR__) . '/locales');
        $this->loc->setBotName($this->botName);
        $this->db = new Db(dirname(__DIR__) . '/data/settings.json');
        Buttons::setBotUsername((string) (getenv('BOT_USERNAME') ?: 'Yumi_MusicBot'));

        // ── bot client (raw MTProto, token auth) ──
        $this->bot = new MtProto($apiId, $apiHash, 2);
        $this->bot->tag = 'yumi-bot';
        $this->botPeers = new Peers($this->bot, 'yumi-bot');
        $this->botPeers->supportsDialogs = false; // bots can't getDialogs — updates only
        $this->bot->onUpdate = fn(TlNode $u) => $this->onBotUpdate($u);

        // ── assistant client (session import from Nandani env) ──
        if ($vcEnabled && $assistantSession !== null) {
            $mt = new MtProto($apiId, $apiHash, 2);
            $mt->tag = 'yumi-assistant';
            if ($mt->importSession($assistantSession)) {
                $this->voice = new Voice($mt, 'yumi-voice', true);
                $mt->onUpdate = function (TlNode $u): void {
                    $this->voice->peers()->learnFrom($u);
                };
            } else {
                echo "[yumi-assistant] session import failed — VC disabled\n";
            }
        }
    }

    private function lang(int $chatId): string
    {
        return $this->db->lang($chatId);
    }

    private function t(int $chatId, string $key, array $args = []): string
    {
        return $this->loc->f($this->lang($chatId), $key, ...$this->flat($args));
    }

    /** @param array<string,mixed> $args */
    private function flat(array $args): array
    {
        $kv = [];
        foreach ($args as $k => $v) {
            $kv[] = $k;
            $kv[] = (string) $v;
        }
        return $kv;
    }

    // ── lifecycle ────────────────────────────────────────────────────────────

    public function start(): void
    {
        $sessFile = dirname(__DIR__) . '/data/bot.session';
        $fresh = true;
        if (is_file($sessFile) && $this->bot->importSession((string) file_get_contents($sessFile))) {
            $fresh = false;
        }
        $this->bot->connect();
        if ($fresh) {
            $this->bot->handshake();
        }
        $this->authorizeBot();

        if (isset($this->voice)) {
            $amt = $this->voice->mt;
            $amt->connect();
            $this->whoAmIAssistant();
        }

        $this->botPeers->refresh();
        $this->setCommands();
        echo "[yumi] ready — owner={$this->ownerId} vc=" . ($this->vcEnabled ? 'on' : 'off') . "\n";
    }

    private function authorizeBot(): void
    {
        $sessFile = dirname(__DIR__) . '/data/bot.session';
        for ($i = 0; $i < 5; $i++) {
            try {
                $res = $this->bot->invoke((new TlNode('auth.importBotAuthorization'))
                    ->put('flags', 0)
                    ->put('api_id', $this->apiId)
                    ->put('api_hash', $this->apiHash)
                    ->put('bot_auth_token', $this->token), 30);
                if ($res->name === 'auth.authorization') {
                    @file_put_contents($sessFile, $this->bot->exportSession());
                    $me = $this->bot->invoke((new TlNode('users.getFullUser'))
                        ->put('id', new TlNode('inputUserSelf')), 20);
                    foreach ($me->list('users') as $u) {
                        if ($u instanceof TlNode && $u->name === 'user') {
                            echo "[yumi-bot] logged in as {$u->str('first_name')} @{$u->str('username')} ({$u->l('id')})\n";
                            if ($u->str('username') !== '') {
                                Buttons::setBotUsername($u->str('username'));
                            }
                        }
                    }
                    return;
                }
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (preg_match('/MIGRATE_(\d)/', $msg, $mm)) {
                    $this->bot->migrateTo((int) $mm[1]);
                    continue;
                }
                if (str_contains($msg, '404') || !$this->bot->connected()) {
                    echo "[yumi-bot] auth key rejected, re-handshaking…\n";
                    $this->bot->connect();
                    $this->bot->handshake();
                    continue;
                }
                throw $e;
            }
        }
        throw new RuntimeException('bot authorization failed');
    }

    private function whoAmIAssistant(): void
    {
        $amt = $this->voice->mt;
        try {
            $me = $amt->invoke((new TlNode('users.getFullUser'))
                ->put('id', new TlNode('inputUserSelf')), 20);
            foreach ($me->list('users') as $u) {
                if ($u instanceof TlNode && $u->name === 'user') {
                    echo "[yumi-assistant] resumed as {$u->str('first_name')} @{$u->str('username')} ({$u->l('id')})\n";
                }
            }
            $this->voice->peers()->refresh();
        } catch (Throwable $e) {
            echo "[yumi-assistant] whoami failed: " . $e->getMessage() . "\n";
        }
    }

    private function setCommands(): void
    {
        try {
            $names = [
                ['play', 'ᴘʟᴧʏ ᴧ sσɴɢ ɪɴ ᴠᴄ'], ['vplay', 'sᴛʀᴇᴧᴍ ᴠɪᴅᴇσ ɪɴ ᴠᴄ'],
                ['song', 'sєηᴅ ᴧᴜᴅɪσ ғɪʟє'], ['video', 'sєηᴅ ᴠɪᴅєσ ғɪʟє'],
                ['pause', 'ᴘᴧᴜsє ᴘʟᴧʏʙᴧᴄᴋ'], ['resume', 'ʀєsᴜᴍє ᴘʟᴧʏʙᴧᴄᴋ'],
                ['skip', 'sᴋɪᴘ ᴄυꝛꝛєηᴛ ᴛꝛᴧᴄᴋ'], ['stop', 'sᴛσᴘ ᴧηᴅ ᴄʟєᴧꝛ'],
                ['end', 'sᴛσᴘ ᴧηᴅ ᴄʟєᴧꝛ'], ['queue', 'sʜσᴡ ǫᴜєᴜє'],
                ['replay', 'ʀєsᴛᴧʀᴛ ᴄυꝛꝛєηᴛ ᴛʀᴧᴄᴋ'],
                ['loop', 'ʟσσᴘ ᴄυꝛꝛєηᴛ [1-10]'], ['shuffle', 'sʜᴜғғʟє ǫᴜєᴜє'],
                ['autoplay', 'ᴧᴜᴛσᴘʟᴧʏ ᴘᴧηєʟ'], ['seek', 'sєєᴋ sєᴄσηᴅs'],
                ['position', 'ᴄυꝛꝛєηᴛ ᴛɪᴍєsᴛᴧᴍᴘ'], ['leave', 'ʟєᴧᴠє ᴠσɪᴄє ᴄʜᴧᴛ'],
                ['lang', 'sєʟєᴄᴛ ʟᴧηɢᴜᴧɢє'], ['ping', 'ʟᴧᴛєηᴄʏ ᴧηᴅ sʏsᴛєᴍ'],
            ];
            $vectors = [];
            foreach ($names as [$cmd, $desc]) {
                $vectors[] = (new TlNode('botCommand'))->put('command', $cmd)->put('description', Util::stylish($desc));
            }
            $this->bot->invoke((new TlNode('bots.setBotCommands'))
                ->put('scope', new TlNode('botCommandScopeDefault'))
                ->put('lang_code', '')
                ->put('commands', $vectors), 20);
        } catch (Throwable $e) {
            echo "[yumi] setCommands failed: " . $e->getMessage() . "\n";
        }
    }

    // ── bot updates ──────────────────────────────────────────────────────────

    private function onBotUpdate(TlNode $u): void
    {
        try {
            $this->botPeers->learnFrom($u);
            foreach ($u->list('updates') as $sub) {
                if ($sub instanceof TlNode) {
                    $this->handleBotUpdate($sub, $u);
                }
            }
            $this->handleBotUpdate($u, null);
        } catch (Throwable $e) {
            echo "[yumi] update handler failed: " . $e->getMessage() . "\n";
        }
    }

    private function handleBotUpdate(TlNode $u, ?TlNode $container): void
    {
        switch ($u->name) {
            case 'updateNewMessage':
            case 'updateNewChannelMessage':
                $msg = $u->node('message');
                if ($msg === null) {
                    return;
                }
                if ($msg->name === 'messageService') {
                    $this->onServiceMessage($msg);
                    return;
                }
                $this->onBotMessage($msg);
                return;
            case 'updateBotCallbackQuery':
                $this->onCallback($u);
                return;
        }
    }

    private function peerChatId(TlNode $peer): int
    {
        return match ($peer->name) {
            'peerUser' => $peer->l('user_id'),
            'peerChat' => -$peer->l('chat_id'),
            'peerChannel' => -1000000000000 - $peer->l('channel_id'),
            default => 0,
        };
    }

    private function senderId(TlNode $msg): int
    {
        $from = $msg->node('from_id');
        if ($from !== null && $from->name === 'peerUser') {
            return $from->l('user_id');
        }
        $peer = $msg->node('peer_id');
        return $peer !== null ? $this->peerChatId($peer) : 0;
    }

    private function onServiceMessage(TlNode $msg): void
    {
        $peer = $msg->node('peer_id');
        if ($peer === null) {
            return;
        }
        $chatId = $this->peerChatId($peer);
        $action = $msg->node('action');
        if ($action === null || $action->name !== 'messageActionGroupCall') {
            return;
        }
        if ($action->has('duration')) {
            $this->send($chatId, $this->t($chatId, 'voicechat_ended', [
                'duration' => Util::formatTime($action->i('duration')),
            ]));
            $this->dropRoom($chatId);
        } else {
            $this->send($chatId, $this->t($chatId, 'voicechat_started'));
        }
    }

    private function onBotMessage(TlNode $msg): void
    {
        $text = $msg->str('message');
        if ($text === '' || $text === null) {
            return;
        }
        $peer = $msg->node('peer_id');
        $chatId = $this->peerChatId($peer);
        $sender = $this->senderId($msg);
        $cmd = strtolower(explode('@', explode(' ', trim($text))[0], 2)[0]);
        $arg = trim(preg_replace('/^\/[a-z0-9_]+(@\w+)?\s*/i', '', $text));
        Locales::setCurrentCommand($cmd);
        echo "[yumi] chat=$chatId user=$sender cmd=$cmd\n";
        try {
            $this->dispatch($chatId, $sender, $cmd, $arg, $msg);
        } catch (Throwable $e) {
            echo "[yumi] cmd $cmd failed: " . $e->getMessage() . "\n";
            $this->send($chatId, $this->t($chatId, 'generic_error', [
                'error' => Util::escapeHtml($e->getMessage()),
            ]), $msg->i('id'));
        }
        Locales::setCurrentCommand(null);
    }

    private function isAdmin(int $chatId, int $sender): bool
    {
        if ($sender === $this->ownerId) {
            return true;
        }
        if ($chatId > 0) {
            return true; // DM
        }
        try {
            $peer = $this->botPeers->inputPeer($chatId);
            if ($peer->name === 'inputPeerChat') {
                $full = $this->bot->invoke((new TlNode('messages.getFullChat'))
                    ->put('chat_id', -$chatId), 20);
                foreach ($full->list('full_chat')->list('participants') ?? [] as $p) {
                    if ($p instanceof TlNode && $p->l('user_id') === $sender) {
                        return in_array($p->name, ['chatParticipantAdmin', 'chatParticipantCreator'], true);
                    }
                }
                return false;
            }
            $res = $this->bot->invoke((new TlNode('channels.getParticipant'))
                ->put('channel', $peer)
                ->put('participant', (new TlNode('inputPeerUser'))->put('user_id', $sender)->put('access_hash', 0)), 20);
            $role = $res->node('participant')->name;
            return in_array($role, ['channelParticipantAdmin', 'channelParticipantCreator'], true);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function mention(int $sender): string
    {
        return $this->botPeers->userMention($sender);
    }

    // ── commands ─────────────────────────────────────────────────────────────

    private function dispatch(int $chatId, int $sender, string $cmd, string $arg, TlNode $msg): void
    {
        switch ($cmd) {
            case '/start':
                if ($chatId > 0) {
                    $this->send($chatId, $this->t($chatId, 'start_private', [
                        'user' => $this->mention($sender),
                    ]), 0, Buttons::startMarkup($this->loc, $this->lang($chatId)));
                } else {
                    $this->send($chatId, $this->t($chatId, 'start_group'), 0,
                        Buttons::addMeMarkup($this->loc, $this->lang($chatId)));
                }
                return;
            case '/help':
                if ($chatId > 0) {
                    $this->send($chatId, $this->t($chatId, 'help_main'), 0,
                        Buttons::helpKeyboard($this->loc, $this->lang($chatId)));
                } else {
                    $this->send($chatId, $this->t($chatId, 'help_private_only'), 0,
                        Buttons::groupHelpKeyboard($this->loc, $this->lang($chatId)));
                }
                return;
            case '/ping':
                $this->send($chatId, $this->t($chatId, 'ping_start'));
                $t0 = microtime(true);
                $this->bot->invoke((new TlNode('ping'))->put('ping_id', 1), 10);
                $ms = (int) ((microtime(true) - $t0) * 1000);
                $this->send($chatId, $this->t($chatId, 'ping_result', [
                    'latency' => $ms . 'ᴍs',
                    'uptime' => Util::formatUptime(time() - $this->started),
                    'ram' => Util::ramInfo(),
                    'cpu' => Util::cpuInfo(),
                    'disk' => Util::diskInfo(),
                    'ptg' => 'php-ffi',
                ]));
                return;
            case '/play':
            case '/song':
                $this->cmdPlay($chatId, $sender, $arg, false, $msg->i('id'), $msg->node('reply_to')?->l('reply_to_msg_id') ?? 0);
                return;
            case '/vplay':
                $this->cmdPlay($chatId, $sender, $arg, true, $msg->i('id'), $msg->node('reply_to')?->l('reply_to_msg_id') ?? 0);
                return;
            case '/video':
                $this->cmdVideoFile($chatId, $sender, $arg);
                return;
            case '/skip':
                if (!$this->guardVc($chatId)) {
                    return;
                }
                $this->cmdSkip($chatId, $sender, $arg);
                return;
            case '/pause':
                if (!$this->guardVc($chatId)) {
                    return;
                }
                $r = $this->rooms[$chatId] ?? null;
                if ($r === null || !$r->playing) {
                    $this->send($chatId, $this->t($chatId, 'play_failed', ['error' => 'ɴᴏ ᴀᴄᴛɪᴠᴇ ᴘʟᴀʏʙᴀᴄᴋ']));
                    return;
                }
                if ($r->paused) {
                    $this->send($chatId, $this->t($chatId, 'pause_already'));
                    return;
                }
                $r->paused = true;
                $this->voice?->ntg->pause($chatId);
                $this->send($chatId, $this->t($chatId, 'pause_success', [
                    'title' => Util::escapeHtml($r->track['title'] ?? '-'),
                    'position' => Util::formatTime($r->position),
                    'duration' => Util::formatTime((int) ($r->track['duration'] ?? 0)),
                    'user' => $this->mention($sender),
                ]), 0, $this->playMarkup($r));
                return;
            case '/resume':
                if (!$this->guardVc($chatId)) {
                    return;
                }
                $r = $this->rooms[$chatId] ?? null;
                if ($r === null || !$r->playing) {
                    $this->send($chatId, $this->t($chatId, 'play_failed', ['error' => 'ɴᴏ ᴀᴄᴛɪᴠᴇ ᴘʟᴀʏʙᴀᴄᴋ']));
                    return;
                }
                if (!$r->paused) {
                    $this->send($chatId, $this->t($chatId, 'resume_already_playing'), 0,
                        Buttons::stopConfirmMarkup($this->loc, $this->lang($chatId), $r));
                    return;
                }
                $r->paused = false;
                $this->voice?->ntg->resume($chatId);
                $this->send($chatId, $this->t($chatId, 'resume_success', [
                    'title' => Util::escapeHtml($r->track['title'] ?? '-'),
                    'position' => Util::formatTime($r->position),
                    'duration' => Util::formatTime((int) ($r->track['duration'] ?? 0)),
                    'user' => $this->mention($sender),
                ]), 0, $this->playMarkup($r));
                return;
            case '/mute':
            case '/unmute':
                // volume is server-side in ntgcalls — mirror to pause/resume
                if ($cmd === '/mute') {
                    $this->dispatch($chatId, $sender, '/pause', $arg, $msg);
                } else {
                    $this->dispatch($chatId, $sender, '/resume', $arg, $msg);
                }
                return;
            case '/end':
            case '/stop':
                if (!$this->guardVc($chatId)) {
                    return;
                }
                $r = $this->rooms[$chatId] ?? null;
                if ($r === null || !$r->playing) {
                    $this->dropRoom($chatId);
                    $this->send($chatId, $this->t($chatId, 'stopped', ['user' => $this->mention($sender)]));
                    return;
                }
                // Nandani confirm card: paused → resume-or-stop
                $this->send($chatId, $this->t($chatId, $r->paused ? 'stop_confirm_paused' : 'stop_confirm_muted'), 0,
                    Buttons::stopConfirmMarkup($this->loc, $this->lang($chatId), $r));
                return;
            case '/leave':
                if (!$this->guardVc($chatId)) {
                    return;
                }
                $this->dropRoom($chatId);
                $this->send($chatId, $this->t($chatId, 'stopped', ['user' => $this->mention($sender)]));
                return;
            case '/queue':
                $this->cmdQueue($chatId);
                return;
            case '/clear':
                $r = $this->rooms[$chatId] ?? null;
                if ($r !== null) {
                    $r->queue = [];
                    $r->loop = false;
                    $r->loopLeft = 0;
                }
                $this->send($chatId, $this->t($chatId, 'stopped', ['user' => $this->mention($sender)]));
                return;
            case '/loop':
                if (!$this->guardVc($chatId)) {
                    return;
                }
                $r = $this->rooms[$chatId] ?? null;
                if ($r === null || $r->track === null) {
                    $this->send($chatId, $this->t($chatId, 'seek_no_active'));
                    return;
                }
                $q = strtolower(trim($arg));
                if ($q === 'enable' || $q === 'on') {
                    $r->loop = true;
                    $r->loopLeft = 1;
                    $this->send($chatId, $this->t($chatId, 'loop_enabled'));
                    return;
                }
                if ($q === 'disable' || $q === 'off') {
                    $r->loop = false;
                    $r->loopLeft = 0;
                    $this->send($chatId, $this->t($chatId, 'loop_disabled', ['user' => $this->mention($sender)]));
                    return;
                }
                $n = ctype_digit($q) ? (int) $q : 0;
                if ($n > 10) {
                    $this->send($chatId, $this->t($chatId, 'loop_invalid'));
                    return;
                }
                if ($n > 0) {
                    $r->loop = true;
                    $r->loopLeft = $n;
                    $this->send($chatId, $this->t($chatId, 'loop_set', [
                        'count' => (string) $n, 'user' => $this->mention($sender),
                    ]));
                } else {
                    $r->loop = false;
                    $r->loopLeft = 0;
                    $this->send($chatId, $this->t($chatId, 'loop_disabled', ['user' => $this->mention($sender)]));
                }
                return;
            case '/shuffle':
                $r = $this->rooms[$chatId] ?? null;
                if ($r === null || $r->queue === []) {
                    $this->send($chatId, $this->t($chatId, 'queue_empty'));
                    return;
                }
                shuffle($r->queue);
                $this->send($chatId, $this->t($chatId, 'shuffle_updated', [
                    'state' => $this->t($chatId, 'enabled'),
                    'user' => $this->mention($sender),
                ]));
                return;
            case '/autoplay':
                if (!$this->guardVc($chatId)) {
                    return;
                }
                $r = $this->rooms[$chatId] ?? null;
                if ($r === null || $r->track === null) {
                    $this->send($chatId, $this->t($chatId, 'autoplay_no_active'));
                    return;
                }
                $this->send($chatId, $this->t($chatId, 'autoplay_menu'), 0,
                    Buttons::autoplayPanel($this->loc, $this->lang($chatId), $chatId));
                return;
            case '/seek':
            case '/seekback':
                if (!$this->guardVc($chatId)) {
                    return;
                }
                $this->cmdSeek($chatId, $sender, $arg, $cmd === '/seekback');
                return;
            case '/jump':
                if (!$this->guardVc($chatId)) {
                    return;
                }
                $this->cmdJump($chatId, $sender, $arg);
                return;
            case '/replay':
                if (!$this->guardVc($chatId)) {
                    return;
                }
                $r = $this->rooms[$chatId] ?? null;
                if ($r === null || $r->track === null || $r->path === '') {
                    $this->send($chatId, $this->t($chatId, 'seek_no_active'));
                    return;
                }
                $err = $this->voice?->playFile($chatId, $r->path, (bool) ($r->track['video'] ?? false));
                if ($err !== '') {
                    $this->send($chatId, $this->t($chatId, 'play_failed', ['error' => Util::escapeHtml($err)]));
                    return;
                }
                $r->position = 0;
                $r->endStreak = 0;
                $r->paused = false;
                $this->send($chatId, $this->t($chatId, 'cb_replaying'), 0, $this->playMarkup($r));
                return;
            case '/position':
                $r = $this->rooms[$chatId] ?? null;
                if ($r === null || $r->track === null) {
                    $this->send($chatId, $this->t($chatId, 'seek_no_active'));
                    return;
                }
                $this->send($chatId, $this->t($chatId, 'position_now', [
                    'title' => Util::escapeHtml($r->track['title'] ?? '-'),
                    'position' => Util::formatTime($r->position),
                    'duration' => Util::formatTime((int) ($r->track['duration'] ?? 0)),
                    'speed' => '1.0',
                ]));
                return;
            case '/lang':
            case '/language':
                $this->send($chatId, $this->t($chatId, 'lang_select'), 0,
                    Buttons::langKeyboard($this->loc, $this->lang($chatId)));
                return;
            case '/stats':
                if ($sender !== $this->ownerId && $chatId > 0) {
                    return;
                }
                $this->send($chatId, $this->statsText($chatId), 0,
                    Buttons::repoMarkup($this->loc, $this->lang($chatId), $this->ownerId));
                return;
        }
    }

    private function guardVc(int $chatId): bool
    {
        if (!$this->vcEnabled || $this->voice === null) {
            $this->send($chatId, $this->t($chatId, 'play_unable'));
            return false;
        }
        return true;
    }

    // ── play pipeline ────────────────────────────────────────────────────────

    private function cmdPlay(int $chatId, int $sender, string $arg, bool $vcVideo, int $replyTo = 0, int $replied = 0): void
    {
        if ($arg === '') {
            // Nandani parity: no arg + no reply → usage template; empty query
            // with reply → searching then no_song_found (resolveQuery("") = []).
            if ($replied === 0) {
                $this->send($chatId, $this->t($chatId, $vcVideo ? 'vplay_usage' : 'no_song_query'), $replyTo);
                return;
            }
            $this->send($chatId, $this->t($chatId, 'searching'));
            $this->send($chatId, $this->t($chatId, 'no_song_found'));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'searching_query', ['query' => Util::escapeHtml($arg)]));
        $results = $this->iota->search($arg, 5);
        if ($results === []) {
            $this->send($chatId, $this->t($chatId, 'play_download_failed'));
            return;
        }
        if (!isset($this->rooms[$chatId])) {
            $this->rooms[$chatId] = new Room($chatId);
        }
        $r = $this->rooms[$chatId];
        $this->searchCache[$chatId] = $results;

        if ($r->track === null && $r->queue === []) {
            $this->startTrack($chatId, $r, $results[0], $sender, $vcVideo);
        } else {
            $t = $results[0];
            if ($vcVideo) {
                $t['video'] = true;
            }
            $t['req'] = $sender;
            $r->queue[] = $t;
            $this->send($chatId, $this->t($chatId, 'play_added_to_queue_single', [
                'index' => (string) count($r->queue),
                'title' => Util::escapeHtml($t['title']),
                'duration' => Util::formatTime((int) $t['duration']),
                'url' => $this->trackUrl($t),
                'by' => $this->mention($sender),
            ]), 0, Buttons::playMarkup($this->loc, $this->lang($chatId), $r, true));
        }
    }

    private function trackUrl(array $t): string
    {
        $id = $t['media_id'] ?? '';
        if (preg_match('#^yt:(.+)$#', $id, $mm)) {
            return 'https://youtu.be/' . $mm[1];
        }
        return 'https://youtube.com';
    }

    private function cmdVideoFile(int $chatId, int $sender, string $arg): void
    {
        if ($arg === '') {
            Locales::setCurrentCommand('/video');
            $this->send($chatId, $this->t($chatId, 'cplay_usage'));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'searching_query', ['query' => Util::escapeHtml($arg)]));
        $results = $this->iota->search($arg, 3);
        $pick = null;
        foreach ($results as $cand) {
            if ((int) $cand['duration'] > 0 && (int) $cand['duration'] <= 600) {
                $pick = $cand;
                break;
            }
        }
        if ($pick === null) {
            $this->send($chatId, $this->t($chatId, 'play_single_track_too_long', [
                'limit_mins' => '10', 'title' => Util::escapeHtml($results[0]['title'] ?? '-'),
            ]));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'play_downloading_song', ['title' => Util::escapeHtml($pick['title'])]));
        $path = $this->resolveTrack($pick, true);
        if ($path === null) {
            $this->send($chatId, $this->t($chatId, 'play_download_failed'));
            return;
        }
        $err = $this->sendDocument($chatId, $path, $pick, true);
        if ($err !== '') {
            echo "[yumi] sendVideo: $err\n";
        }
    }

    private function cmdSkip(int $chatId, int $sender, string $arg): void
    {
        $r = $this->rooms[$chatId] ?? null;
        if ($r === null || !$r->playing) {
            $this->send($chatId, $this->t($chatId, 'seek_no_active'));
            return;
        }
        if ($arg !== '' && ctype_digit($arg)) {
            $n = (int) $arg;
            $avail = count($r->queue);
            if ($n === 0 || $n > $avail) {
                $this->send($chatId, $this->t($chatId, 'skip_count_exceeds_queue', [
                    'requested' => (string) $n, 'available' => (string) $avail,
                ]));
                return;
            }
            for ($i = 0; $i < $n; $i++) {
                array_shift($r->queue);
            }
        }
        $hadNext = $r->queue !== [];
        $this->advanceRoom($chatId);
        if (!$hadNext && ($this->rooms[$chatId] ?? null) === null) {
            $this->send($chatId, $this->t($chatId, 'skip_stopped', ['user' => $this->mention($sender)]));
        }
    }

    private function cmdSeek(int $chatId, int $sender, string $arg, bool $back): void
    {
        $r = $this->rooms[$chatId] ?? null;
        if ($r === null || $r->track === null) {
            $this->send($chatId, $this->t($chatId, 'seek_no_active'));
            return;
        }
        if (!preg_match('/^\d+$/', $arg)) {
            Locales::setCurrentCommand($back ? '/seekback' : '/seek');
            $this->send($chatId, $this->t($chatId, 'seek_usage'));
            return;
        }
        $secs = (int) $arg;
        $dur = (int) ($r->track['duration'] ?? 0);
        // Nandani semantics: /seek N jumps to absolute second N; /seekback N rewinds N.
        $target = $back ? max(0, $r->position - $secs) : min($dur > 0 ? $dur : $secs, $secs);
        if (!$back && $dur > 0 && $secs > $dur - 5) {
            $this->send($chatId, $this->t($chatId, 'seek_too_close_end', ['seconds' => (string) $secs]));
            return;
        }
        $err = $this->seekTo($chatId, $r, $target);
        if ($err !== '') {
            $this->send($chatId, $this->t($chatId, 'seek_failed', [
                'direction' => $back ? 'ʙᴧᴄᴋᴡᴧꝛᴅ' : 'ғσꝛᴡᴧꝛᴅ',
                'seconds' => (string) $secs, 'error' => Util::escapeHtml($err),
            ]));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'seek_success', [
            'emoji' => $back ? '⏪' : '⏩',
            'direction' => $back ? 'ʙᴧᴄᴋᴡᴧꝛᴅ' : 'ғσꝛᴡᴧꝛᴅ',
            'position' => Util::formatTime($r->position),
            'duration' => Util::formatTime($dur),
        ]));
    }

    private function cmdJump(int $chatId, int $sender, string $arg): void
    {
        $r = $this->rooms[$chatId] ?? null;
        if ($r === null || $r->track === null) {
            $this->send($chatId, $this->t($chatId, 'jump_no_active'));
            return;
        }
        if (!preg_match('/^\d+$/', $arg)) {
            Locales::setCurrentCommand('/jump');
            $this->send($chatId, $this->t($chatId, 'jump_usage'));
            return;
        }
        $target = (int) $arg;
        $dur = (int) ($r->track['duration'] ?? 0);
        if ($dur > 0 && $target > $dur - 5) {
            $this->send($chatId, $this->t($chatId, 'jump_too_close_end', ['position' => Util::formatTime($target)]));
            return;
        }
        $err = $this->seekTo($chatId, $r, $target);
        if ($err !== '') {
            $this->send($chatId, $this->t($chatId, 'jump_failed', [
                'position' => Util::formatTime($target), 'error' => Util::escapeHtml($err),
            ]));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'jump_success', [
            'position' => Util::formatTime($target), 'duration' => Util::formatTime($dur),
        ]));
    }

    private function seekTo(int $chatId, Room $r, int $target): string
    {
        $this->voice?->ntg->stop($chatId);
        $err = $this->voice?->playFile($chatId, $r->path, (bool) ($r->track['video'] ?? false), $target);
        if ($err === '') {
            $r->position = $target;
            $r->endStreak = 0;
        }
        return $err;
    }

    private function cmdQueue(int $chatId): void
    {
        $r = $this->rooms[$chatId] ?? null;
        if ($r === null || ($r->track === null && $r->queue === [])) {
            $this->send($chatId, $this->t($chatId, 'queue_empty'));
            return;
        }
        $lang = $this->lang($chatId);
        $count = count($r->queue) + ($r->track !== null ? 1 : 0);
        $body = $this->loc->f($lang, 'queue_header', 'count', (string) $count) . "\n";
        if ($r->track !== null) {
            $body .= $this->loc->f($lang, 'queue_now_playing_item',
                'url', $this->trackUrl($r->track),
                'title', Util::escapeHtml(Util::shortTitle((string) $r->track['title'], 30)),
                'duration', Util::formatTime((int) ($r->track['duration'] ?? 0)),
                'by', $this->requesterMention($r->track),
            ) . "\n";
        }
        $i = 1;
        foreach ($r->queue as $t) {
            if ($i > 30) {
                $body .= $this->loc->f($lang, 'queue_more', 'remaining', (string) (count($r->queue) - 30));
                break;
            }
            $body .= $this->loc->f($lang, 'queue_item',
                'index', (string) $i++,
                'url', $this->trackUrl($t),
                'title', Util::escapeHtml(Util::shortTitle((string) $t['title'], 30)),
                'duration', Util::formatTime((int) ($t['duration'] ?? 0)),
                'by', $this->requesterMention($t),
            ) . "\n";
        }
        if ($r->queue === []) {
            $body .= "\n" . $this->loc->get($lang, 'queue_empty_tail');
        }
        $this->send($chatId, $body, 0, $this->playMarkup($r, true));
    }

    /** Requester mention of a track (falls back to the bot). */
    private function requesterMention(array $t): string
    {
        $req = (int) ($t['req'] ?? 0);
        return $req > 0 ? $this->mention($req) : $this->botName();
    }

    private function botName(): string
    {
        try {
            $n = $this->botPeers->userName(8713141466);
            return $n !== '' ? Util::escapeHtml($n) : '𝐘‌υϻɪ 𝘅 ϻ‌υsɪᴄ';
        } catch (Throwable) {
            return '𝐘‌υϻɪ 𝘅 ϻ‌υsɪᴄ';
        }
    }

    private function trackLine(array $t, int $position): string
    {
        $dur = (int) ($t['duration'] ?? 0);
        $time = $dur > 0 ? ' <code>[' . Util::formatTime($position) . ' / ' . Util::formatTime($dur) . ']</code>' : '';
        $video = ($t['video'] ?? false) ? ' 🎬' : '';
        return '<a href="' . $this->trackUrl($t) . '">' . Util::escapeHtml((string) $t['title']) . '</a>' . $time . $video;
    }

    private function statsText(int $chatId): string
    {
        try {
            $ver = $this->bot->invoke((new TlNode('help.getAppConfig')), 15);
            $apiVer = 'layer 230';
        } catch (Throwable) {
            $apiVer = 'layer 230';
        }
        return $this->t($chatId, 'stats_overview', [
            'os' => 'Linux', 'arch' => php_uname('m'),
            'cores' => (string) max(1, (int) shell_exec('nproc') ?: 1),
            'threads' => (string) max(1, (int) shell_exec('nproc') ?: 1),
            'alloc' => round(memory_get_usage(true) / 1048576, 1) . ' MB',
            'sys' => Util::ramInfo(),
            'gc_count' => (string) gc_collect_cycles(),
            'gc_rate' => 'ok',
            'gc_emoji' => '🟢', 'cpu_emoji' => '🟢',
            'cpu' => Util::cpuInfo(),
            'ram_emoji' => '🟢', 'ram' => Util::ramGiB(),
            'disk' => Util::diskGiB(),
            'db_data' => Util::humanSize(is_file(dirname(__DIR__) . '/data/settings.json')
                ? (int) filesize(dirname(__DIR__) . '/data/settings.json') : 0),
            'db_collections' => '2', 'db_objects' => (string) $this->db->servedChats(),
            'served_chats' => (string) $this->db->servedChats(),
            'served_users' => '—',
            'core_ver' => 'php ' . PHP_VERSION,
            'api_ver' => $apiVer,
            'vc_ver' => 'ntgcalls ' . $this->ntgVer,
        ]);
    }

    private function startTrack(int $chatId, Room $r, array $t, int $sender, bool $vcVideo = false): void
    {
        $t['req'] = $t['req'] ?? $sender;
        $r->track = $t;
        $r->position = 0;
        $r->endStreak = 0;
        $r->playing = false;
        $this->send($chatId, $this->t($chatId, 'stream_downloading_next'));
        $path = $this->resolveTrack($t, $vcVideo);
        if ($path === null) {
            $r->track = null;
            $this->send($chatId, $this->t($chatId, 'play_download_failed'));
            return;
        }
        $r->path = $path;
        $err = $this->voice?->playFile($chatId, $path, $vcVideo);
        if ($err !== '') {
            $r->track = null;
            echo "[yumi] playFile failed: $err\n";
            $this->send($chatId, $this->t($chatId, 'play_unable'));
            return;
        }
        $r->playing = true;
        $r->statusMsg = $this->nowPlayingCard($chatId, $r, $sender);
        echo "[yumi] card msg id: {$r->statusMsg}\n";
    }

    /** Nandani photo now-playing card (falls back to a text card). */
    private function nowPlayingCard(int $chatId, Room $r, int $sender): int
    {
        $t = $r->track ?? [];
        $lang = $this->lang($chatId);
        $caption = $this->loc->f($lang, 'stream_now_playing',
            'title', Util::escapeHtml((string) ($t['title'] ?? '-')),
            'duration', Util::formatTime((int) ($t['duration'] ?? 0)),
            'url', $this->trackUrl($t),
            'by', $this->botPeers->userMention($sender)
        );
        $thumb = (string) ($t['thumbnail'] ?? '');
        if ($thumb !== '' && $this->db->thumbnailsDisabled($chatId) === false) {
            try {
                [$plain, $entities] = MsgEntities::parse($caption);
                $req = (new TlNode('messages.sendMedia'))
                    ->put('peer', $this->botPeers->inputPeer($chatId))
                    ->put('media', (new TlNode('inputMediaPhotoExternal'))
                        ->put('flags', 0)
                        ->put('url', $thumb))
                    ->put('message', $plain)
                    ->put('random_id', random_int(1, PHP_INT_MAX));
                if ($entities !== []) {
                    $req->put('entities', $entities);
                }
                $req->put('reply_markup', $this->playMarkup($r));
                $res = $this->bot->invoke($req, 30);
                $this->botPeers->learnFrom($res);
                return $this->updatesMsgId($res);
            } catch (Throwable $e) {
                echo "[yumi] photo card failed: " . $e->getMessage() . " — text fallback\n";
            }
        }
        return $this->send($chatId, $caption, 0, $this->playMarkup($r));
    }

    private function resolveTrack(array $t, bool $video): ?string
    {
        $id = str_replace(':', '_', (string) $t['media_id']);
        $dest = $this->downloads . '/' . $id . ($video ? '.mp4' : '.audio');
        if (is_file($dest) && filesize($dest) > 1024) {
            return $dest;
        }
        return $this->iota->downloadToFile($t['media_id'], $dest, $video) ? $dest : null;
    }

    // ── room engine ──────────────────────────────────────────────────────────

    public function dropRoom(int $chatId): void
    {
        $r = $this->rooms[$chatId] ?? null;
        if ($r === null) {
            return;
        }
        $this->voice?->leave($chatId);
        unset($this->rooms[$chatId]);
    }

    public function advanceRoom(int $chatId): void
    {
        $r = $this->rooms[$chatId] ?? null;
        if ($r === null) {
            return;
        }
        if ($r->loop && $r->path !== '') {
            $err = $this->voice?->playFile($chatId, $r->path, (bool) ($r->track['video'] ?? false));
            if ($err === '') {
                $r->position = 0;
                $r->endStreak = 0;
                if ($r->loopLeft > 0 && --$r->loopLeft === 0) {
                    $r->loop = false;
                }
                return;
            }
        }
        $next = array_shift($r->queue);
        if ($next === null && $r->autoplay && $r->track !== null) {
            $this->send($chatId, $this->t($chatId, 'stream_downloading_next'));
            $next = $this->autoplayPick($r->track);
            if ($next !== null) {
                $this->send($chatId, $this->t($chatId, 'stream_autoplay_next', [
                    'title' => Util::escapeHtml($next['title']),
                ]));
            }
        }
        if ($next === null) {
            $this->dropRoom($chatId);
            $this->send($chatId, $this->t($chatId, 'stream_queue_finished'));
            return;
        }
        $this->startTrack($chatId, $r, $next, 0);
    }

    private function autoplayPick(array $last): ?array
    {
        $id = $last['media_id'] ?? '';
        $body = null;
        if ($id !== '' && preg_match('#^yt:(.+)$#', $id, $mm)) {
            $url = rtrim(getenv('IOTA_API_URL') ?: 'http://127.0.0.1:8014', '/')
                . '/similar/' . rawurlencode('yt:' . $mm[1]) . '?limit=8';
            $body = @file_get_contents($url);
        }
        if ($body !== false && $body !== null) {
            $json = json_decode($body, true);
            foreach (($json['results'] ?? []) as $cand) {
                if (($cand['media_id'] ?? '') !== '') {
                    return [
                        'media_id' => $cand['media_id'],
                        'title' => ($cand['title'] ?? '') . (($cand['artist'] ?? '') !== '' ? ' - ' . $cand['artist'] : ''),
                        'artist' => $cand['artist'] ?? '',
                        'duration' => $cand['duration_seconds'] ?? 0,
                        'thumbnail' => $cand['thumbnail_url'] ?? '',
                    ];
                }
            }
        }
        $res = $this->iota->search(($last['title'] ?? '') . ' similar songs', 3);
        return $res[0] ?? null;
    }

    /** 1s room tick: position, end-streak, progress-button refresh. */
    public function tick(): void
    {
        foreach ($this->rooms as $chatId => $r) {
            if (!$r->playing || $r->paused || $r->track === null) {
                continue;
            }
            $r->position++;
            $dur = (int) ($r->track['duration'] ?? 0);
            if ($dur > 0 && $r->position >= $dur) {
                $r->endStreak++;
                if ($r->endStreak >= 3) {
                    $this->advanceRoom($chatId);
                    continue;
                }
            } else {
                $r->endStreak = 0;
            }
            // Nandani refreshes the progress button every 5s (markup-only edit)
            if ($r->position % 5 === 0 && $r->statusMsg !== 0) {
                $this->editMarkup($chatId, $r->statusMsg, $this->playMarkup($r));
            }
        }
    }

    public function mainLoopTick(): void
    {
        $this->voice?->cameraTick();
        $now = time();
        if ($now !== $this->lastTick) {
            $this->lastTick = $now;
            $this->tick();
        }
        if ($now - $this->lastPing >= 45) {
            $this->lastPing = $now;
            foreach (MtProto::clients() as $c) {
                $c->sendPing();
            }
            $this->botPeers->maybeRefresh();
        }
    }

    // ── buttons ──────────────────────────────────────────────────────────────

    private function playMarkup(Room $r, bool $queued = false): TlNode
    {
        return Buttons::playMarkup($this->loc, $this->lang($r->chatId), $r, $queued);
    }

    private function onCallback(TlNode $q): void
    {
        $data = (string) $q->str('data');
        $sender = $q->l('user_id');
        $queryId = $q->l('query_id');
        $lang = $this->lang(0); // callback has no chat lang context; en fallback
        try {
            if (str_starts_with($data, 'room:')) {
                $parts = explode(':', $data);
                $chatId = (int) $parts[1];
                $action = $parts[2] ?? '';
                $lang = $this->lang($chatId);
                $this->onRoomCallback($q, $chatId, $action, $parts[3] ?? '', $sender, $lang);
                return;
            }
            switch ($data) {
                case 'start':
                    $this->editOrSend($q, $this->loc->f($lang, 'start_private', 'user', $this->mention($sender)),
                        Buttons::startMarkup($this->loc, $lang));
                    $this->answerQuery($queryId, '', false);
                    return;
                case 'help_cb':
                case 'help:main':
                    $this->editOrSend($q, $this->loc->get($lang, 'help_main'),
                        Buttons::helpKeyboard($this->loc, $lang));
                    $this->answerQuery($queryId, '', false);
                    return;
                case str_starts_with($data, 'help:'):
                    $section = substr($data, 5);
                    $key = 'help_' . $section;
                    $this->editOrSend($q, $this->loc->get($lang, $key), Buttons::backKeyboard($this->loc, $lang));
                    $this->answerQuery($queryId, '', false);
                    return;
                case str_starts_with($data, 'lang:'):
                    $code = substr($data, 5);
                    $this->db->setLang($sender, $code);
                    $this->answerQuery($queryId, $this->loc->f($code, 'lang_success', 'lang_name',
                        $this->loc->get($code, 'name')), false);
                    $this->editOrSend($q, $this->loc->get($code, 'lang_select'), Buttons::langKeyboard($this->loc, $code));
                    return;
                case 'cancel':
                    $this->answerQuery($queryId, $this->loc->get($lang, 'no_download_to_cancel'), false);
                    return;
            }
        } catch (Throwable $e) {
            echo "[yumi] callback failed: " . $e->getMessage() . "\n";
            $this->answerQuery($queryId, $this->loc->get($lang, 'invalid_request'), true);
        }
    }

    private function onRoomCallback(TlNode $q, int $chatId, string $action, string $p4, int $sender, string $lang): void
    {
        $queryId = $q->l('query_id');
        // Nandani: all playback buttons are admin-only
        if (!in_array($action, ['progress', 'close'], true) && !$this->isAdmin($chatId, $sender)) {
            $this->answerQuery($queryId, $this->loc->get($lang, 'playmode_restricted'), true);
            return;
        }
        $r = $this->rooms[$chatId] ?? null;
        switch ($action) {
            case 'progress':
                $this->answerQuery($queryId, '', false);
                return;
            case 'close':
                try {
                    $this->bot->invoke((new TlNode('messages.deleteMessages'))->put('id', [$q->i('msg_id')]), 15);
                } catch (Throwable $ignored) {
                }
                $this->answerQuery($queryId, '', false);
                return;
        }
        if ($r === null) {
            $this->answerQuery($queryId, $this->loc->get($lang, 'queue_no_active'), true);
            return;
        }
        $dur = (int) ($r->track['duration'] ?? 0);
        $title = Util::escapeHtml((string) ($r->track['title'] ?? '-'));
        switch ($action) {
            case 'pause':
                if ($r->paused) {
                    $this->answerQuery($queryId, $this->loc->get($lang, 'cb_already_playing'), false);
                    return;
                }
                $r->paused = true;
                $this->voice?->ntg->pause($chatId);
                $this->answerQuery($queryId, $this->loc->f($lang, 'cb_pause_success',
                    'position', Util::formatTime($r->position)), false);
                break;
            case 'resume':
            case 'unmute': // stop-confirm card's unmute-or-stop row
                if (!$r->paused) {
                    $this->answerQuery($queryId, $this->loc->get($lang, 'cb_already_playing'), false);
                    return;
                }
                $r->paused = false;
                $this->voice?->ntg->resume($chatId);
                $this->answerQuery($queryId, $this->loc->f($lang, 'cb_resume_success',
                    'position', Util::formatTime($r->position)), false);
                break;
            case 'replay':
                if ($r->path !== '') {
                    $err = $this->voice?->playFile($chatId, $r->path, (bool) ($r->track['video'] ?? false));
                    if ($err === '') {
                        $r->position = 0;
                        $r->endStreak = 0;
                    }
                }
                $this->answerQuery($queryId, $this->loc->get($lang, 'cb_replaying'), false);
                break;
            case 'skip':
                $this->answerQuery($queryId, $this->loc->get($lang, 'cb_replaying'), false);
                $this->advanceRoom($chatId);
                return;
            case 'stop':
                $this->answerQuery($queryId, '', false);
                $this->dropRoom($chatId);
                $this->send($chatId, $this->loc->f($lang, 'stopped', 'user', $this->botPeers->userMention($sender)));
                return;
            case 'seekback_15':
            case 'seek_15':
                $back = $action === 'seekback_15';
                $target = $back ? max(0, $r->position - 15) : min($dur, $r->position + 15);
                $err = $this->seekTo($chatId, $r, $target);
                $this->answerQuery($queryId, $err === ''
                    ? $this->loc->f($lang, $back ? 'cb_seekback_success' : 'cb_seek_success', 'seconds', '15')
                    : $err, false);
                break;
            case 'autoplay_toggle':
                $this->answerQuery($queryId, '', false);
                $this->editMarkup($chatId, $q->i('msg_id'),
                    Buttons::autoplayPanel($this->loc, $lang, $chatId));
                return;
            case 'autoplay_on':
            case 'autoplay_off':
                $r->autoplay = $action === 'autoplay_on';
                $this->db->setAutoplay($chatId, $r->autoplay);
                $this->answerQuery($queryId,
                    $this->loc->get($lang, $r->autoplay ? 'autoplay_enabled' : 'autoplay_disabled'), false);
                break;
            default:
                $this->answerQuery($queryId, $this->loc->get($lang, 'unknown_action'), false);
                return;
        }
        if ($r->statusMsg !== 0) {
            $this->editMarkup($chatId, $r->statusMsg, $this->playMarkup($r));
        }
    }

    private function editOrSend(TlNode $q, string $text, TlNode $markup): void
    {
        $msgId = $q->i('msg_id');
        if ($msgId > 0) {
            $this->edit($q->l('user_id') > 0 ? $this->callbackChat($q) : 0, $msgId, $text, $markup);
        }
    }

    private function callbackChat(TlNode $q): int
    {
        $peer = $q->node('peer');
        return $peer !== null ? $this->peerChatId($peer) : $q->l('user_id');
    }

    // ── telegram sends ───────────────────────────────────────────────────────

    /** Extract the real message id from a messages.* send response (Updates node). */
    private function updatesMsgId(TlNode $res): int
    {
        foreach ($res->list('updates') as $u) {
            if (!$u instanceof TlNode) {
                continue;
            }
            if (in_array($u->name, ['message', 'messageEmpty', 'messageService'], true)) {
                return (int) $u->l('id');
            }
            if ($u->name === 'updateNewMessage' || $u->name === 'updateNewChannelMessage') {
                $m = $u->node('message');
                if ($m !== null) {
                    return (int) $m->l('id');
                }
            }
            if ($u->name === 'updateMessageID') {
                return (int) $u->l('id');
            }
        }
        return 0;
    }

    public function send(int $chatId, string $text, int $replyTo = 0, ?TlNode $markup = null): int
    {
        if ($text === '') {
            return 0;
        }
        try {
            [$plain, $entities] = MsgEntities::parse($text);
            $req = (new TlNode('messages.sendMessage'))
                ->put('peer', $this->botPeers->inputPeer($chatId))
                ->put('message', $plain)
                ->put('random_id', (int) (microtime(true) * 1e6) ^ random_int(0, PHP_INT_MAX));
            if ($replyTo > 0) {
                $req->put('reply_to_msg_id', $replyTo);
            }
            if ($entities !== []) {
                $req->put('entities', $entities);
            }
            if ($markup !== null) {
                $req->put('reply_markup', $markup);
            }
            $res = $this->bot->invoke($req, 30);
            $this->botPeers->learnFrom($res);
            return $this->updatesMsgId($res) ?: ($res->l('id') ?? 0);
        } catch (Throwable $e) {
            echo "[yumi] send failed ($chatId): " . $e->getMessage() . "\n";
            return 0;
        }
    }

    public function edit(int $chatId, int $msgId, string $text, ?TlNode $markup = null): void
    {
        try {
            [$plain, $entities] = MsgEntities::parse($text);
            $req = (new TlNode('messages.editMessage'))
                ->put('peer', $this->botPeers->inputPeer($chatId))
                ->put('id', $msgId)
                ->put('message', $plain);
            if ($entities !== []) {
                $req->put('entities', $entities);
            }
            if ($markup !== null) {
                $req->put('reply_markup', $markup);
            }
            $this->bot->invoke($req, 20);
        } catch (Throwable $e) {
            // MESSAGE_NOT_MODIFIED etc — silent
        }
    }

    /** Markup-only edit — used for the 5s progress-button refresh. */
    public function editMarkup(int $chatId, int $msgId, TlNode $markup): void
    {
        try {
            $this->bot->invoke((new TlNode('messages.editMessage'))
                ->put('peer', $this->botPeers->inputPeer($chatId))
                ->put('id', $msgId)
                ->put('reply_markup', $markup), 20);
        } catch (Throwable $e) {
            echo "[yumi] editMarkup failed (msg=$msgId): " . $e->getMessage() . "\n";
        }
    }

    public function answerQuery(int $queryId, string $text, bool $alert): void
    {
        try {
            $req = (new TlNode('messages.setBotCallbackAnswer'))
                ->put('query_id', (int) $queryId);
            if ($alert) {
                $req->put('alert', true);
            }
            if ($text !== '') {
                $req->put('message', $text);
            }
            $this->bot->invoke($req, 15);
        } catch (Throwable $e) {
            echo "[yumi] answerQuery: " . $e->getMessage() . "\n";
        }
    }

    /** Send a local audio/video file as a document (upload.saveBigFilePart). */
    public function sendDocument(int $chatId, string $path, array $track, bool $video): string
    {
        try {
            $size = filesize($path);
            $partSize = 512 * 1024;
            $total = (int) ceil($size / $partSize);
            $fileId = random_int(1, PHP_INT_MAX);
            $fh = fopen($path, 'rb');
            if ($fh === false) {
                return 'cannot open file';
            }
            try {
                for ($i = 0; $i < $total; $i++) {
                    $bytes = fread($fh, $partSize);
                    if ($bytes === false) {
                        return 'read failed';
                    }
                    $this->bot->invoke((new TlNode('upload.saveBigFilePart'))
                        ->put('file_id', $fileId)
                        ->put('file_part', $i)
                        ->put('file_total_parts', $total)
                        ->put('bytes', $bytes), 60);
                }
            } finally {
                fclose($fh);
            }
            if ($video) {
                $attrs = [
                    (new TlNode('documentAttributeVideo'))
                        ->put('duration', (int) ($track['duration'] ?? 0))
                        ->put('w', 1280)->put('h', 720)
                        ->put('supports_streaming', true),
                    (new TlNode('documentAttributeFilename'))->put('file_name',
                        preg_replace('/[^\w\-. ]+/u', '', (string) ($track['title'] ?? 'video')) . '.mp4'),
                ];
            } else {
                $attrs = [
                    (new TlNode('documentAttributeAudio'))
                        ->put('title', mb_substr((string) ($track['title'] ?? 'Song'), 0, 60))
                        ->put('performer', mb_substr((string) ($track['artist'] ?? ''), 0, 60))
                        ->put('duration', (int) ($track['duration'] ?? 0)),
                    (new TlNode('documentAttributeFilename'))->put('file_name',
                        preg_replace('/[^\w\-. ]+/u', '', (string) ($track['title'] ?? 'song')) . '.mp3'),
                ];
            }
            $media = (new TlNode('inputMediaUploadedDocument'))
                ->put('file', (new TlNode('inputDocumentBigUploaded'))
                    ->put('id', $fileId)
                    ->put('parts', $total)
                    ->put('name', 'audio'))
                ->put('mime_type', $video ? 'video/mp4' : 'audio/mpeg')
                ->put('attributes', $attrs)
                ->put('force_file', false);
            $caption = Util::stylish(Locales::expandEmojis(
                "{emoji:🎵} <b>ʏσυꝛ sσɴɢ ғꝛσᴍ {bot}!</b>\n\n<blockquote>❖ "
                . Util::escapeHtml((string) ($track['title'] ?? ''))
                . "\n➥ <i>ᴘʟᴧʏɪηɢ ɪη ᴠᴄ ᴧηᴅ sєηᴛ ʜєꝛє ♬</i></blockquote>"
            ));
            $caption = str_replace('{bot}', $this->botName, $caption);
            $req = (new TlNode('messages.sendMedia'))
                ->put('peer', $this->botPeers->inputPeer($chatId))
                ->put('media', $media)
                ->put('message', $caption)
                ->put('random_id', random_int(1, PHP_INT_MAX));
            $this->bot->invoke($req, 120);
            return '';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
