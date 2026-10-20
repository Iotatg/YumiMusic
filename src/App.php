<?php

declare(strict_types=1);

/**
 * YumiMusic application: rooms, commands, now-playing cards, audio uploads
 * and the voice-chat bridge. Single cooperative process (bot + assistant).
 */
final class App
{
    public MtProto $bot;
    public Peers $botPeers;
    public ?Voice $voice = null;
    public Iota $iota;
    public int $ownerId;
    private string $downloads;

    /** @var array<int, Room> */
    public array $rooms = [];

    /** @var array<int, array<int, array>> chatId → last search results */
    private array $searchCache = [];
    private int $lastPing = 0;
    private int $lastTick = 0;
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



    // ── lifecycle ────────────────────────────────────────────────────────────

    public function start(): void
    {
        // bot auth (persisted session → skip on restart)
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
                    // auth key lost on the server side — fresh handshake + re-auth
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
                ['play', 'Play a song'], ['video', 'Send a video'],
                ['vplay', 'Stream video in VC'],
                ['skip', 'Skip current track'], ['pause', 'Pause'],
                ['resume', 'Resume'], ['stop', 'Stop and clear'],
                ['queue', 'Show queue'], ['seek', 'Seek seconds'],
                ['loop', 'Loop current'], ['shuffle', 'Shuffle queue'],
                ['autoplay', 'Toggle autoplay'], ['leave', 'Leave voice chat'],
                ['ping', 'Latency'],
            ];
            $vectors = [];
            foreach ($names as [$cmd, $desc]) {
                $vectors[] = (new TlNode('botCommand'))->put('command', $cmd)->put('description', $desc);
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
                $this->learnFromMessage($msg, $container);
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

    private function learnFromMessage(TlNode $msg, ?TlNode $container): void
    {
        $peer = $msg->node('peer_id');
        if ($peer === null) {
            return;
        }
        if ($peer->name === 'peerChat') {
            $this->botPeers->learnFrom((new TlNode('x'))->put('chats', [
                (new TlNode('chat'))->put('id', -$peer->l('chat_id')),
            ]));
        } elseif ($peer->name === 'peerChannel') {
            // channel access hashes ride in updates.chats — handled by learnFrom
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
            $dur = Util::fmtTime($action->i('duration'));
            $this->send($chatId, YumiStyle::header(
                "\u{26A0}\u{FE0F} <b>𝐕‌ɪᴅєσ 𝐂‌ʜᴧᴛ 𝐄‌ηᴅєᴅ!</b>\n\n<blockquote>\u{23F0} 𝐃‌υꝛᴧᴛɪση ➜ $dur</blockquote>"
            ));
            $this->dropRoom($chatId);
        } else {
            $this->send($chatId, YumiStyle::header(
                "\u{267B}\u{FE0F} <b>𝐕‌σɪᴄє 𝐂‌ʜᴧᴛ 𝐒‌ᴛᴧꝛᴛєᴅ!</b>\n\n<blockquote>❖ 𝐔‌sє /play [song name]\n➥ 𝐓‌σ 𝐏‌ʟᴧʏ 𝐘‌συꝛ 𝐅‌ᴧᴠσυꝛɪᴛє 𝐌‌υsɪᴄ \u{266C}</blockquote>"
            ));
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
        echo "[yumi] chat=$chatId user=$sender cmd=$cmd\n";
        try {
            $this->dispatch($chatId, $sender, $cmd, $arg, $msg);
        } catch (Throwable $e) {
            echo "[yumi] cmd $cmd failed: " . $e->getMessage() . "\n";
            $this->send($chatId, YumiStyle::header(
                "\u{26D4} <b>𝐄‌ꝛꝛσꝛ!</b>\n\n<blockquote>" . Util::escape($e->getMessage()) . "</blockquote>"
            ));
        }
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
            // try via cached participant roles in updates (best effort)
            return false;
        }
    }

    private function senderName(TlNode $msg): string
    {
        $from = $msg->node('from_id');
        if ($from === null) {
            return '𝐔‌sєꝛ';
        }
        $uid = $from->l('user_id');
        // try to find name from cached users in the raw update — fallback id
        return '𝐔‌sєꝛ#' . $uid;
    }

    // ── commands ─────────────────────────────────────────────────────────────

    private function dispatch(int $chatId, int $sender, string $cmd, string $arg, TlNode $msg): void
    {
        switch ($cmd) {
            case '/start':
                $this->send($chatId, YumiStyle::header(
                    "\u{1F44B} <b>𝐖‌єʟᴄσᴍє 𝐓‌σ 𝐘‌υϻɪ 𝘅 ϻ‌υsɪᴄ!</b>\n\n"
                    . "<blockquote>❖ 𝐀ᴅᴅ ᴍᴇ ᴛᴏ ʏᴏᴜʀ ɢʀᴏᴜᴘ\n"
                    . "➥ 𝐏‌ʟᴧʏ ᴍᴜsɪᴄ ɪɴ ᴠᴏɪᴄᴇ ᴄʜᴀᴛ\n\n"
                    . "❖ 𝐔‌sє /play [song name]\n"
                    . "➥ 𝐄‌xᴧᴍᴘʟє : /play believer</blockquote>"
                ), $msg->i('id'));
                return;
            case '/help':
                $this->send($chatId, YumiStyle::header(
                    "<b>𝐂‌σᴍᴍᴧηᴅ 𝐋‌ɪsᴛ!</b>\n\n"
                    . "<blockquote>❖ /play [name] — 𝐏‌ʟᴧʏ 𝐌‌υsɪᴄ\n"
                    . "❖ /video [name] — 𝐆‌єᴛ 𝐕‌ɪᴅєσ\n"
                    . "❖ /skip /pause /resume /stop\n"
                    . "❖ /queue /seek [sec] /loop /shuffle\n"
                    . "❖ /autoplay — 𝐓‌σɢɢʟє\n"
                    . "❖ /leave — 𝐋‌єᴧᴠє 𝐕‌𝐂\n"
                    . "❖ /ping — 𝐋‌ᴧᴛєηᴄʏ</blockquote>"
                ), $msg->i('id'));
                return;
            case '/ping':
                $t0 = microtime(true);
                $this->bot->invoke((new TlNode('ping'))->put('ping_id', 1), 10);
                $ms = (int) ((microtime(true) - $t0) * 1000);
                $up = time() - $this->started;
                $this->send($chatId, YumiStyle::header(
                    "\u{1F3D3} <b>𝐏‌σηɢ!</b>\n\n<blockquote>❖ 𝐏‌σηɢ : {$ms}ᴍs\n"
                    . "❖ 𝐔‌ᴘᴛɪᴍᴇ : " . Util::fmtTime($up) . "</blockquote>"
                ), $msg->i('id'));
                return;
            case '/play':
            case '/song':
                $this->cmdPlay($chatId, $sender, $arg, false);
                return;
            case '/video':
                $this->cmdPlay($chatId, $sender, $arg, true);
                return;
            case '/vplay':
                // Nandani parity: stream the video into the voice chat
                if (!$this->guardVc($chatId)) return;
                $this->cmdVcVideo($chatId, $sender, $arg);
                return;
            case '/skip':
                if (!$this->guardVc($chatId)) return;
                $this->advanceRoom($chatId);
                $this->send($chatId, YumiStyle::header(
                    "\u{23ED} <b>𝐓‌ꝛᴧᴄᴋ 𝐒‌ᴋɪᴘᴘєᴅ!</b>\n\n<blockquote>❖ 𝐍‌єxᴛ 𝐓‌ꝛᴧᴄᴋ 𝐋‌σᴧᴅɪηɢ... \u{266C}</blockquote>"
                ));
                return;
            case '/pause':
                if (!$this->guardVc($chatId)) return;
                $r = $this->rooms[$chatId] ?? null;
                if ($r && !$r->paused) {
                    $r->paused = true;
                    $this->voice?->ntg->pause($chatId);
                }
                $this->send($chatId, YumiStyle::header(
                    "\u{23F8}\u{FE0F} <b>𝐌‌υsɪᴄ 𝐏‌ᴧᴜsєᴅ!</b>\n\n<blockquote>❖ 𝐏‌ʟᴧʏʙᴧᴄᴋ ɪs σɴ ʜσʟᴅ</blockquote>"
                ));
                return;
            case '/resume':
                if (!$this->guardVc($chatId)) return;
                $r = $this->rooms[$chatId] ?? null;
                if ($r && $r->paused) {
                    $r->paused = false;
                    $this->voice?->ntg->resume($chatId);
                }
                $this->send($chatId, YumiStyle::header(
                    "\u{25B6}\u{FE0F} <b>𝐌‌υsɪᴄ 𝐑‌єsᴜᴍєᴅ!</b>\n\n<blockquote>❖ 𝐏‌ʟᴧʏʙᴧᴄᴋ ɪs ʙᴧᴄᴋ σɴ ᴛʀᴧᴄᴋ \u{266C}</blockquote>"
                ));
                return;
            case '/end':
            case '/stop':
                if (!$this->guardVc($chatId)) return;
                $this->dropRoom($chatId);
                $this->send($chatId, YumiStyle::header(
                    "\u{23F9}\u{FE0F} <b>𝐌‌υsɪᴄ 𝐒‌ᴛσᴘᴘєᴅ!</b>\n\n<blockquote>❖ 𝐐‌υєυє ᴄʟᴇᴀʀᴇᴅ, ᴠᴄ ʟᴇғᴛ</blockquote>"
                ));
                return;
            case '/leave':
                if (!$this->guardVc($chatId)) return;
                $this->dropRoom($chatId);
                $this->send($chatId, YumiStyle::header(
                    "\u{1F44B} <b>𝐋‌єғᴛ 𝐕‌σɪᴄє 𝐂‌ʜᴧᴛ!</b>"
                ));
                return;
            case '/queue':
                $r = $this->rooms[$chatId] ?? null;
                if ($r === null) {
                    $this->send($chatId, YumiStyle::header(
                        "\u{1F4AD} <b>𝐐‌υєυє 𝐈‌s 𝐄‌ᴍᴘᴛʏ!</b>\n\n<blockquote>❖ 𝐔‌sє /play ᴛᴏ ᴀᴅᴅ sᴏɴɢs</blockquote>"
                    ));
                    return;
                }
                $body = "<blockquote>❖ 𝐍‌σᴡ : " . Util::escape($r->track['title'] ?? '-') . "</blockquote>";
                foreach ($r->queue as $i => $t) {
                    $body .= "\n\u{279C} <b>" . ($i + 1) . ".</b> " . Util::escape($t['title']);
                }
                $this->send($chatId, YumiStyle::header("\u{1F3BC} <b>𝐐‌υєυє!</b>\n\n" . $body));
                return;
            case '/loop':
                if (!$this->guardVc($chatId)) return;
                $r = $this->rooms[$chatId] ?? null;
                if ($r) {
                    $r->loop = !$r->loop;
                    $state = $r->loop ? '𝐄‌ηᴧʙʟєᴅ' : '𝐃‌ɪsᴧʙʟєᴅ';
                    $this->send($chatId, YumiStyle::header(
                        "\u{1F501} <b>𝐋‌σσᴘ $state!</b>"
                    ));
                }
                return;
            case '/shuffle':
                if (!$this->guardVc($chatId)) return;
                $r = $this->rooms[$chatId] ?? null;
                if ($r) {
                    shuffle($r->queue);
                    $this->send($chatId, YumiStyle::header(
                        "\u{1F500} <b>𝐐‌υєυє 𝐒‌ʜᴜғғʟєᴅ!</b>"
                    ));
                }
                return;
            case '/autoplay':
                if (!$this->guardVc($chatId)) return;
                $r = $this->rooms[$chatId] ?? null;
                if ($r) {
                    $r->autoplay = !$r->autoplay;
                    $state = $r->autoplay ? '𝐄‌ηᴧʙʟєᴅ' : '𝐃‌ɪsᴧʙʟєᴅ';
                    $this->send($chatId, YumiStyle::header(
                        "\u{1F3B6} <b>𝐀‌υᴛσᴘʟᴧʏ $state!</b>"
                    ));
                }
                return;
            case '/seek':
                if (!$this->guardVc($chatId)) return;
                $r = $this->rooms[$chatId] ?? null;
                if ($r && ctype_digit($arg)) {
                    $r->position = min((int) $arg, (int) ($r->track['duration'] ?? 0));
                    $this->voice?->ntg->stop($chatId);
                    $err = $this->voice?->playFile($chatId, $r->path, $r->track['video'] ?? false, $r->position);
                    if ($err === '') {
                        $this->send($chatId, YumiStyle::header(
                            "\u{23E9} <b>𝐒‌єєᴋєᴅ 𝐓‌σ " . Util::fmtTime($r->position) . "!</b>"
                        ));
                    }
                }
                return;
            case '/stats':
                if ($sender === $this->ownerId) {
                    $rooms = count($this->rooms);
                    $this->send($chatId, YumiStyle::header(
                        "\u{1F4CA} <b>𝐒‌ᴛᴧᴛs!</b>\n\n<blockquote>❖ 𝐑‌σσᴍs : $rooms\n"
                        . "❖ 𝐕‌𝐂 : " . ($this->vcEnabled ? 'on' : 'off') . "</blockquote>"
                    ));
                }
                return;
        }
    }

    private function guardVc(int $chatId): bool
    {
        if (!$this->vcEnabled || $this->voice === null) {
            $this->send($chatId, YumiStyle::header(
                "\u{26D4} <b>𝐕‌σɪᴄє 𝐂‌ʜᴧᴛ 𝐃‌ɪsᴧʙʟєᴅ!</b>"
            ));
            return false;
        }
        return true;
    }

    // ── play pipeline ────────────────────────────────────────────────────────

    private function cmdPlay(int $chatId, int $sender, string $arg, bool $video): void
    {
        if ($arg === '') {
            $this->send($chatId, YumiStyle::header(
                "\u{2753} <b>𝐔‌sᴧɢє!</b>\n\n<blockquote>❖ /play [song name]\n➥ 𝐄‌xᴧᴍᴘʟє : /play believer</blockquote>"
            ));
            return;
        }
        $results = $this->iota->search($arg, 5);
        if ($results === []) {
            $this->send($chatId, YumiStyle::header(
                "\u{274C} <b>𝐍‌σ 𝐑‌єsυʟᴛs!</b>\n\n<blockquote>❖ 𝐓‌ꝛʏ ᴧησᴛʜєꝛ ǫᴜᴇʀʏ</blockquote>"
            ));
            return;
        }
        if ($video) {
            // /video sends the file directly (no VC streaming)
            $pick = $results[0];
            $this->send($chatId, YumiStyle::header(
                "\u{2B07} <b>𝐃‌σωηʟσᴧᴅɪηɢ 𝐕‌ɪᴅєσ!</b>\n\n<blockquote>❖ " . Util::escape($pick['title']) . "</blockquote>"
            ));
            $this->sendVideoFile($chatId, $pick);
            return;
        }
        if (!isset($this->rooms[$chatId])) {
            $this->rooms[$chatId] = new Room($chatId);
        }
        $r = $this->rooms[$chatId];
        $this->searchCache[$chatId] = $results;

        if ($r->track === null && $r->queue === []) {
            $this->startTrack($chatId, $r, $results[0], $sender);
        } else {
            $t = $results[0];
            $r->queue[] = $t;
            $idx = count($r->queue);
            $this->send($chatId, YumiStyle::queued($t, $idx, '𝐔‌sєꝛ'), 0, $this->playMarkup($r));
        }
    }

    private function cmdVcVideo(int $chatId, int $sender, string $arg): void
    {
        if ($arg === '') {
            $this->send($chatId, YumiStyle::header(
                "\u{2753} <b>𝐔‌sᴧɢє!</b>\n\n<blockquote>❖ /vplay [video name]\n➥ 𝐄‌xᴧᴍᴘʟє : /vplay believer</blockquote>"
            ));
            return;
        }
        $results = $this->iota->search($arg, 5);
        if ($results === []) {
            $this->send($chatId, YumiStyle::header(
                "\u{274C} <b>𝐍‌σ 𝐑‌єsυʟᴛs!</b>\n\n<blockquote>❖ 𝐓‌ꝛʏ ᴧησᴛʜєꝛ ǫᴜᴇʀʏ</blockquote>"
            ));
            return;
        }
        if (!isset($this->rooms[$chatId])) {
            $this->rooms[$chatId] = new Room($chatId);
        }
        $r = $this->rooms[$chatId];
        if ($r->track === null && $r->queue === []) {
            $this->startTrack($chatId, $r, $results[0], $sender, true);
        } else {
            $t = $results[0];
            $t['video'] = true;
            $r->queue[] = $t;
            $this->send($chatId, YumiStyle::queued($t, count($r->queue), '𝐔‌sєꝛ'), 0, $this->playMarkup($r));
        }
    }

    private function startTrack(int $chatId, Room $r, array $t, int $sender, bool $vcVideo = false): void
    {
        $r->track = $t;
        $r->position = 0;
        $r->endStreak = 0;
        $this->send($chatId, YumiStyle::header(
            "\u{2B07} <b>𝐃‌σωηʟσᴧᴅɪηɢ!</b>\n\n<blockquote>❖ " . Util::escape($t['title']) . "</blockquote>"
        ));
        $path = $this->resolveTrack($t, $vcVideo);
        if ($path === null) {
            $r->track = null;
            $this->send($chatId, YumiStyle::header(
                "\u{274C} <b>𝐃‌σωηʟσᴧᴅ ғᴧɪʟєᴅ!</b>"
            ));
            return;
        }
        $r->path = $path;
        $err = $this->voice?->playFile($chatId, $path, $vcVideo);
        if ($err !== '') {
            $r->track = null;
            echo "[yumi] playFile failed: $err\n";
            $this->send($chatId, YumiStyle::header(
                "\u{274C} <b>𝐕‌𝐂 𝐉‌σɪη ғᴧɪʟєᴅ!</b>\n\n<blockquote>" . Util::escape($err) . "</blockquote>"
            ));
            return;
        }
        $r->playing = true;
        $r->statusMsg = $this->send(
            $chatId,
            YumiStyle::nowPlaying($t, '𝐔‌sєꝛ', Util::fmtTime(0), Util::bar(0, (int) $t['duration'])),
            0,
            $this->playMarkup($r)
        );
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

    private function sendVideoFile(int $chatId, array $t): void
    {
        $path = $this->resolveTrack($t, true);
        if ($path === null) {
            $this->send($chatId, YumiStyle::header("\u{274C} <b>𝐃‌σωηʟσᴧᴅ ғᴧɪʟєᴅ!</b>"));
            return;
        }
        $err = $this->sendDocument($chatId, $path, $t, true);
        if ($err !== '') {
            echo "[yumi] sendVideo: $err\n";
        }
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
                return;
            }
        }
        $next = array_shift($r->queue);
        if ($next === null && $r->autoplay && $r->track !== null) {
            $next = $this->autoplayPick($r->track);
        }
        if ($next === null) {
            $this->dropRoom($chatId);
            $this->send($chatId, YumiStyle::header(
                "\u{1F4AE} <b>𝐐‌υєυє ғɪηɪsʜєᴅ!</b>\n\n<blockquote>\u{1F490} 𝐔‌sє /play ᴛᴏ ᴀᴅᴅ ᴍᴏʀᴇ sᴏɴɢs..!</blockquote>"
            ));
            return;
        }
        $this->startTrack($chatId, $r, $next, 0);
    }

    private function autoplayPick(array $last): ?array
    {
        // Tier 0: iota /similar, Tier 1: "title similar songs" search
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
                        'thumbnail' => '',
                    ];
                }
            }
        }
        $res = $this->iota->search(($last['title'] ?? '') . ' similar songs', 3);
        return $res[0] ?? null;
    }

    /** 1s room tick: position, end-streak, card refresh. */
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
            if ($r->position % 5 === 0 && $r->statusMsg !== 0) {
                $this->edit($chatId, $r->statusMsg, YumiStyle::nowPlaying(
                    $r->track, '𝐔‌sєꝛ',
                    Util::fmtTime($r->position) . ' / ' . Util::fmtTime($dur),
                    Util::bar($r->position, $dur)
                ), $this->playMarkup($r));
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

    /** Layer-230 inline callback button. */
    private function cbButton(string $text, string $data): TlNode
    {
        return (new TlNode('keyboardInlineButton'))
            ->put('text', $text)
            ->put('type', (new TlNode('inlineButtonTypeCallback'))->put('data', $data));
    }

    private function playMarkup(Room $r): TlNode
    {
        $p = 'yumi:' . $r->chatId . ':';
        $row1 = [
            $r->paused
                ? $this->cbButton("\u{25B6}", $p . 'resume')
                : $this->cbButton("\u{23F8}", $p . 'pause'),
            $this->cbButton("\u{21BB}", $p . 'replay'),
            $this->cbButton("\u{23ED}", $p . 'skip'),
            $this->cbButton("\u{23F9}", $p . 'stop'),
        ];
        $ap = $r->autoplay ? "\u{1F3B6} on" : "\u{1F3B6} off";
        $row2 = [
            $this->cbButton($ap, $p . 'autoplay'),
            $this->cbButton('CLOSE', $p . 'close'),
        ];
        return (new TlNode('replyInlineMarkup'))->put('rows', [
            (new TlNode('keyboardInlineButtonRow'))->put('buttons', $row1),
            (new TlNode('keyboardInlineButtonRow'))->put('buttons', $row2),
        ]);
    }

    private function searchMarkup(int $chatId): TlNode
    {
        $row = [];
        foreach (($this->searchCache[$chatId] ?? []) as $i => $r) {
            $row[] = $this->cbButton((string) ($i + 1), "yumi:$chatId:pick:$i");
        }
        return (new TlNode('replyInlineMarkup'))->put('rows', [
            (new TlNode('keyboardInlineButtonRow'))->put('buttons', $row),
        ]);
    }

    private function onCallback(TlNode $q): void
    {
        $data = $q->str('data');
        $sender = $q->l('user_id');
        $parts = explode(':', $data); // yumi:chatId:action[:arg]
        if (count($parts) < 3 || $parts[0] !== 'yumi') {
            return;
        }
        $chatId = (int) $parts[1];
        $action = $parts[2];
        try {
            switch ($action) {
                case 'pause':
                case 'resume':
                case 'skip':
                case 'stop':
                case 'replay':
                case 'autoplay':
                    if (!$this->isAdmin($chatId, $sender)) {
                        $this->answerQuery($q->l('query_id'), "\u{26D4} 𝐀‌ᴅᴍɪηs σηʟʏ!", true);
                        return;
                    }
                    $r = $this->rooms[$chatId] ?? null;
                    if ($r === null) {
                        $this->answerQuery($q->l('query_id'), 'room not active', true);
                        return;
                    }
                    if ($action === 'pause' && !$r->paused) {
                        $r->paused = true;
                        $this->voice?->ntg->pause($chatId);
                        $this->answerQuery($q->l('query_id'), "\u{23F8} paused", false);
                    } elseif ($action === 'resume' && $r->paused) {
                        $r->paused = false;
                        $this->voice?->ntg->resume($chatId);
                        $this->answerQuery($q->l('query_id'), "\u{25B6} resumed", false);
                    } elseif ($action === 'skip') {
                        $this->answerQuery($q->l('query_id'), "\u{23ED} skipped", false);
                        $this->advanceRoom($chatId);
                        return;
                    } elseif ($action === 'stop') {
                        $this->answerQuery($q->l('query_id'), "\u{23F9} stopped", false);
                        $this->dropRoom($chatId);
                        return;
                    } elseif ($action === 'replay') {
                        if ($r->path !== '') {
                            $this->voice?->playFile($chatId, $r->path, false);
                            $r->position = 0;
                        }
                        $this->answerQuery($q->l('query_id'), "\u{21BB} replaying", false);
                        return;
                    } elseif ($action === 'autoplay') {
                        $r->autoplay = !$r->autoplay;
                        $this->answerQuery($q->l('query_id'), 'autoplay ' . ($r->autoplay ? 'on' : 'off'), false);
                    }
                    if ($r->statusMsg !== 0) {
                        $dur = (int) ($r->track['duration'] ?? 0);
                        $this->edit($chatId, $r->statusMsg, YumiStyle::nowPlaying(
                            $r->track ?? [], '𝐔‌sєꝛ', Util::fmtTime($r->position) . ' / ' . Util::fmtTime($dur),
                            Util::bar($r->position, $dur)
                        ), $this->playMarkup($r));
                    }
                    return;
                case 'close':
                    try {
                        $this->bot->invoke((new TlNode('messages.deleteMessages'))->put('id', [$q->i('msg_id')]), 15);
                    } catch (Throwable $ignored) {
                    }
                    $this->answerQuery($q->l('query_id'), '', false);
                    return;
                case 'pick':
                    $i = (int) ($parts[3] ?? 0);
                    $t = $this->searchCache[$chatId][$i] ?? null;
                    if ($t === null) {
                        $this->answerQuery($q->l('query_id'), 'expired', true);
                        return;
                    }
                    $this->answerQuery($q->l('query_id'), '', false);
                    if (!isset($this->rooms[$chatId])) {
                        $this->rooms[$chatId] = new Room($chatId);
                    }
                    $r = $this->rooms[$chatId];
                    if ($r->track === null && $r->queue === []) {
                        $this->startTrack($chatId, $r, $t, $sender);
                    } else {
                        $r->queue[] = $t;
                        $this->send($chatId, YumiStyle::queued($t, count($r->queue), '𝐔‌sєꝛ'), 0, $this->playMarkup($r));
                    }
                    return;
            }
        } catch (Throwable $e) {
            echo "[yumi] callback failed: " . $e->getMessage() . "\n";
            $this->answerQuery($q->l('query_id'), 'error', true);
        }
    }

    // ── telegram sends ───────────────────────────────────────────────────────

    public function send(int $chatId, string $text, int $replyTo = 0, ?TlNode $markup = null): int
    {
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
            return $res->node('updates')?->l('id') ?? ($res->l('id') ?? 0);
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
                    (new TlNode('documentAttributeFilename'))->put('file_name', 'video_' . $fileId . '.mp4'),
                ];
            } else {
                $attrs = [
                    (new TlNode('documentAttributeAudio'))
                        ->put('title', mb_substr((string) ($track['title'] ?? 'Song'), 0, 60))
                        ->put('performer', mb_substr((string) ($track['artist'] ?? ''), 0, 60))
                        ->put('duration', (int) ($track['duration'] ?? 0)),
                    (new TlNode('documentAttributeFilename'))->put('file_name', 'audio_' . $fileId . '.mp3'),
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
            $caption = YumiStyle::header(
                "\u{1F3B6} <b>𝐘‌συꝛ 𝐒‌σηɢ!</b>\n\n<blockquote>❖ " . Util::escape((string) ($track['title'] ?? ''))
                . "\n➥ 𝐅‌ꝛσᴍ 𝐘‌υϻɪ 𝘅 ϻ‌υsɪᴄ \u{266C}</blockquote>"
            );
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
