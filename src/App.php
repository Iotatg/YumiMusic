<?php

declare(strict_types=1);

/**
 * YumiMusic application — UI/UX engine:
 * Locales templates, signature stylish() outputs,
 * photo now-playing cards with progress-bar button rows, help
 * sections, language picker, autoplay panel, stop-confirm — all
 * driven through raw MTProto on the single cooperative process.
 */
final class App
{
    use Extras;

    public MtProto $bot;
    public Peers $botPeers;
    public ?Voice $voice = null;
    public Iota $iota;
    public RiteshApi $ritesh;
    public int $ownerId;
    private string $downloads;
    private Locales $loc;
    private Db $db;
    private string $botName;
    private string $ntgVer = '3.0.4';
    private string $assistantUser = '';
    /** False when the assistant session is gone (deleted account / revoked). */
    private bool $assistantOk = true;
    private int $botId = 0;
    private bool $maint = false;

    /** @var array<int, Room> */
    public array $rooms = [];

    /** @var array<int, array<int, array>> chatId → last search results */
    private array $searchCache = [];
    /** @var array<string, array>|null persisted query → tracks (auto-cache) */
    private ?array $qcache = null;
    private Chatbot $chatbot;
    /** @var array<string, bool> chatId:userId → admin flag (cleared by /reload) */
    private array $adminCache = [];
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
        string $riteshUrl = '',
        string $riteshKey = '',
        bool $riteshEnabled = true,
    ) {
        $this->downloads = dirname(__DIR__) . '/data/downloads';
        if (!is_dir($this->downloads)) {
            mkdir($this->downloads, 0777, true);
        }
        // Link-resolution cache lives next to the downloads so a restart keeps
        // both the parsed links and the audio files.
        UrlResolver::boot(dirname(__DIR__) . '/data/urlcache.json');
        $this->pruneDownloads();
        $this->started = time();
        $this->iota = new Iota($iotaUrl, $iotaKey);
        $this->ritesh = new RiteshApi($riteshUrl, $riteshKey, $riteshEnabled);
        $this->ownerId = (int) (getenv('OWNER_ID') ?: 0);
        $this->botName = (string) (getenv('BOT_NAME') ?: '𝐘‌υϻɪ 𝘅 ϻ‌υsɪᴄ');

        $this->loc = Locales::load(dirname(__DIR__) . '/locales');
        $this->loc->setBotName($this->botName);
        $this->db = new Db(dirname(__DIR__) . '/data/settings.json');
        Buttons::setBotUsername((string) (getenv('BOT_USERNAME') ?: 'Yumi_MusicBot'));
        $this->chatbot = new Chatbot(dirname(__DIR__) . '/data/chatbot.json', $this->botName, $this->ownerId);
        $this->chatbot->setUsername((string) (getenv('BOT_USERNAME') ?: ''));

        // ── bot client (raw MTProto, token auth) ──
        $this->bot = new MtProto($apiId, $apiHash, 2);
        $this->bot->tag = 'yumi-bot';
        $this->botPeers = new Peers($this->bot, 'yumi-bot');
        $this->botPeers->supportsDialogs = false; // bots can't getDialogs — updates only
        $this->bot->onUpdate = fn(TlNode $u) => $this->onBotUpdate($u);

        // ── assistant client (userbot session from env) ──
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
        // A stored session is already authorised — re-running
        // auth.importBotAuthorization on every boot burns a FLOOD_WAIT and
        // stalls startup for minutes. Probe first, log in only when the key
        // is genuinely unusable.
        if (!$this->probeAuthorized()) {
            $this->authorizeBot();
        }

        if (isset($this->voice)) {
            $amt = $this->voice->mt;
            $amt->connect();
            $this->whoAmIAssistant();
        }

        $this->botPeers->refresh();
        $this->setCommands();
        echo "[yumi] chatbot — " . ($this->chatbot->available() ? 'on (' . $this->chatbot->model() . ')' : 'off (no key)') . "\n";
        echo "[yumi] ready — owner={$this->ownerId} vc=" . ($this->vcEnabled ? 'on' : 'off') . "\n";
    }

    /** True when the imported auth key already belongs to a live bot session. */
    private function probeAuthorized(): bool
    {
        try {
            $me = $this->bot->invoke((new TlNode('users.getFullUser'))
                ->put('id', new TlNode('inputUserSelf')), 15);
        } catch (Throwable $e) {
            echo '[yumi-bot] session probe failed: ' . substr($e->getMessage(), 0, 90) . "\n";
            return false;
        }
        foreach ($me->list('users') as $u) {
            if ($u instanceof TlNode && $u->name === 'user' && $u->l('id') > 0) {
                $this->botId = (int) $u->l('id');
                echo "[yumi-bot] session restored — @{$u->str('username')} ({$u->l('id')})\n";
                if ($u->str('username') !== '') {
                    Buttons::setBotUsername($u->str('username'));
                    $this->chatbot->setUsername($u->str('username'));
                }
                return true;
            }
        }
        return false;
    }

    private function authorizeBot(): void
    {        $sessFile = dirname(__DIR__) . '/data/bot.session';
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
                            $this->botId = (int) $u->l('id');
                            echo "[yumi-bot] logged in as {$u->str('first_name')} @{$u->str('username')} ({$u->l('id')})\n";
                            if ($u->str('username') !== '') {
                                Buttons::setBotUsername($u->str('username'));
                                $this->chatbot->setUsername($u->str('username'));
                    $this->chatbot->setUsername($u->str('username'));
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
                    $this->assistantOk = true;
                    echo "[yumi-assistant] resumed as {$u->str('first_name')} @{$u->str('username')} ({$u->l('id')})\n";
                    if ($u->str('username') !== '') {
                        $this->assistantUser = $u->str('username');
                    }
                }
            }
            $this->voice->peers()->refresh();
        } catch (Throwable $e) {
            $this->assistantOk = false;
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
                ['ac', 'ᴧᴄᴛɪᴠє ᴠσɪᴄє ᴄʜᴧᴛs'], ['vcstatus', 'ᴠσɪᴄє єηɢɪηє sᴛᴧᴛυs'],
                ['settings', 'ᴄʜᴧᴛ sєᴛᴛɪηɢs'], ['id', 'ᴄʜᴧᴛ / υsєʀ ɪᴅs'],
                ['alive', 'ʙσᴛ υᴘᴛɪᴍє ᴧηᴅ ʀᴧᴍ'], ['speed', 'ᴘʟᴧʏʙᴧᴄᴋ sᴘєєᴅ 0.5–4'],
                ['move', 'ᴍσᴠє ǫᴜєᴜє ɪᴛєᴍ'], ['remove', 'ʀєᴍσᴠє ǫᴜєᴜє ɪᴛєᴍ'],
                ['auth', 'ᴧυᴛʜσʀɪᴢє ᴧ υsєʀ'], ['unauth', 'ʀєᴠσᴋє ᴧυᴛʜσʀɪᴢᴧᴛɪση'],
                ['authlist', 'ʟɪsᴛ ᴧυᴛʜσʀɪᴢєᴅ υsєʀs'], ['warn', 'ωᴧʀη ᴧ υsєʀ'],
                ['warnings', 'sʜσᴡ ωᴧʀηɪηɢs'], ['ban', 'ʙᴧη ᴧ υsєʀ'],
                ['unban', 'υηʙᴧη ᴧ υsєʀ'], ['kick', 'ᴋɪᴄᴋ ᴧ υsєʀ'],
                ['purge', 'ᴅєʟєᴛє ᴍєssᴧɢєs'], ['pin', 'ᴘɪη ᴧ ᴍєssᴧɢє'],
                ['privacy', 'ᴘʀɪᴠᴧᴄʏ ɪηғσ'], ['repo', 'sσυʀᴄє ᴄσᴅє'],
                ['save', 'sᴧᴠє ᴧ ησᴛє'], ['get', 'sʜσᴡ ᴧ ησᴛє'],
                ['notes', 'ʟɪsᴛ sᴧᴠєᴅ ησᴛєs'], ['filter', 'ᴧᴅᴅ ᴧη ᴧυᴛσ ʀєᴘʟʏ'],
                ['filters', 'ʟɪsᴛ ғɪʟᴛєʀs'], ['myplaylists', 'ʏσυʀ ᴘʟᴧʏʟɪsᴛs'],
                ['createplaylist', 'ηєω ᴘʟᴧʏʟɪsᴛ'], ['addtoplaylist', 'ᴧᴅᴅ ᴧ ᴛʀᴧᴄᴋ'],
                ['provider', 'sєᴀʀᴄʜ ʙᴧᴄᴋєηᴅ'],
                ['welcome', 'ᴛσɢɢʟє ωєʟᴄσᴍє'], ['promote', 'ᴘʀσᴍσᴛє ᴧ ᴍєᴍʙєʀ'],
                ['chatbot', 'ᴛᴧʟᴋ ᴛσ ᴛʜє ʙσᴛ ɪη ᴛєxᴛ'], ['resetbot', 'ᴄʟєᴧʀ ᴄʜᴧᴛ ᴍєᴍσʀʏ'],
                ['demote', 'ᴅєᴍσᴛє ᴧ ᴍєᴍʙєʀ'], ['cleanmode', 'ᴧυᴛσ-ᴅєʟєᴛє ᴄᴏᴍᴍᴀɴᴅs'],
                ['blacklisted', 'ɢʟσʙᴧʟ ʙʟᴧᴄᴋʟɪsᴛ'],
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
        if ($this->db->isBlockedChat($chatId)) {
            return;
        }
        $action = $msg->node('action');
        if ($action === null) {
            return;
        }
        if ($action->name === 'messageActionGroupCall') {
            if ($action->has('duration')) {
                $this->send($chatId, $this->t($chatId, 'voicechat_ended', [
                    'duration' => Util::formatTime($action->i('duration')),
                ]));
                $this->dropRoom($chatId);
            } else {
                $this->send($chatId, $this->t($chatId, 'voicechat_started'));
            }
            return;
        }
        // Welcome greeting for joins.
        $users = [];
        if ($action->name === 'messageActionChatAddUser') {
            foreach ($action->list('users') as $u) {
                $users[] = (int) $u;
            }
        } elseif ($action->name === 'messageActionChatJoinedByLink') {
            $inviter = $action->l('inviter_id');
            if ($inviter !== 0) {
                $users[] = $inviter;
            }
        }
        if ($users !== []) {
            $this->maybeWelcome($chatId, $users, $this->chatTitle($chatId));
        }
    }

    private function onBotMessage(TlNode $msg): void
    {
        $peer = $msg->node('peer_id');
        $chatId = $this->peerChatId($peer);
        $sender = $this->senderId($msg);
        $msgId = $msg->i('id');

        // Global blacklist: blocked chats are ignored, blocked users have
        // every message removed.
        if ($this->db->isBlockedChat($chatId)) {
            return;
        }
        if ($this->db->isBlockedUser($sender)) {
            $this->deleteMsgs($chatId, [$msgId]);
            return;
        }

        $text = $msg->str('message');
        if ($text === '' || $text === null) {
            return;
        }
        $cmd = strtolower(explode('@', explode(' ', trim($text))[0], 2)[0]);
        if ($cmd === '' || $cmd[0] !== '/') {
            $rt = $msg->node('reply_to');
            $this->onPlainMessage($chatId, $sender, $text, $msgId, $rt !== null ? (int) ($rt->i('reply_to_msg_id') ?? 0) : 0);
            return;
        }
        $arg = trim(preg_replace('/^\/[a-z0-9_]+(@\w+)?\s*/i', '', $text));
        Locales::setCurrentCommand($cmd);
        echo "[yumi] chat=$chatId user=$sender cmd=$cmd\n";
        if ($chatId > 0 && $this->isGroupOnly($cmd)) {
            $this->send($chatId, $this->t($chatId, 'groups_only'));
            Locales::setCurrentCommand(null);
            return;
        }
        if ($this->maint && $sender !== $this->ownerId && !in_array($cmd, ['/start', '/help', '/ping', '/alive'], true)) {
            $this->send($chatId, "🛠️ <b>𝐁‌σᴛ 𝐈‌s υηᴅєʀ 𝐌‌ᴧɪηᴛєηᴧηᴄє</b>\n\n"
                . '<blockquote>ᴘʟєᴧsє ᴛʀʏ ᴧɢᴧɪη ɪη ᴧ ғєᴡ ᴍɪηυᴛєs.</blockquote>');
            Locales::setCurrentCommand(null);
            return;
        }
        try {
            $this->dispatch($chatId, $sender, $cmd, $arg, $msg);
        } catch (Throwable $e) {
            echo "[yumi] cmd $cmd failed: " . $e->getMessage() . "\n";
            $this->send($chatId, $this->t($chatId, 'generic_error', [
                'error' => Util::escapeHtml($e->getMessage()),
            ]), $msg->i('id'));
        }
        // Clean mode removes command messages 15 s after they are handled.
        if ($this->db->cleanMode($chatId)) {
            $this->pendingDeletes[] = [$chatId, $msgId, time() + 15];
        }
        Locales::setCurrentCommand(null);
    }

    /** @var array<int, array{0:int,1:int,2:int}> [chatId, msgId, dueTs] */
    private array $pendingDeletes = [];

    private function flushPendingDeletes(int $now): void
    {
        if ($this->pendingDeletes === []) {
            return;
        }
        $keep = [];
        foreach ($this->pendingDeletes as $p) {
            if ($p[2] <= $now) {
                $this->deleteMsgs($p[0], [$p[1]]);
            } else {
                $keep[] = $p;
            }
        }
        $this->pendingDeletes = $keep;
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
                // chatFull.participants is a chatParticipants wrapper — the
                // member vector lives one level deeper. Flattening that
                // wrongly made every legacy-group admin look unauthorised.
                $participants = $full->node('full_chat')
                    ?->node('participants')
                    ?->list('participants') ?? [];
                foreach ($participants as $p) {
                    if ($p instanceof TlNode && $p->l('user_id') === $sender) {
                        return in_array($p->name, ['chatParticipantAdmin', 'chatParticipantCreator'], true);
                    }
                }
                return false;
            }
            $res = $this->bot->invoke((new TlNode('channels.getParticipant'))
                ->put('channel', $peer)
                ->put('participant', $this->botPeers->inputPeer($sender)), 20);
            $role = $res->node('participant')?->name ?? '';
            return in_array($role, ['channelParticipantAdmin', 'channelParticipantCreator'], true);
        } catch (Throwable $e) {
            echo "[yumi] admin check failed chat=$chatId user=$sender: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function mention(int $sender): string
    {
        return $this->botPeers->userMention($sender);
    }

    // ── commands ─────────────────────────────────────────────────────────────

    /**
     * The same handler is exposed under several names — channel-scoped
     * (`cplay`, `cskip`), force (`playforce`), video (`vplay`, `vcplay`) and
     * legacy spellings. Normalising here keeps one implementation per action
     * instead of a switch arm for every synonym.
     */
    private const ALIASES = [
        '/song' => '/play', '/cplay' => '/play', '/fplay' => '/play',
        '/fcplay' => '/play', '/cforceplay' => '/play', '/cfplay' => '/play',
        '/cplayforce' => '/play', '/playforce' => '/play', '/channelplay' => '/play',
        '/cvplay' => '/vplay', '/vcplay' => '/vplay', '/fvplay' => '/vplay',
        '/fvcplay' => '/vplay', '/vplayforce' => '/vplay', '/vcplayforce' => '/vplay',
        '/cskip' => '/skip', '/caskip' => '/skip', '/askip' => '/skip',
        '/cpause' => '/pause', '/cresume' => '/resume', '/cstop' => '/stop',
        '/cend' => '/stop', '/cseek' => '/seek', '/cseekback' => '/seekback',
        '/cloop' => '/loop', '/cshuffle' => '/shuffle', '/cspeed' => '/speed',
        '/csetspeed' => '/setspeed', '/cspeedup' => '/speedup', '/cjump' => '/jump',
        '/cmove' => '/move', '/cremove' => '/remove',
        '/cqueue' => '/queue', '/cpos' => '/position', '/cposition' => '/position',
        '/creplay' => '/replay', '/cmute' => '/mute', '/cunmute' => '/unmute',
        '/creload' => '/reload', '/activevoice' => '/ac', '/activevc' => '/ac',
        '/active' => '/ac', '/vcstats' => '/vcstatus', '/nothumbs' => '/nothumb',
        '/language' => '/lang', '/source' => '/repo', '/sudoadd' => '/addsudo',
        '/gcast' => '/bcast', '/bcast' => '/broadcast',
        '/maintenance' => '/maint', '/setloop' => '/loop', '/pos' => '/position',
        '/plistinfo' => '/playlistinfo', '/whitelistuser' => '/unblockuser',
    ];

    private function dispatch(int $chatId, int $sender, string $cmd, string $arg, TlNode $msg): void
    {
        $cmd = self::ALIASES[$cmd] ?? $cmd;
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
                // volume is server-side in ntgcalls — map to pause/resume
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
                // Stop confirm card: paused → resume-or-stop
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
                $this->cmdNoteDelete($chatId, $arg);
                return;
            case '/cclear':
            case '/clearqueue':
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
                $this->voice?->ntg->stop($chatId);
                $err = $this->voice?->playFile($chatId, $r->path, (bool) ($r->track['video'] ?? false), 0, $r->speed);
                if ($err !== '') {
                    $this->send($chatId, $this->t($chatId, 'play_failed', ['error' => Util::escapeHtml($err)]));
                    return;
                }
                $r->position = 0;
                $r->endStreak = 0;
                $r->paused = false;
                $this->send($chatId, $this->t($chatId, 'cb_replaying'), 0, $this->playMarkup($r));
                return;
            case '/chatbot':
                if ($chatId > 0) {
                    $this->send($chatId, $this->t($chatId, 'chatbot_groups_only'));
                    return;
                }
                if (!$this->isAdmin($chatId, $sender) && $sender !== $this->ownerId) {
                    $this->send($chatId, $this->t($chatId, 'chatbot_admin_only'));
                    return;
                }
                $arg = strtolower(trim($arg));
                $on = !$this->chatbot->enabled($chatId);
                if (in_array($arg, ['on', 'enable', '1', 'true'], true)) {
                    $on = true;
                } elseif (in_array($arg, ['off', 'disable', '0', 'false'], true)) {
                    $on = false;
                } elseif ($arg !== '' && $arg !== 'toggle') {
                    $this->send($chatId, $this->t($chatId, 'chatbot_usage'));
                    return;
                }
                $this->chatbot->setEnabled($chatId, $on);
                if ($on && !$this->chatbot->available()) {
                    $this->send($chatId, $this->t($chatId, 'chatbot_no_key'));
                    return;
                }
                $this->send($chatId, $this->t($chatId, $on ? 'chatbot_on' : 'chatbot_off'));
                return;
            case '/gchatbot':
                if ($sender !== $this->ownerId) {
                    $this->send($chatId, $this->t($chatId, 'chatbot_admin_only'));
                    return;
                }
                $on = !$this->chatbot->globalOn();
                $this->chatbot->setGlobal($on);
                if ($on && !$this->chatbot->available()) {
                    $this->send($chatId, $this->t($chatId, 'chatbot_no_key'));
                    return;
                }
                $this->send($chatId, $this->t($chatId, $on ? 'chatbot_global_on' : 'chatbot_global_off'));
                return;
            case '/resetbot':
            case '/clearchat':
                $this->chatbot->reset($chatId < 0 ? $chatId : $sender);
                $this->send($chatId, $this->t($chatId, 'chatbot_reset'));
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

            // ── system / info ────────────────────────────────────────────────
            case '/ac':
            case '/active':
            case '/activevc':
            case '/activevoice':
                $this->cmdActiveVc($chatId, $sender);
                return;
            case '/vcstatus':
            case '/vcstats':
                $this->cmdVcStatus($chatId);
                return;
            case '/id':
                $this->cmdId($chatId, $sender, $msg);
                return;
            case '/alive':
                $this->cmdAlive($chatId);
                return;
            case '/repo':
            case '/source':
                $this->send($chatId, $this->t($chatId, 'repo_text'),
                    0, Buttons::repoMarkup($this->loc, $this->lang($chatId), $this->ownerId));
                return;
            case '/privacy':
                $this->send($chatId, "<b>🔒 𝐏‌ʀɪᴠᴧᴄʏ</b>\n\n<blockquote>"
                    . '𝐘‌υϻɪ 𝘅 ϻ‌υsɪᴄ sᴛσʀєs ᴄʜᴧᴛ sєᴛᴛɪηɢs, ᴘʟᴧʏʟɪsᴛs, ωᴧʀηɪηɢs ᴧηᴅ ησᴛєs ɪη '
                    . 'ᴀ sᴍᴧʟʟ ʟσᴄᴧʟ ᴅᴧᴛᴧʙᴧsє. 𝐍‌σ ᴍєssᴧɢє ᴄσηᴛєηᴛ ɪs ᴘєʀsɪsᴛєᴅ. '
                    . 'sᴛʀєᴧᴍs ᴧʀє ʀєsσʟᴠєᴅ ση ᴅєᴍᴧηᴅ ᴧηᴅ ᴄᴧᴄʜєᴅ ᴛєᴍᴘσʀᴧʀɪʟʏ ғσʀ ᴘʟᴧʏʙᴧᴄᴋ.'
                    . '</blockquote>');
                return;
            case '/settings':
                $this->cmdSettings($chatId);
                return;
            case '/speed':
            case '/setspeed':
            case '/cspeed':
            case '/csetspeed':
            case '/speedup':
            case '/cspeedup':
                $this->cmdSpeed($chatId, $sender, $arg);
                return;
            case '/move':
            case '/cmove':
                $this->cmdMove($chatId, $arg);
                return;
            case '/remove':
            case '/cremove':
                $this->cmdRemove($chatId, $arg);
                return;

            // ── authorization ────────────────────────────────────────────────
            case '/auth':
            case '/addauth':
            case '/authorize':
                $this->cmdAuth($chatId, $sender, $arg, $msg, true);
                return;
            case '/unauth':
            case '/delauth':
            case '/delauthuser':
                $this->cmdAuth($chatId, $sender, $arg, $msg, false);
                return;
            case '/authlist':
            case '/authusers':
                $this->cmdAuthList($chatId);
                return;

            // ── global sudo ──────────────────────────────────────────────────
            case '/addsudo':
            case '/addsudoer':
            case '/sudoadd':
                $this->cmdSudo($chatId, $sender, $arg, $msg, true);
                return;
            case '/delsudo':
            case '/delsudoer':
            case '/rmsudo':
            case '/remsudo':
            case '/sudodel':
            case '/sudorem':
            case '/dropsudo':
                $this->cmdSudo($chatId, $sender, $arg, $msg, false);
                return;
            case '/sudo':
            case '/sudoers':
            case '/sudolist':
            case '/listsudo':
                $this->cmdSudoList($chatId);
                return;

            // ── moderation ───────────────────────────────────────────────────
            case '/ban':
                $this->cmdBan($chatId, $sender, $arg, $msg, 'ban');
                return;
            case '/unban':
                $this->cmdBan($chatId, $sender, $arg, $msg, 'unban');
                return;
            case '/blockuser':
            case '/blacklistuser':
            case '/bluser':
            case '/blackuser':
                $this->cmdBlockUser($chatId, $sender, $arg, $msg, true);
                return;
            case '/unblockuser':
            case '/unblacklistuser':
            case '/unbluser':
            case '/whitelistuser':
                $this->cmdBlockUser($chatId, $sender, $arg, $msg, false);
                return;
            case '/kick':
                $this->cmdBan($chatId, $sender, $arg, $msg, 'kick');
                return;
            case '/purge':
                $this->cmdPurge($chatId, $sender, $msg);
                return;
            case '/pin':
                $this->cmdPin($chatId, $sender, $msg, false);
                return;
            case '/unpin':
                $this->cmdPin($chatId, $sender, $msg, true);
                return;
            case '/warn':
                $this->cmdWarn($chatId, $sender, $arg, $msg);
                return;
            case '/warnings':
            case '/warns':
                $this->cmdWarnings($chatId, $sender, $arg, $msg);
                return;
            case '/resetwarns':
            case '/clearwarn':
            case '/unwarn':
                $this->cmdResetWarns($chatId, $sender, $arg, $msg);
                return;
            case '/setwarnlimit':
            case '/warnlimit':
                $this->cmdSetWarnLimit($chatId, $sender, $arg);
                return;
            case '/setwarnmode':
                $this->cmdSetWarnMode($chatId, $sender, $arg);
                return;

            // ── chat settings ────────────────────────────────────────────────
            case '/playmode':
                $this->cmdPlayMode($chatId, $sender, $arg);
                return;
            case '/adminmode':
                $this->cmdAdminMode($chatId, $sender, $arg);
                return;
            case '/nothumb':
                $this->cmdNoThumb($chatId, $sender);
                return;
            case '/autoleave':
                $this->cmdAutoLeave($chatId, $sender, $arg);
                return;
            case '/setcplay':
                $this->cmdSetCPlay($chatId, $sender, $arg);
                return;
            case '/reload':
                $this->cmdReload($chatId, $sender);
                return;
            case '/provider':
            case '/providers':
                $this->cmdProvider($chatId, $sender, $arg);
                return;
            // ── notes ────────────────────────────────────────────────────────
            case '/save':
                $this->cmdNoteSave($chatId, $arg);
                return;
            case '/get':
                $this->cmdNoteGet($chatId, $arg);
                return;
            case '/notes':
                $this->cmdNoteList($chatId);
                return;
            case '/clearnote':
                $this->cmdNoteDelete($chatId, $arg);
                return;

            // ── filters ──────────────────────────────────────────────────────
            case '/filter':
                $this->cmdFilterSave($chatId, $sender, $arg);
                return;
            case '/filters':
                $this->cmdFilterList($chatId);
                return;
            case '/stopfilter':
                $this->cmdFilterStop($chatId, $sender, $arg);
                return;
            case '/clearfilters':
                $this->cmdFilterClear($chatId, $sender);
                return;

            // ── playlists ────────────────────────────────────────────────────
            case '/createplaylist':
                $this->cmdPlaylistCreate($chatId, $sender, $arg);
                return;
            case '/deleteplaylist':
                $this->cmdPlaylistDelete($chatId, $sender, $arg);
                return;
            case '/addtoplaylist':
                $this->cmdPlaylistAdd($chatId, $sender, $arg);
                return;
            case '/removefromplaylist':
                $this->cmdPlaylistRemove($chatId, $sender, $arg);
                return;
            case '/playlistinfo':
            case '/plistinfo':
                $this->cmdPlaylistInfo($chatId, $sender, $arg);
                return;
            case '/myplaylists':
                $this->cmdPlaylistList($chatId, $sender);
                return;

            // ── admin: promote / welcome / clean mode / logger ───────────────
            case '/promote':
            case '/fullpromote':
                $this->cmdPromote($chatId, $sender, $arg, $msg, false);
                return;
            case '/demote':
            case '/unpromote':
                $this->cmdPromote($chatId, $sender, $arg, $msg, true);
                return;
            case '/welcome':
                $this->cmdWelcome($chatId, $sender, $arg);
                return;
            case '/cleanmode':
            case '/cmddelete':
                $this->cmdCleanMode($chatId, $sender, $arg);
                return;
            case '/logger':
            case '/logs':
            case '/log':
            case '/vclog':
            case '/vclogger':
                $this->cmdLogger($chatId, $sender, $arg);
                return;

            // ── global blacklist ─────────────────────────────────────────────
            case '/blockchat':
            case '/blackchat':
            case '/blchat':
            case '/blacklistchat':
                $this->cmdBlockChat($chatId, $sender, true);
                return;
            case '/unblockchat':
            case '/whitechat':
            case '/unblchat':
            case '/unblacklistchat':
                $this->cmdBlockChat($chatId, $sender, false);
                return;
            case '/blacklisted':
            case '/blocked':
                $this->cmdBlacklisted($chatId);
                return;
            case '/bug':
                $this->cmdBug($chatId, $sender, $arg);
                return;
            case '/unpinall':
                $this->cmdUnpinAll($chatId, $sender);
                return;

            // ── owner tools ──────────────────────────────────────────────────
            case '/broadcast':
            case '/bcast':
            case '/gcast':
                $this->cmdBroadcast($chatId, $sender, $arg);
                return;
            case '/maint':
            case '/maintenance':
                if ($sender !== $this->ownerId) {
                    $this->send($chatId, $this->t($chatId, 'only_admin'));
                    return;
                }
                $this->maint = !$this->maint;
                $this->send($chatId, $this->maint
                    ? '🛠️ <b>𝐌‌ᴧɪηᴛєηᴧηᴄє 𝐌‌σᴅє 𝐎‌η</b>'
                    : '✅ <b>𝐌‌ᴧɪηᴛєηᴧηᴄє 𝐌‌σᴅє 𝐎‌ғғ</b>');
                return;
            case '/restart':
                if ($sender !== $this->ownerId) {
                    $this->send($chatId, $this->t($chatId, 'only_admin'));
                    return;
                }
                $this->send($chatId, '♻️ <b>𝐑‌єsᴛᴧʀᴛɪηɢ...</b>');
                sleep(1);
                exit(0);
        }
    }

    // ── system / info commands ───────────────────────────────────────────────

    private function cmdActiveVc(int $chatId, int $sender): void
    {
        if ($chatId > 0 && $sender !== $this->ownerId) {
            $this->send($chatId, $this->t($chatId, 'groups_only'));
            return;
        }
        if ($this->rooms === []) {
            $this->send($chatId, $this->t($chatId, 'no_active_vc'));
            return;
        }
        $out = '<b>🎤 𝐀‌ᴄᴛɪᴠє 𝐕‌σɪᴄє 𝐂‌ʜᴧᴛs — ' . count($this->rooms) . '</b>';
        foreach ($this->rooms as $cid => $r) {
            $title = $r->track === null ? '—' : (string) ($r->track['title'] ?? '—');
            $out .= "\n\n<blockquote>❖ <b>𝐂‌ʜᴧᴛ:</b> <code>" . $cid . "</code>\n"
                . '➥ <b>𝐍‌σᴡ 𝐏‌ʟᴧʏɪηɢ:</b> ' . Util::escapeHtml(Util::shortTitle($title, 30)) . "\n"
                . '➥ <b>𝐐‌υєυє:</b> ' . count($r->queue) . ' 𝐓‌ꝛᴧᴄᴋs ♬</blockquote>';
        }
        $this->send($chatId, $out);
    }

    private function cmdVcStatus(int $chatId): void
    {
        $ready = isset($this->voice) && $this->voice->mt->connected();
        $out = "<b>⚙️ 𝐕‌σɪᴄє 𝐄‌ηɢɪηє 𝐒‌ᴛᴧᴛυs</b>\n\n<blockquote>"
            . '❖ <b>𝐀‌ssɪsᴛᴧηᴛs:</b> <code>' . (isset($this->voice) ? 1 : 0) . "</code>\n"
            . '❖ <b>𝐀‌ᴄᴛɪᴠє 𝐑‌σσᴍs:</b> <code>' . count($this->rooms) . "</code>\n"
            . '➥ #1 ' . ($this->assistantUser !== '' ? '@' . $this->assistantUser : 'ᴀssɪsᴛᴧηᴛ')
            . ' — ' . ($ready ? '✅ ʀєᴧᴅʏ' : '⚠️ σғғʟɪηє') . "\n"
            . '❖ <b>ηᴛɢᴄᴧʟʟs:</b> <code>' . $this->ntgVer . "</code>\n"
            . '❖ <b>υᴘᴛɪᴍє:</b> <code>' . Util::formatUptime(time() - $this->started) . '</code>'
            . '</blockquote>';
        $this->send($chatId, $out);
    }

    private function cmdId(int $chatId, int $sender, TlNode $msg): void
    {
        $out = '<b>𝐂‌ʜᴧᴛ:</b> <code>' . $chatId . "</code>\n"
            . '<b>𝐔‌sєꝛ:</b> <code>' . $sender . "</code>\n"
            . '<b>𝐌‌єssᴧɢє:</b> <code>' . $msg->i('id') . '</code>';
        $this->send($chatId, $out);
    }

    private function cmdAlive(int $chatId): void
    {
        $out = "<b>💚 𝐘‌υϻɪ 𝘅 ϻ‌υsɪᴄ ɪs ᴧʟɪᴠє</b>\n\n<blockquote>"
            . '❖ <b>υᴘᴛɪᴍє:</b> <code>' . Util::formatUptime(time() - $this->started) . "</code>\n"
            . '❖ <b>𝐑‌ᴧᴍ:</b> <code>' . Util::ramInfo() . "</code>\n"
            . '❖ <b>𝐂‌ᴘυ:</b> <code>' . Util::cpuInfo() . "</code>\n"
            . '❖ <b>𝐀‌ᴄᴛɪᴠє 𝐑‌σσᴍs:</b> <code>' . count($this->rooms) . '</code>'
            . '</blockquote>';
        $this->send($chatId, $out);
    }

    private function cmdSettings(int $chatId): void
    {
        $lang = $this->lang($chatId);
        $this->send($chatId, $this->t($chatId, 'settings_main', [
            'title' => Util::escapeHtml($chatId > 0 ? 'ᴘʀɪᴠᴧᴛє ᴄʜᴧᴛ' : (string) $chatId),
            'id' => (string) $chatId,
        ]), 0, Buttons::settingsKeyboard($this->loc, $lang, $this->db, $chatId));
    }

    // ── chat settings ────────────────────────────────────────────────────────

    private function canManageSettings(int $chatId, int $sender): bool
    {
        if ($chatId > 0) {
            return true;
        }
        return $this->isAdmin($chatId, $sender) || $this->db->isAuth($chatId, $sender);
    }

    private function cmdPlayMode(int $chatId, int $sender, string $arg): void
    {
        if (!$this->canManageSettings($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $arg = strtolower(trim($arg));
        if ($arg === '') {
            $admins = $this->db->playMode($chatId) === 'admin';
            $this->send($chatId, $this->loc->f($this->lang($chatId), 'playmode_help', 'status',
                $this->loc->f($this->lang($chatId), $admins ? 'playmode_status_admins' : 'playmode_status_everyone')));
            return;
        }
        $admins = in_array($arg, ['admin', 'admins', 'on', 'enable', 'enabled'], true);
        $this->db->setPlayMode($chatId, $admins ? 'admin' : 'everyone');
        $this->send($chatId, $this->loc->f($this->lang($chatId),
            $admins ? 'playmode_success_admins' : 'playmode_success_everyone'));
    }

    private function cmdAdminMode(int $chatId, int $sender, string $arg): void
    {
        if (!$this->canManageSettings($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $arg = strtolower(trim($arg));
        $on = in_array($arg, ['admin', 'admins', 'on', 'enable', 'enabled'], true);
        if ($arg === '') {
            $on = !$this->db->adminMode($chatId);
        }
        $this->db->setAdminMode($chatId, $on);
        $this->send($chatId, $this->loc->f($this->lang($chatId), 'adminmode_updated', 'status',
            $this->loc->f($this->lang($chatId), $on ? 'adminmode_status_admin' : 'adminmode_status_everyone')));
    }

    private function cmdNoThumb(int $chatId, int $sender): void
    {
        if (!$this->canManageSettings($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $cur = $this->db->thumbnailsDisabled($chatId);
        $this->db->setThumbnailsDisabled($chatId, !$cur);
        $this->send($chatId, $this->loc->f($this->lang($chatId), 'nothumb_updated', 'action',
            $this->loc->f($this->lang($chatId), !$cur ? 'disabled' : 'enabled')));
    }

    private function cmdAutoLeave(int $chatId, int $sender, string $arg): void
    {
        if ($sender !== $this->ownerId) {
            $this->send($chatId, $this->t($chatId, 'only_sudo'));
            return;
        }
        $arg = strtolower(trim($arg));
        if ($arg !== '') {
            $this->db->setAutoLeave($chatId, in_array($arg, ['on', 'enable', 'enabled', 'yes'], true));
        }
        $this->send($chatId, $this->loc->f($this->lang($chatId), 'autoleave_status', 'status',
            $this->db->autoLeave($chatId) ? 'on' : 'off'));
    }

    private function cmdSetCPlay(int $chatId, int $sender, string $arg): void
    {
        if ($sender !== $this->ownerId) {
            $this->send($chatId, $this->t($chatId, 'cplay_owner_required'));
            return;
        }
        $lang = $this->lang($chatId);
        $arg = trim($arg);
        if ($arg === '') {
            $linked = $this->db->linkedChannel($chatId);
            $this->send($chatId, $linked === 0
                ? $this->loc->get($lang, 'cplay_id_not_set')
                : $this->loc->f($lang, 'cplay_enabled', 'chat', (string) $linked));
            return;
        }
        if (in_array(strtolower($arg), ['off', 'disable', 'none', '0'], true)) {
            $this->db->setLinkedChannel($chatId, 0);
            $this->send($chatId, $this->loc->get($lang, 'cplay_disabled'));
            return;
        }
        $id = ltrim($arg, '-');
        if (!ctype_digit($id)) {
            $this->send($chatId, $this->loc->get($lang, 'cplay_invalid_target'));
            return;
        }
        $this->db->setLinkedChannel($chatId, (int) $arg);
        $this->send($chatId, $this->loc->f($lang, 'cplay_enabled', 'chat', (string) (int) $arg));
    }

    private function cmdReload(int $chatId, int $sender): void
    {
        if (!$this->canManageSettings($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        // Drop cached peer/admin lookups so the next command re-reads them.
        $this->adminCache = [];
        $this->send($chatId, '🔄 <b>𝐀‌ᴅᴍɪη 𝐂‌ᴧᴄʜє 𝐑‌єʟσᴧᴅєᴅ</b>');
    }

    private function cmdProvider(int $chatId, int $sender, string $arg): void
    {
        if (!$this->canManageSettings($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $arg = strtolower(trim($arg));
        if ($arg !== '' && in_array($arg, ['auto', 'iota', 'ritesh'], true)) {
            $this->db->setProvider($arg);
        } elseif ($arg !== '') {
            $this->send($chatId, '⚠️ <b>𝐔‌sᴧɢє</b>: <code>/provider auto|iota|ritesh</code>');
            return;
        }

        $cur = $this->db->provider();
        $mark = fn(string $p): string => $p === $cur ? '●' : '○';
        $this->send($chatId, '🔌 <b>𝐃‌σωηʟσᴧᴅ 𝐏‌ʀσᴠɪᴅєʀs</b>'
            . "\n\n<blockquote>"
            . $mark('auto') . " <b>ᴀυᴛσ</b> — ɪσᴛᴧ ғɪʀsᴛ, ʀɪᴛєsʜ ғᴧʟʟʙᴧᴄᴋ"
            . "\n" . $mark('iota') . " <b>ɪσᴛᴧ-ᴧᴘɪ</b> — " . ($this->iota->baseUrl() !== '' ? 'ᴄσηηєᴄᴛєᴅ' : 'ησᴛ sєᴛ')
            . "\n" . $mark('ritesh') . " <b>ʀɪᴛєsʜ-ᴧᴘɪ</b> — "
            . ($this->ritesh->enabled() ? 'ᴄσηηєᴄᴛєᴅ' : 'ᴅɪsᴧʙʟєᴅ')
            . "\n\n➥ ᴄυʀʀєηᴛ: <b>" . strtoupper($cur) . "</b></blockquote>");
    }

    private function cmdBug(int $chatId, int $sender, string $arg): void
    {
        $arg = trim($arg);
        if ($arg === '') {
            $this->send($chatId, '🐞 <b>𝐁‌υɢ 𝐑‌єᴘσʀᴛ</b>'
                . "\n\n<blockquote>➥ υsє: <code>/bug &lt;ʏσυʀ ʀєᴘσʀᴛ&gt;</code></blockquote>");
            return;
        }
        $this->send($this->ownerId, '🐞 <b>𝐍‌єω 𝐁‌υɢ 𝐑‌єᴘσʀᴛ</b>'
            . "\n\n<blockquote>❖ <b>𝐅‌ʀσᴍ:</b> " . $this->mention($sender)
            . "\n❖ <b>𝐂‌ʜᴧᴛ:</b> <code>" . $chatId . "</code>"
            . "\n➥ <b>𝐑‌єᴘσʀᴛ:</b> " . Util::escapeHtml($arg) . '</blockquote>');
        $this->send($chatId, '✅ <b>𝐑‌єᴘσʀᴛ 𝐒‌єηᴛ</b>'
            . "\n\n<blockquote>➥ ᴛʜᴧηᴋs! ᴛʜє σᴡηєʀ ωɪʟʟ ʀєᴠɪєω ɪᴛ.</blockquote>");
    }

    private function cmdUnpinAll(int $chatId, int $sender): void
    {
        if ($chatId > 0) {
            $this->send($chatId, $this->t($chatId, 'groups_only'));
            return;
        }
        if (!$this->isAdmin($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        try {
            $this->bot->invoke((new TlNode('messages.unpinAllMessages'))
                ->put('peer', $this->botPeers->inputPeer($chatId)), 20);
            $this->send($chatId, '📌 <b>𝐀‌ʟʟ 𝐏‌ɪηs 𝐑‌єᴍσᴠєᴅ</b>');
        } catch (Throwable $e) {
            $this->send($chatId, $this->t($chatId, 'generic_error', ['error' => Util::escapeHtml($e->getMessage())]));
        }
    }

    private function cmdSpeed(int $chatId, int $sender, string $arg): void
    {
        $r = $this->rooms[$chatId] ?? null;
        if ($arg === '') {
            $cur = $r !== null ? $r->speed : $this->db->speed($chatId);
            $this->send($chatId, $this->t($chatId, 'speed_current', ['speed' => number_format($cur, 2)]));
            return;
        }
        if (!is_numeric($arg) || (float) $arg < 0.5 || (float) $arg > 4.0) {
            Locales::setCurrentCommand('/speed');
            $this->send($chatId, $this->t($chatId, 'speed_invalid'));
            return;
        }
        $sp = round((float) $arg, 2);
        $this->db->setSpeed($chatId, $sp);
        if ($r === null || $r->track === null || $r->path === '') {
            $this->send($chatId, $this->t($chatId, 'speed_set', ['speed' => number_format($sp, 2)]));
            return;
        }
        $r->speed = $sp;
        $err = $this->seekTo($chatId, $r, $r->position);
        if ($err !== '') {
            $this->send($chatId, $this->t($chatId, 'play_failed', ['error' => Util::escapeHtml($err)]));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'speed_set', ['speed' => number_format($sp, 2)]), 0, $this->playMarkup($r));
    }

    private function cmdMove(int $chatId, string $arg): void
    {
        $r = $this->rooms[$chatId] ?? null;
        if ($r === null || $r->queue === []) {
            $this->send($chatId, $this->t($chatId, 'queue_empty'));
            return;
        }
        if (!preg_match('~^(\d+)\s+(\d+)$~', $arg, $m)) {
            Locales::setCurrentCommand('/move');
            $this->send($chatId, $this->t($chatId, 'move_usage'));
            return;
        }
        $from = (int) $m[1] - 1;
        $to = (int) $m[2] - 1;
        $n = count($r->queue);
        if ($from < 0 || $from >= $n || $to < 0 || $to >= $n) {
            $this->send($chatId, $this->t($chatId, 'move_invalid', ['count' => (string) $n]));
            return;
        }
        $item = $r->queue[$from];
        array_splice($r->queue, $from, 1);
        array_splice($r->queue, $to, 0, [$item]);
        $this->send($chatId, $this->t($chatId, 'move_success', [
            'title' => Util::escapeHtml(Util::shortTitle((string) ($item['title'] ?? '-'), 30)),
            'from' => (string) ($from + 1), 'to' => (string) ($to + 1),
        ]), 0, $this->playMarkup($r, true));
    }

    private function cmdRemove(int $chatId, string $arg): void
    {
        $r = $this->rooms[$chatId] ?? null;
        if ($r === null || $r->queue === []) {
            $this->send($chatId, $this->t($chatId, 'queue_empty'));
            return;
        }
        if (!ctype_digit($arg)) {
            Locales::setCurrentCommand('/remove');
            $this->send($chatId, $this->t($chatId, 'remove_usage'));
            return;
        }
        $i = (int) $arg - 1;
        if (!isset($r->queue[$i])) {
            $this->send($chatId, $this->t($chatId, 'remove_invalid', ['count' => (string) count($r->queue)]));
            return;
        }
        $item = $r->queue[$i];
        array_splice($r->queue, $i, 1);
        $this->send($chatId, $this->t($chatId, 'remove_success', [
            'title' => Util::escapeHtml(Util::shortTitle((string) ($item['title'] ?? '-'), 30)),
        ]), 0, $this->playMarkup($r, true));
    }

    // ── authorization ────────────────────────────────────────────────────────

    /** Resolve a target user from reply, numeric id or @username. */
    private function targetUser(TlNode $msg, string $arg): int
    {
        $arg = trim($arg);
        if ($arg !== '') {
            $arg = preg_replace('~^@~', '', $arg) ?? $arg;
            if (ctype_digit($arg)) {
                return (int) $arg;
            }
            try {
                $res = $this->bot->invoke((new TlNode('contacts.resolveUsername'))
                    ->put('username', $arg), 20);
                $this->botPeers->learnFrom($res);
                foreach ($res->list('users') as $u) {
                    if ($u instanceof TlNode) {
                        return (int) $u->l('id');
                    }
                }
            } catch (Throwable $e) {
                echo "[yumi] resolve @$arg failed: " . $e->getMessage() . "\n";
            }
            return 0;
        }
        $rid = $msg->node('reply_to')?->l('reply_to_msg_id') ?? 0;
        if ($rid === 0) {
            return 0;
        }
        try {
            $res = $this->bot->invoke((new TlNode('messages.getMessages'))
                ->put('id', [(new TlNode('inputMessageReplyTo'))->put('id', $rid)]), 20);
            $this->botPeers->learnFrom($res);
            foreach ($res->list('messages') as $m) {
                if (!$m instanceof TlNode) {
                    continue;
                }
                $from = $m->node('from_id');
                if ($from !== null && $from->name === 'peerUser') {
                    return (int) $from->l('user_id');
                }
            }
        } catch (Throwable $e) {
            echo "[yumi] reply target failed: " . $e->getMessage() . "\n";
        }
        return 0;
    }

    private function cmdAuth(int $chatId, int $sender, string $arg, TlNode $msg, bool $add): void
    {
        if (!$this->isAdmin($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin_or_auth'));
            return;
        }
        $uid = $this->targetUser($msg, $arg);
        if ($uid === 0) {
            $this->send($chatId, $this->t($chatId, 'auth_no_user'));
            return;
        }
        if ($add) {
            if ($uid === $sender || $uid === $this->ownerId) {
                $this->send($chatId, $this->t($chatId, 'cannot_authorize_user'));
                return;
            }
            if (!$this->db->addAuth($chatId, $uid)) {
                $this->send($chatId, $this->t($chatId, 'already_authed'));
                return;
            }
            $this->send($chatId, $this->t($chatId, 'addauth_success_with_count', [
                'user' => $this->mention($uid),
                'count' => (string) count($this->db->authUsers($chatId)),
                'limit' => '∞',
            ]));
            return;
        }
        if (!$this->db->delAuth($chatId, $uid)) {
            $this->send($chatId, $this->t($chatId, 'del_auth_not_authorized'));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'del_auth_success', ['user' => $this->mention($uid)]));
    }

    private function cmdAuthList(int $chatId): void
    {
        $users = $this->db->authUsers($chatId);
        if ($users === []) {
            $this->send($chatId, $this->t($chatId, 'authlist_empty'));
            return;
        }
        $out = $this->t($chatId, 'authlist_header');
        $i = 1;
        foreach ($users as $u) {
            $out .= "\n" . $this->t($chatId, 'authlist_user_entry', [
                'index' => (string) $i++,
                'user' => $this->mention($u),
                'user_id' => (string) $u,
            ]);
        }
        $out .= "\n" . $this->t($chatId, 'authlist_total', ['count' => (string) count($users)]);
        $this->send($chatId, $out);
    }

    // ── moderation ───────────────────────────────────────────────────────────

    private function canManage(int $chatId, int $sender): bool
    {
        return $chatId < 0 && $this->isAdmin($chatId, $sender);
    }

    private function cmdBan(int $chatId, int $sender, string $arg, TlNode $msg, string $mode): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $uid = $this->targetUser($msg, $arg);
        if ($uid === 0) {
            $this->send($chatId, $this->t($chatId, 'user_extract_fail', ['error' => 'ησ ᴛᴧʀɢєᴛ']));
            return;
        }
        try {
            $peer = $this->botPeers->inputPeer($chatId);
            $user = $this->botPeers->inputPeer($uid);
            if ($peer->name === 'inputPeerChannel') {
                $rights = (new TlNode('chatBannedRights'))
                    ->put('until_date', 0)
                    ->put('view_messages', true)
                    ->put('send_messages', true)
                    ->put('send_media', true)
                    ->put('send_stickers', true)
                    ->put('send_gifs', true)
                    ->put('send_games', true)
                    ->put('send_inline', true)
                    ->put('embed_links', true);
                if ($mode === 'unban') {
                    $rights = (new TlNode('chatBannedRights'))->put('until_date', 0);
                }
                $this->bot->invoke((new TlNode('channels.editBanned'))
                    ->put('channel', $peer)
                    ->put('participant', $user)
                    ->put('banned_rights', $rights), 20);
            } else {
                $gid = -$chatId;
                $this->bot->invoke((new TlNode('messages.deleteChatUser'))
                    ->put('chat_id', $gid)
                    ->put('user_id', (new TlNode('inputUser'))
                        ->put('user_id', $uid)
                        ->put('access_hash', $user->l('access_hash')))
                    ->put('revoke_history', true), 20);
            }
        } catch (Throwable $e) {
            $this->send($chatId, $this->t($chatId, 'generic_error', ['error' => Util::escapeHtml($e->getMessage())]));
            return;
        }
        if ($mode === 'kick') {
            try {
                if ($peer->name === 'inputPeerChannel') {
                    $this->bot->invoke((new TlNode('channels.editBanned'))
                        ->put('channel', $peer)
                        ->put('participant', $user)
                        ->put('banned_rights', (new TlNode('chatBannedRights'))->put('until_date', 0)), 20);
                }
            } catch (Throwable $ignored) {
            }
            $this->send($chatId, $this->t($chatId, 'kick_success', ['user' => $this->mention($uid)]));
            return;
        }
        $key = $mode === 'unban' ? 'unban_success' : 'ban_success';
        $this->send($chatId, $this->t($chatId, $key, ['user' => $this->mention($uid)]));
    }

    private function cmdPurge(int $chatId, int $sender, TlNode $msg): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $rid = $msg->node('reply_to')?->l('reply_to_msg_id') ?? 0;
        if ($rid === 0) {
            $this->send($chatId, $this->t($chatId, 'purge_usage'));
            return;
        }
        $cur = $msg->i('id');
        $ids = [];
        for ($i = $rid; $i <= $cur && count($ids) < 100; $i++) {
            $ids[] = $i;
        }
        try {
            $this->bot->invoke((new TlNode('messages.deleteMessages'))
                ->put('id', $ids)
                ->put('revoke', true), 25);
        } catch (Throwable $e) {
            $this->send($chatId, $this->t($chatId, 'generic_error', ['error' => Util::escapeHtml($e->getMessage())]));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'purge_success', ['count' => (string) count($ids)]));
    }

    private function cmdPin(int $chatId, int $sender, TlNode $msg, bool $unpin): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $rid = $msg->node('reply_to')?->l('reply_to_msg_id') ?? 0;
        if ($rid === 0) {
            $this->send($chatId, $this->t($chatId, 'pin_usage'));
            return;
        }
        try {
            $this->bot->invoke((new TlNode('messages.updatePinnedMessage'))
                ->put('peer', $this->botPeers->inputPeer($chatId))
                ->put('id', $rid)
                ->put('unpin', $unpin), 20);
        } catch (Throwable $e) {
            $this->send($chatId, $this->t($chatId, 'generic_error', ['error' => Util::escapeHtml($e->getMessage())]));
            return;
        }
        $this->send($chatId, $this->t($chatId, $unpin ? 'unpin_success' : 'pin_success'));
    }

    // ── warnings ─────────────────────────────────────────────────────────────

    private function cmdWarn(int $chatId, int $sender, string $arg, TlNode $msg): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $uid = $this->targetUser($msg, '');
        if ($uid === 0) {
            $this->send($chatId, $this->t($chatId, 'auth_no_user'));
            return;
        }
        if ($uid === $sender || $uid === $this->ownerId) {
            $this->send($chatId, $this->t($chatId, 'cannot_authorize_user'));
            return;
        }
        $count = $this->db->addWarn($chatId, $uid, $arg);
        $limit = $this->db->warnLimit($chatId);
        $this->send($chatId, $this->t($chatId, 'warn_success', [
            'user' => $this->mention($uid),
            'count' => (string) $count,
            'limit' => (string) $limit,
        ]));
        if ($count >= $limit) {
            $mode = $this->db->warnMode($chatId);
            $this->db->resetWarns($chatId, $uid);
            $this->applyWarnAction($chatId, $uid, $mode);
            $this->send($chatId, $this->t($chatId, 'warn_limit_action', [
                'user' => $this->mention($uid),
                'mode' => strtoupper($mode),
            ]));
        }
    }

    private function applyWarnAction(int $chatId, int $uid, string $mode): void
    {
        if ($mode === 'mute') {
            $mode = 'ban';
        }
        try {
            $peer = $this->botPeers->inputPeer($chatId);
            $user = $this->botPeers->inputPeer($uid);
            if ($peer->name === 'inputPeerChannel') {
                $rights = $mode === 'kick'
                    ? (new TlNode('chatBannedRights'))->put('until_date', 0)
                    : (new TlNode('chatBannedRights'))
                        ->put('until_date', 0)
                        ->put('view_messages', true)
                        ->put('send_messages', true)
                        ->put('send_media', true)
                        ->put('send_stickers', true)
                        ->put('send_gifs', true)
                        ->put('send_games', true)
                        ->put('send_inline', true)
                        ->put('embed_links', true);
                $this->bot->invoke((new TlNode('channels.editBanned'))
                    ->put('channel', $peer)
                    ->put('participant', $user)
                    ->put('banned_rights', $rights), 20);
                if ($mode === 'kick') {
                    $this->bot->invoke((new TlNode('channels.editBanned'))
                        ->put('channel', $peer)
                        ->put('participant', $user)
                        ->put('banned_rights', (new TlNode('chatBannedRights'))->put('until_date', 0)), 20);
                }
            }
        } catch (Throwable $e) {
            echo "[yumi] warn action failed: " . $e->getMessage() . "\n";
        }
    }

    private function cmdWarnings(int $chatId, int $sender, string $arg, TlNode $msg): void
    {
        $uid = $this->targetUser($msg, $arg);
        if ($uid === 0) {
            $uid = $sender;
        }
        $w = $this->db->warns($chatId, $uid);
        if ($w['count'] === 0) {
            $this->send($chatId, $this->t($chatId, 'warnings_empty'));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'warnings_header', [
            'user' => $this->mention($uid),
            'count' => (string) $w['count'],
            'limit' => (string) $this->db->warnLimit($chatId),
        ]));
    }

    private function cmdResetWarns(int $chatId, int $sender, string $arg, TlNode $msg): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $uid = $this->targetUser($msg, $arg);
        if ($uid === 0) {
            $this->send($chatId, $this->t($chatId, 'auth_no_user'));
            return;
        }
        $n = $this->db->resetWarns($chatId, $uid);
        $this->send($chatId, $this->t($chatId, 'warns_reset', ['count' => (string) $n]));
    }

    private function cmdSetWarnLimit(int $chatId, int $sender, string $arg): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $n = (int) $arg;
        if ($n < 2 || $n > 20) {
            Locales::setCurrentCommand('/setwarnlimit');
            $this->send($chatId, $this->t($chatId, 'warn_limit_usage'));
            return;
        }
        $this->db->setWarnLimit($chatId, $n);
        $this->send($chatId, $this->t($chatId, 'warn_limit_set', ['limit' => (string) $n]));
    }

    private function cmdSetWarnMode(int $chatId, int $sender, string $arg): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $m = strtolower(trim($arg));
        if (!in_array($m, ['ban', 'kick', 'mute'], true)) {
            Locales::setCurrentCommand('/setwarnmode');
            $this->send($chatId, $this->t($chatId, 'warn_mode_usage'));
            return;
        }
        $this->db->setWarnMode($chatId, $m);
        $this->send($chatId, $this->t($chatId, 'warn_mode_set', ['mode' => strtoupper($m)]));
    }

    // ── broadcast (owner) ────────────────────────────────────────────────────

    private function cmdBroadcast(int $chatId, int $sender, string $arg): void
    {
        if ($sender !== $this->ownerId) {
            $this->send($chatId, $this->t($chatId, 'sudo_owner_only'));
            return;
        }
        if (trim($arg) === '') {
            $this->send($chatId, $this->t($chatId, 'broadcast_no_content'));
            return;
        }
        $targets = $this->db->chatIds();
        $this->send($chatId, $this->t($chatId, 'broadcast_initializing'));
        $ok = 0;
        $fail = 0;
        foreach ($targets as $target) {
            if ($target === $chatId) {
                continue;
            }
            try {
                $this->bot->invoke((new TlNode('messages.sendMessage'))
                    ->put('peer', $this->botPeers->inputPeer($target))
                    ->put('message', $arg)
                    ->put('random_id', random_int(1, PHP_INT_MAX)), 20);
                $ok++;
            } catch (Throwable $e) {
                $fail++;
            }
            usleep(120000);
        }
        $this->send($chatId, '<b>📡 𝐁‌ꝛσᴧᴅᴄᴧsᴛ 𝐃‌σηє</b>'
            . "\n\n<blockquote>✅ sєηᴛ: <code>$ok</code>\n❌ ғᴧɪʟєᴅ: <code>$fail</code></blockquote>");
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

    /**
     * Search across the configured providers. iota runs first, RiteshApi is the
     * fallback (or the primary when /provider ritesh is set).
     *
     * @return list<array>
     */
    private function findTracks(string $query, int $limit = 5): array
    {
        $provider = $this->db->provider();
        // A prefixed media id (`yt:`, `am:`, `dz:`, …) is already an exact
        // catalogue pointer — no search needed.
        $q = trim($query);
        if (preg_match('~^(yt|sp|dz|am|jv|sc|bp|rs|mb):\S+$~i', $q)) {
            $meta = $this->iota->info($q);
            if ($meta !== null) {
                return [$meta];
            }
            if (str_starts_with($q, 'yt:') && $this->ritesh->enabled()) {
                $hit = $this->ritesh->details(substr($q, 3));
                if ($hit !== null) {
                    return [$hit];
                }
            }
        }
        $isUrl = UrlResolver::isUrl($query);
        $useIota = $provider !== 'ritesh';
        $useRitesh = $provider !== 'iota' && $this->ritesh->enabled();

        $out = [];
        if ($isUrl) {
            // Links are always resolved by the widest catalogue first — the
            // /provider switch only chooses who answers text searches.
            $out = UrlResolver::resolve($query, $this->iota, $this->ritesh);
            if ($out === [] && $this->ritesh->enabled()) {
                $hit = $this->ritesh->resolveUrl($query);
                if ($hit !== null) {
                    $out = [$hit];
                    echo "[yumi] ritesh supplied 1 result(s)\n";
                }
            }
        } elseif ($useIota) {
            $out = $this->searchCached($query, $limit, $provider);
        }
        if ($out === [] && $useRitesh) {
            $out = $this->ritesh->search($query, $limit);
            if ($out !== []) {
                echo "[yumi] ritesh supplied " . count($out) . " result(s)\n";
            }
        }
        return array_values($out);
    }

    /**
     * Auto-cache for text searches: a repeated /play of the same words is
     * served from data/qcache.json instead of hitting the provider again.
     * Results are keyed per provider so /provider switches stay correct.
     *
     * @return list<array>
     */
    private function searchCached(string $query, int $limit, string $provider): array
    {
        $q = trim(preg_replace('~\s+~u', ' ', mb_strtolower($query, 'UTF-8')) ?? $query);
        $key = $provider . '|' . $q;
        $c = $this->qcache();
        $e = $c[$key] ?? null;
        if (is_array($e) && isset($e['ts']) && time() - (int) $e['ts'] < 21600 && ($e['r'] ?? []) !== []) {
            return array_slice((array) $e['r'], 0, $limit);
        }
        $r = $this->iota->search($query, $limit);
        if ($r !== []) {
            $c[$key] = ['ts' => time(), 'r' => array_values($r)];
            if (count($c) > 400) {
                uasort($c, static fn(array $a, array $b): int => (int) $b['ts'] <=> (int) $a['ts']);
                $c = array_slice($c, 0, 400, true);
            }
            $this->qcache = $c;
            @file_put_contents(
                dirname(__DIR__) . '/data/qcache.json',
                json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }
        return $r;
    }

    private function qcache(): array
    {
        if ($this->qcache !== null) {
            return $this->qcache;
        }
        $raw = @file_get_contents(dirname(__DIR__) . '/data/qcache.json');
        $j = $raw !== false ? json_decode($raw, true) : null;
        return $this->qcache = is_array($j) ? $j : [];
    }

    private function cmdPlay(int $chatId, int $sender, string $arg, bool $vcVideo, int $replyTo = 0, int $replied = 0): void
    {
        if ($arg === '') {
            // No arg + no reply → usage template; empty query
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
        if ($chatId < 0 && $this->db->playMode($chatId) === 'admin' && !$this->isAdmin($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'playmode_restricted'));
            return;
        }
        // Pasted links (Spotify / Apple Music / Deezer / YouTube / direct file)
        // must be resolved to a real track first — iota would treat the URL
        // itself as a text query and return nothing.
        $isLink = UrlResolver::isUrl($arg);
        $results = $this->findTracks($arg, $isLink ? 10 : 5);
        if ($results === []) {
            $this->send($chatId, $this->t($chatId, 'no_song_found'));
            return;
        }
        if (!isset($this->rooms[$chatId])) {
            $this->rooms[$chatId] = new Room($chatId);
            // Autoplay is a per-chat preference — a fresh room must inherit it,
            // otherwise the panel toggle silently resets on the next /play.
            $this->rooms[$chatId]->autoplay = $this->db->autoplay($chatId);
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
            $this->logPlayback($chatId, $sender, (string) $t['title'], $this->trackUrl($t), true);
            $this->send($chatId, $this->t($chatId, 'play_added_to_queue_single', [
                'index' => (string) count($r->queue),
                'title' => Util::escapeHtml($t['title']),
                'duration' => Util::formatTime((int) $t['duration']),
                'url' => $this->trackUrl($t),
                'by' => $this->mention($sender),
            ]), 0, Buttons::playMarkup($this->loc, $this->lang($chatId), $r, true));
        }

        // Album / playlist link → the resolver returns the whole track list;
        // queue everything after the one that just started.
        if ($isLink && count($results) > 1) {
            $added = 0;
            foreach (array_slice($results, 1) as $extra) {
                if ($added >= 20 || ($extra['media_id'] ?? '') === '') {
                    break;
                }
                if ($vcVideo) {
                    $extra['video'] = true;
                }
                $extra['req'] = $sender;
                $r->queue[] = $extra;
                $added++;
            }
            if ($added > 0) {
                $this->send($chatId, $this->t($chatId, 'play_added_to_queue_album', [
                    'count' => (string) $added,
                    'title' => Util::escapeHtml((string) ($results[0]['title'] ?? '')),
                ]));
            }
        }
    }

    private function trackUrl(array $t): string
    {
        $id = (string) ($t['media_id'] ?? '');
        if (str_starts_with($id, 'url:')) {
            return substr($id, 4);
        }
        if (preg_match('#^yt:(.+)$#', $id, $mm)) {
            return 'https://youtu.be/' . $mm[1];
        }
        if (preg_match('#^am:(\d+)$#', $id, $mm)) {
            return 'https://music.apple.com/song/' . $mm[1];
        }
        if (preg_match('#^dz:(\d+)$#', $id, $mm)) {
            return 'https://www.deezer.com/track/' . $mm[1];
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
        $results = $this->findTracks($arg, 3);
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
        // /seek N jumps to absolute second N; /seekback N rewinds N seconds.
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
        $err = $this->voice?->playFile($chatId, $r->path, (bool) ($r->track['video'] ?? false), $target, $r->speed);
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
        // No assistant session → no one can join the call, so fail with a
        // precise reason instead of a misleading "restart the voice chat".
        if (!$this->assistantOk) {
            $this->send($chatId, $this->t($chatId, 'assistant_unavailable'));
            return;
        }
        $t['req'] = $t['req'] ?? $sender;
        $r->track = $t;
        $r->position = 0;
        $r->endStreak = 0;
        $r->playing = false;
        $this->send($chatId, $this->t($chatId, 'stream_downloading_next'));
        $path = $this->resolveTrack($t, $vcVideo);
        // An autoplay pick that cannot be fetched should not kill the queue —
        // walk the rest of the pool before giving up.
        if ($path === null && !empty($t['_pool'])) {
            $tries = 0;
            foreach ($t['_pool'] as $alt) {
                if ($tries >= 3) {
                    break;
                }
                if (!is_array($alt) || ($alt['media_id'] ?? '') === ($t['media_id'] ?? '')) {
                    continue;
                }
                $tries++;
                $altPath = $this->resolveTrack($alt, $vcVideo);
                if ($altPath !== null) {
                    $alt['req'] = $t['req'];
                    $alt['autoplay'] = true;
                    $t = $alt;
                    $r->track = $t;
                    $path = $altPath;
                    break;
                }
            }
        }
        if ($path === null) {
            $r->track = null;
            $this->send($chatId, $this->t($chatId, 'play_download_failed'));
            return;
        }
        $r->path = $path;
        $r->speed = $this->db->speed($chatId);
        $err = $this->voice?->playFile($chatId, $path, $vcVideo, 0, $r->speed);
        if ($err !== '') {
            $r->track = null;
            echo "[yumi] playFile failed: $err\n";
            $this->send($chatId, $this->t($chatId, 'play_unable'));
            return;
        }
        $r->playing = true;
        $r->remember($t);
        $r->statusMsg = $this->nowPlayingCard($chatId, $r, $sender);
        echo "[yumi] card msg id: {$r->statusMsg}\n";
        $this->logPlayback($chatId, $sender, (string) $t['title'], $this->trackUrl($t), false);
    }

    /** Photo now-playing card (falls back to a text card). */
    private function nowPlayingCard(int $chatId, Room $r, int $sender): int
    {
        $t = $r->track ?? [];
        $lang = $this->lang($chatId);
        $caption = $this->loc->f($lang, 'stream_now_playing',
            'title', Util::escapeHtml((string) ($t['title'] ?? '-')),
            'duration', Util::formatTime((int) ($t['duration'] ?? 0)),
            'url', $this->trackUrl($t),
            'by', !empty($t['autoplay'])
                ? $this->loc->get($lang, 'autoplay_requester')
                : $this->botPeers->userMention($sender)
        );
        $thumb = (string) ($t['thumbnail'] ?? '');
        if ($thumb !== '' && $this->db->thumbnailsDisabled($chatId) === false) {
            try {
                [$plain, $entities] = MsgEntities::parse($caption);
                $rid = random_int(1, PHP_INT_MAX);
                $req = (new TlNode('messages.sendMedia'))
                    ->put('peer', $this->botPeers->inputPeer($chatId))
                    ->put('media', (new TlNode('inputMediaPhotoExternal'))
                        ->put('flags', 0)
                        ->put('url', $thumb))
                    ->put('message', $plain)
                    ->put('random_id', $rid);
                if ($entities !== []) {
                    $req->put('entities', $entities);
                }
                $req->put('reply_markup', $this->playMarkup($r));
                $res = $this->bot->invoke($req, 30);
                $this->botPeers->learnFrom($res);
                return $this->updatesMsgId($res, $chatId, $rid);
            } catch (Throwable $e) {
                echo "[yumi] photo card failed: " . $e->getMessage() . " — text fallback\n";
            }
        }
        return $this->send($chatId, $caption, 0, $this->playMarkup($r));
    }

    /**
     * Keep the audio cache inside a size budget. Oldest files go first; a file
     * currently playing is touched on every access so it is never the victim.
     */
    private function pruneDownloads(): void
    {
        $cap = (int) (getenv('CACHE_MAX_MB') ?: 2048) * 1024 * 1024;
        if ($cap <= 0) {
            return;
        }
        $files = [];
        $total = 0;
        foreach (glob($this->downloads . '/*') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $size = (int) @filesize($f);
            $total += $size;
            $files[] = ['p' => $f, 's' => $size, 'm' => (int) @filemtime($f)];
        }
        if ($total <= $cap) {
            return;
        }
        usort($files, static fn(array $a, array $b): int => $a['m'] <=> $b['m']);
        $freed = 0;
        foreach ($files as $f) {
            if ($total - $freed <= $cap) {
                break;
            }
            if (@unlink($f['p'])) {
                $freed += $f['s'];
            }
        }
        if ($freed > 0) {
            echo '[cache] pruned ' . round($freed / 1048576, 1) . " MB\n";
        }
    }

    private function resolveTrack(array $t, bool $video): ?string
    {
        $path = $this->resolveTrackFile($t, $video);
        if ($path !== null && !str_starts_with($path, 'http')) {
            @touch($path);          // mark as recently used for the LRU pruner
            $this->pruneDownloads();
        }
        return $path;
    }

    private function resolveTrackFile(array $t, bool $video): ?string
    {
        $mediaId = (string) ($t['media_id'] ?? '');
        // Direct media link: ntgcalls' ffmpeg source streams http(s) URLs as-is.
        if (str_starts_with($mediaId, 'url:')) {
            return substr($mediaId, 4);
        }
        $id = str_replace(':', '_', $mediaId);
        $dest = $this->downloads . '/' . $id . ($video ? '.mp4' : '.audio');
        if (is_file($dest) && filesize($dest) > 1024) {
            return $dest;
        }
        $provider = $this->db->provider();
        if ($provider !== 'ritesh' && $this->iota->downloadToFile($t['media_id'], $dest, $video)) {
            return $dest;
        }
        // Ritesh fallback: yt:<id> → bare id, url:<u> → the url itself.
        if ($provider !== 'iota' && $this->ritesh->enabled()) {
            $q = $mediaId;
            if (str_starts_with($q, 'yt:')) {
                $q = substr($q, 3);
            }
            if ($this->ritesh->downloadToFile($q, $dest, $video)) {
                return $dest;
            }
        }
        // Forced provider failed — still try the other one rather than give up.
        if ($provider === 'ritesh' && $this->iota->downloadToFile($t['media_id'], $dest, $video)) {
            return $dest;
        }
        return null;
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
            $this->voice?->ntg->stop($chatId);
            $err = $this->voice?->playFile($chatId, $r->path, (bool) ($r->track['video'] ?? false), 0, $r->speed);
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
            $pool = $this->autoplayPool($r, $r->track, 5);
            $next = $this->pickAutoplay($pool);
            if ($next !== null) {
                $next['autoplay'] = true;
                $next['_pool'] = $pool;
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

    /**
     * Build the autoplay candidate pool: catalogue neighbours first, then
     * artist/title searches. Anything already heard in this room is dropped,
     * so a finished song can never come back as its own "next" track.
     *
     * @return list<array>
     */
    private function autoplayPool(Room $r, array $last, int $limit = 5): array
    {
        $out = [];
        $ids = [];
        $titles = [];
        $add = function (array $c) use (&$out, &$ids, &$titles, $r, $last, $limit): void {
            if (count($out) >= $limit) {
                return;
            }
            $id = (string) ($c['media_id'] ?? '');
            if ($id === '' || $id === (string) ($last['media_id'] ?? '')) {
                return;
            }
            // Previews and hour-long compilations make poor "next" tracks.
            $dur = (int) ($c['duration'] ?? 0);
            if ($dur > 0 && ($dur < 45 || $dur > 1200)) {
                return;
            }
            $n = Util::normTitle((string) ($c['title'] ?? ''));
            foreach ($titles as $seen) {
                if (Room::sameTitle($n, $seen)) {
                    return;
                }
            }
            if (isset($ids[$id]) || $r->played($c)) {
                return;
            }
            $ids[$id] = true;
            $titles[] = $n;
            $out[] = $c;
        };

        foreach ($this->iota->similar((string) ($last['media_id'] ?? ''), 8) as $c) {
            $add($c);
        }
        if ($out === []) {
            foreach ($this->relatedQueries($last, $limit + 4) as $c) {
                $add($c);
            }
        }
        return $out;
    }

    /**
     * Fallback discovery when the catalogue has no neighbours: the artist's
     * other tracks, then a "similar songs" text search.
     *
     * @return list<array>
     */
    private function relatedQueries(array $last, int $limit): array
    {
        $artist = trim((string) ($last['artist'] ?? ''));
        $title = Util::normTitle((string) ($last['title'] ?? ''));
        $queries = [];
        if ($artist !== '') {
            $queries[] = $artist;
        }
        if ($title !== '') {
            $queries[] = $title . ' similar songs';
        }
        if ($artist !== '' && $title !== '') {
            $queries[] = $artist . ' ' . $title;
        }
        $out = [];
        foreach ($queries as $q) {
            foreach ($this->findTracks($q, $limit) as $c) {
                $out[] = $c;
            }
            if (count($out) >= $limit * 2) {
                break;
            }
        }
        return $out;
    }

    /** Random pick from the top of the pool, so autoplay does not follow a fixed order. */
    private function pickAutoplay(array $pool): ?array
    {
        $n = min(count($pool), 4);
        if ($n === 0) {
            return null;
        }
        return $pool[$n === 1 ? 0 : random_int(0, $n - 1)];
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
            // Refresh the progress button every 5s (markup-only edit)
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
            $this->flushPendingDeletes($now);
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
            // Prefix routing must use ifs — `case str_starts_with(...)` in a
            // switch compares loosely and would swallow every later branch.
            if (str_starts_with($data, 'help:')) {
                $section = substr($data, 5);
                $this->editOrSend($q, $this->loc->get($lang, 'help_' . $section),
                    Buttons::backKeyboard($this->loc, $lang));
                $this->answerQuery($queryId, '', false);
                return;
            }
            if (str_starts_with($data, 'set:')) {
                $this->onSettingCallback($q, substr($data, 4), $sender, $queryId, $lang);
                return;
            }
            if ($data === 'lang:menu') {
                $this->editOrSend($q, $this->loc->get($lang, 'lang_select'), Buttons::langKeyboard($this->loc, $lang));
                $this->answerQuery($queryId, '', false);
                return;
            }
            if (str_starts_with($data, 'lang:')) {
                $code = substr($data, 5);
                if (!in_array($code, $this->loc->available(), true)) {
                    $this->answerQuery($queryId, $this->loc->get($lang, 'lang_fail'), true);
                    return;
                }
                $this->db->setLang($sender, $code);
                $this->answerQuery($queryId, $this->loc->f($code, 'lang_success', 'lang_name',
                    $this->loc->get($code, 'name')), false);
                $this->editOrSend($q, $this->loc->get($code, 'lang_select'), Buttons::langKeyboard($this->loc, $code));
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
                case 'close':
                    try {
                        $this->bot->invoke((new TlNode('messages.deleteMessages'))
                            ->put('id', [$q->i('msg_id')]), 15);
                    } catch (Throwable $ignored) {
                    }
                    $this->answerQuery($queryId, '', false);
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

    private function onSettingCallback(TlNode $q, string $what, int $sender, int $queryId, string $lang): void
    {
        $chatId = $this->callbackChat($q);
        if ($chatId === 0) {
            $chatId = $sender;
        }
        if (!$this->isAdmin($chatId, $sender)) {
            $this->answerQuery($queryId, $this->loc->get($lang, 'playmode_restricted'), true);
            return;
        }
        $answer = $this->loc->get($lang, 'settings_updated');
        switch ($what) {
            case 'playmode':
                $adminOnly = $this->db->playMode($chatId) !== 'admin';
                $this->db->setPlayMode($chatId, $adminOnly ? 'admin' : 'everyone');
                $answer = $this->loc->get($lang,
                    $adminOnly ? 'playmode_success_admins' : 'playmode_success_everyone');
                break;
            case 'adminmode':
                $v = !$this->db->adminMode($chatId);
                $this->db->setAdminMode($chatId, $v);
                $answer = $this->loc->f($lang, 'adminmode_updated', 'status',
                    $this->loc->get($lang, $v ? 'adminmode_status_admin' : 'adminmode_status_everyone'));
                break;
            case 'nothumb':
                $v = !$this->db->thumbnailsDisabled($chatId);
                $this->db->setThumbnailsDisabled($chatId, $v);
                $answer = $this->loc->f($lang, 'nothumb_updated', 'status',
                    $this->loc->get($lang, $v ? 'disabled' : 'enabled'));
                break;
            case 'autoleave':
                $v = !$this->db->autoLeave($chatId);
                $this->db->setAutoLeave($chatId, $v);
                $answer = $this->loc->get($lang, $v ? 'enabled' : 'disabled');
                break;
        }
        $this->answerQuery($queryId, $answer, false);
        $msgId = $q->i('msg_id');
        if ($msgId > 0) {
            $this->edit($chatId, $msgId, $this->t($chatId, 'settings_main', [
                'title' => Util::escapeHtml($chatId > 0 ? 'ᴘʀɪᴠᴧᴛє ᴄʜᴧᴛ' : (string) $chatId),
                'id' => (string) $chatId,
            ]), Buttons::settingsKeyboard($this->loc, $this->lang($chatId), $this->db, $chatId));
        }
    }

    private function onRoomCallback(TlNode $q, int $chatId, string $action, string $p4, int $sender, string $lang): void
    {
        $queryId = $q->l('query_id');
        // Playback buttons are admin-only
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
                    $this->voice?->ntg->stop($chatId);
                    $err = $this->voice?->playFile($chatId, $r->path, (bool) ($r->track['video'] ?? false), 0, $r->speed);
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
    /**
     * Extract the id of the message we just created.
     *
     * Telegram may batch unrelated updates into the same `updates` container
     * (a join-request in another group, for instance), so a blind "first id
     * wins" scan can return a foreign message id and every later edit fails.
     * Match on the destination peer, then on our own random_id.
     */
    private function updatesMsgId(TlNode $res, int $chatId = 0, int $rid = 0): int
    {
        $fallbackMsg = 0;
        $fallbackRid = 0;
        foreach ($res->list('updates') as $u) {
            if (!$u instanceof TlNode) {
                continue;
            }
            if (in_array($u->name, ['message', 'messageEmpty', 'messageService'], true)) {
                $id = (int) $u->l('id');
                if ($this->peerMatches($u->node('peer_id'), $chatId)) {
                    return $id;
                }
                $fallbackMsg = $fallbackMsg ?: $id;
                continue;
            }
            if ($u->name === 'updateNewMessage' || $u->name === 'updateNewChannelMessage') {
                $m = $u->node('message');
                if ($m !== null) {
                    $id = (int) $m->l('id');
                    if ($chatId === 0 || $this->peerMatches($m->node('peer_id'), $chatId)) {
                        return $id;
                    }
                    $fallbackMsg = $fallbackMsg ?: $id;
                }
                continue;
            }
            if ($u->name === 'updateMessageID') {
                $id = (int) $u->l('id');
                if ($rid !== 0 && $u->has('random_id') && $u->l('random_id') === $rid) {
                    return $id;
                }
                $fallbackRid = $fallbackRid ?: $id;
            }
        }
        return $fallbackMsg ?: $fallbackRid;
    }

    private function peerMatches(?TlNode $peer, int $chatId): bool
    {
        if ($peer === null || $chatId === 0) {
            return false;
        }
        if ($chatId <= -1000000000000) {
            return $peer->name === 'peerChannel'
                && (int) $peer->l('channel_id') === (int) substr((string) $chatId, 4);
        }
        if ($chatId < 0) {
            return $peer->name === 'peerChat' && (int) $peer->l('chat_id') === -$chatId;
        }
        return $peer->name === 'peerUser' && (int) $peer->l('user_id') === $chatId;
    }

    /** Show "typing…" in the chat while the model thinks. */
    public function typing(int $chatId): void
    {
        try {
            $this->bot->invoke((new TlNode('messages.setTyping'))
                ->put('flags', 0)
                ->put('peer', $this->botPeers->inputPeer($chatId))
                ->put('action', new TlNode('sendMessageTypingAction')), 8);
        } catch (Throwable $e) {
            // cosmetic only
        }
    }

    public function send(int $chatId, string $text, int $replyTo = 0, ?TlNode $markup = null): int
    {
        if ($text === '') {
            return 0;
        }
        try {
            [$plain, $entities] = MsgEntities::parse($text);
            $rid = (int) (microtime(true) * 1e6) ^ random_int(0, PHP_INT_MAX);
            $req = (new TlNode('messages.sendMessage'))
                ->put('peer', $this->botPeers->inputPeer($chatId))
                ->put('message', $plain)
                ->put('random_id', $rid);
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
            $msgId = $this->updatesMsgId($res, $chatId, $rid) ?: ($res->l('id') ?? 0);
            $this->chatbot->noteSent($chatId, (int) $msgId);
            return $msgId;
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
