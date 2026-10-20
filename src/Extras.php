<?php

declare(strict_types=1);

/**
 * Secondary feature surface — notes, filters, playlists, promote/demote,
 * welcome, clean mode, logger and the global blacklist.
 *
 * Lives in a trait so App.php stays focused on the playback pipeline while
 * these handlers still reach App's private helpers (send/t/isAdmin/targetUser).
 */
trait Extras
{
    /** Group-scoped commands — refused in the bot's private chat. */
    private const GROUP_ONLY = [
        'queue', 'cqueue', 'skip', 'cskip', 'askip', 'caskip', 'pause', 'cpause',
        'resume', 'cresume', 'stop', 'cstop', 'end', 'cend', 'seek', 'cseek',
        'seekback', 'cseekback', 'loop', 'cloop', 'setloop', 'csetloop',
        'shuffle', 'cshuffle', 'speed', 'cspeed', 'setspeed', 'csetspeed',
        'speedup', 'cspeedup', 'mute', 'cmute', 'unmute', 'cunmute',
        'replay', 'creplay', 'position', 'cposition', 'pos', 'cpos',
        'jump', 'cjump', 'move', 'cmove', 'remove', 'cremove', 'clear', 'cclear',
        'auth', 'authorize', 'addauth', 'unauth', 'delauth', 'delauthuser',
        'authlist', 'authusers', 'ban', 'unban', 'kick', 'promote', 'fullpromote',
        'unpromote', 'demote', 'pin', 'unpin', 'unpinall', 'purge',
        'warn', 'warnings', 'warns', 'resetwarns', 'clearwarn', 'unwarn',
        'setwarnlimit', 'warnlimit', 'setwarnmode', 'welcome',
        'blockuser', 'blacklistuser', 'bluser', 'blackuser', 'unblockuser',
        'unblacklistuser', 'unbluser', 'whitelistuser', 'blockchat', 'blacklistchat',
        'blchat', 'blackchat', 'unblockchat', 'unblacklistchat', 'unblchat',
        'whitechat', 'blacklisted', 'blocked', 'playmode', 'adminmode',
        'nothumb', 'nothumbs', 'autoleave', 'cplay', 'setcplay', 'channelplay',
        'autoplay', 'cleanmode', 'cmddelete', 'logger', 'logs', 'log',
        'vcstats', 'vcstatus', 'activevc', 'activevoice', 'ac', 'active',
        'vclog', 'vclogger', 'reload', 'creload',
        'save', 'get', 'notes', 'clearnote', 'filter', 'filters',
        'stopfilter', 'clearfilters',
    ];

    /** Commands that need sudo rather than chat admin. */
    private const SUDO_ONLY = [
        'blockchat', 'blacklistchat', 'blchat', 'blackchat',
        'unblockchat', 'unblacklistchat', 'unblchat', 'whitechat',
        'blockuser', 'blacklistuser', 'bluser', 'blackuser',
        'unblockuser', 'unblacklistuser', 'unbluser', 'whitelistuser',
        'blacklisted', 'blocked', 'logger', 'logs', 'log', 'vclog', 'vclogger',
    ];

    private function isGroupOnly(string $cmd): bool
    {
        return in_array(ltrim($cmd, '/'), self::GROUP_ONLY, true);
    }

    private function isSudo(int $userId): bool
    {
        return $userId === $this->ownerId || $this->db->isSudo($userId);
    }

    /** @var array<string,bool> chatId:error → already reported */
    private array $deleteWarned = [];

    private function deleteMsgs(int $chatId, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }
        try {
            $this->bot->invoke((new TlNode('messages.deleteMessages'))
                ->put('id', $ids)
                ->put('revoke', true), 20);
        } catch (Throwable $e) {
            $key = $chatId . ':' . substr($e->getMessage(), 0, 40);
            if (!isset($this->deleteWarned[$key])) {
                $this->deleteWarned[$key] = true;
                echo "[extras] delete failed chat=$chatId ids=" . implode(',', $ids)
                    . ': ' . $e->getMessage() . "\n";
            }
        }
    }

    // ── notes ────────────────────────────────────────────────────────────────

    private function cmdNoteSave(int $chatId, string $arg): void
    {
        $sp = strpos($arg, ' ');
        if ($sp === false || $sp === 0) {
            $this->send($chatId, $this->t($chatId, 'note_usage'));
            return;
        }
        $name = mb_strtolower(trim(substr($arg, 0, $sp)));
        $body = trim(substr($arg, $sp + 1));
        if ($name === '' || $body === '') {
            $this->send($chatId, $this->t($chatId, 'note_usage'));
            return;
        }
        $this->db->setNote($chatId, $name, $body);
        $this->send($chatId, $this->t($chatId, 'note_saved', ['name' => Util::escapeHtml($name)]));
    }

    private function cmdNoteGet(int $chatId, string $arg): void
    {
        $name = mb_strtolower(trim($arg));
        if ($name === '') {
            $this->send($chatId, $this->t($chatId, 'note_usage'));
            return;
        }
        $note = $this->db->note($chatId, $name);
        if ($note === '') {
            $this->send($chatId, $this->t($chatId, 'note_not_found'));
            return;
        }
        $this->send($chatId, $note);
    }

    private function cmdNoteDelete(int $chatId, string $arg): void
    {
        $name = mb_strtolower(trim($arg));
        if ($name === '') {
            $this->send($chatId, $this->t($chatId, 'note_usage'));
            return;
        }
        if (!$this->db->delNote($chatId, $name)) {
            $this->send($chatId, $this->t($chatId, 'note_not_found'));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'note_deleted', ['name' => Util::escapeHtml($name)]));
    }

    private function cmdNoteList(int $chatId): void
    {
        $notes = $this->db->notes($chatId);
        if ($notes === []) {
            $this->send($chatId, $this->t($chatId, 'notes_empty'));
            return;
        }
        $out = $this->t($chatId, 'notes_title');
        foreach (array_keys($notes) as $n) {
            $out .= "\n• <code>" . Util::escapeHtml($n) . '</code>';
        }
        $this->send($chatId, $out);
    }

    // ── filters ──────────────────────────────────────────────────────────────

    private function cmdFilterSave(int $chatId, int $sender, string $arg): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $sp = strpos($arg, ' ');
        if ($sp === false || $sp === 0) {
            $this->send($chatId, $this->t($chatId, 'filter_usage'));
            return;
        }
        $kw = trim(substr($arg, 0, $sp));
        $reply = trim(substr($arg, $sp + 1));
        if ($kw === '' || $reply === '') {
            $this->send($chatId, $this->t($chatId, 'filter_usage'));
            return;
        }
        $this->db->saveFilter($chatId, $kw, $reply);
        $this->send($chatId, $this->t($chatId, 'filter_saved', ['keyword' => Util::escapeHtml($kw)]));
    }

    private function cmdFilterList(int $chatId): void
    {
        $filters = $this->db->filters($chatId);
        if ($filters === []) {
            $this->send($chatId, $this->t($chatId, 'filters_empty'));
            return;
        }
        $out = $this->t($chatId, 'filters_title');
        foreach (array_keys($filters) as $kw) {
            $out .= "\n• <code>" . Util::escapeHtml($kw) . '</code>';
        }
        $this->send($chatId, $out);
    }

    private function cmdFilterStop(int $chatId, int $sender, string $arg): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $kw = trim($arg);
        if ($kw === '' || !$this->db->delFilter($chatId, $kw)) {
            $this->send($chatId, $this->t($chatId, 'filter_not_found'));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'filter_deleted', ['keyword' => Util::escapeHtml($kw)]));
    }

    private function cmdFilterClear(int $chatId, int $sender): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $n = $this->db->clearFilters($chatId);
        $this->send($chatId, $this->t($chatId, 'filters_cleared', ['count' => (string) $n]));
    }

    /** Plain-message hook: reply when a filter keyword is mentioned. */
    public function onPlainMessage(int $chatId, int $sender, string $text, int $msgId): void
    {
        if ($text === '' || str_starts_with($text, '/')) {
            return;
        }
        if ($this->db->isBlockedUser($sender)) {
            $this->deleteMsgs($chatId, [$msgId]);
            return;
        }
        $hit = $this->db->matchFilter($chatId, $text);
        if ($hit !== null) {
            echo "[extras] filter hit chat=$chatId\n";
            $this->send($chatId, $hit, $msgId);
        }
    }

    // ── playlists ────────────────────────────────────────────────────────────

    private function cmdPlaylistCreate(int $chatId, int $sender, string $arg): void
    {
        $name = trim($arg);
        if ($name === '') {
            $this->send($chatId, $this->t($chatId, 'playlist_usage_create'));
            return;
        }
        if (count($this->db->playlists($sender)) >= 20) {
            $this->send($chatId, $this->t($chatId, 'playlist_limit_reached'));
            return;
        }
        if (!$this->db->createPlaylist($sender, $name)) {
            $this->send($chatId, $this->t($chatId, 'playlist_create_fail', [
                'error' => 'ᴘʟᴧʏʟɪsᴛ єxɪsᴛs',
            ]));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'playlist_create_success', [
            'name' => Util::escapeHtml($name),
            'id' => Util::escapeHtml($name),
        ]));
    }

    private function cmdPlaylistDelete(int $chatId, int $sender, string $arg): void
    {
        $name = trim($arg);
        if ($name === '') {
            $this->send($chatId, $this->t($chatId, 'playlist_usage_delete'));
            return;
        }
        if (!$this->db->deletePlaylist($sender, $name)) {
            $this->send($chatId, $this->t($chatId, 'playlist_not_found'));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'playlist_delete_success', ['name' => Util::escapeHtml($name)]));
    }

    private function cmdPlaylistAdd(int $chatId, int $sender, string $arg): void
    {
        $sp = strpos($arg, ' ');
        if ($sp === false || $sp === 0) {
            $this->send($chatId, $this->t($chatId, 'playlist_usage_add'));
            return;
        }
        $name = trim(substr($arg, 0, $sp));
        $query = trim(substr($arg, $sp + 1));
        if (!isset($this->db->playlists($sender)[$name])) {
            $this->send($chatId, $this->t($chatId, 'playlist_not_found'));
            return;
        }
        $tracks = UrlResolver::isUrl($query)
            ? UrlResolver::resolve($query, $this->iota)
            : $this->iota->search($query, 5);
        if ($tracks === []) {
            $this->send($chatId, $this->t($chatId, 'playlist_invalid_query'));
            return;
        }
        $t = $tracks[0];
        $this->db->addToPlaylist($sender, $name, [
            'title' => (string) $t['title'],
            'url' => (string) $t['media_id'],
            'duration' => (int) $t['duration'],
            'by' => (string) $sender,
        ]);
        $this->send($chatId, $this->t($chatId, 'playlist_add_success', [
            'song' => Util::escapeHtml(Util::shortTitle((string) $t['title'], 40)),
            'playlist' => Util::escapeHtml($name),
        ]));
    }

    private function cmdPlaylistRemove(int $chatId, int $sender, string $arg): void
    {
        $sp = strpos($arg, ' ');
        if ($sp === false || $sp === 0) {
            $this->send($chatId, $this->t($chatId, 'playlist_usage_remove'));
            return;
        }
        $name = trim(substr($arg, 0, $sp));
        $num = trim(substr($arg, $sp + 1));
        if (!ctype_digit($num)) {
            $this->send($chatId, $this->t($chatId, 'playlist_invalid_song_number'));
            return;
        }
        if (!$this->db->removeFromPlaylist($sender, $name, ((int) $num) - 1)) {
            $this->send($chatId, $this->t($chatId, 'playlist_song_not_found'));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'playlist_remove_success', ['name' => Util::escapeHtml($name)]));
    }

    private function cmdPlaylistInfo(int $chatId, int $sender, string $arg): void
    {
        $name = trim($arg);
        if ($name === '') {
            $this->send($chatId, $this->t($chatId, 'playlist_usage_info'));
            return;
        }
        $entries = $this->db->playlistEntries($sender, $name);
        if ($entries === []) {
            $this->send($chatId, $this->t($chatId, 'playlist_songs_empty'));
            return;
        }
        $lines = [];
        $i = 1;
        foreach ($entries as $e) {
            $lines[] = $this->t($chatId, 'playlist_song_line', [
                'index' => (string) $i++,
                'name' => Util::escapeHtml(Util::shortTitle((string) $e['title'], 40)),
                'url' => Util::formatDuration((int) $e['duration']),
            ]);
        }
        $this->send($chatId, $this->t($chatId, 'playlist_info', [
            'name' => Util::escapeHtml($name),
            'owner' => $this->mention($sender),
            'count' => (string) count($entries),
            'songs' => implode("\n", $lines),
        ]));
    }

    private function cmdPlaylistList(int $chatId, int $sender): void
    {
        $lists = $this->db->playlists($sender);
        if ($lists === []) {
            $this->send($chatId, $this->t($chatId, 'playlist_none'));
            return;
        }
        $lines = [];
        foreach ($lists as $name => $entries) {
            $lines[] = $this->t($chatId, 'playlist_list_line', [
                'name' => Util::escapeHtml((string) $name),
                'id' => (string) count($entries),
            ]);
        }
        $this->send($chatId, $this->t($chatId, 'playlist_list', [
            'playlists' => implode("\n", $lines),
        ]));
    }

    // ── promote / demote ─────────────────────────────────────────────────────

    private function cmdPromote(int $chatId, int $sender, string $arg, TlNode $msg, bool $demote): void
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
            if ($peer->name !== 'inputPeerChannel') {
                // Bots have no promote rights in legacy basic groups — Telegram
                // rejects messages.editChatAdmin with BOT_METHOD_INVALID.
                $this->send($chatId, $this->t($chatId, 'promote_basic_unsupported'));
                return;
            }
            $user = $this->botPeers->inputPeer($uid);
            $rights = $demote ? new TlNode('chatAdminRights') : (new TlNode('chatAdminRights'))
                ->put('change_info', true)
                ->put('post_messages', true)
                ->put('edit_messages', true)
                ->put('delete_messages', true)
                ->put('ban_users', true)
                ->put('invite_users', true)
                ->put('pin_messages', true)
                ->put('add_admins', true)
                ->put('manage_call', true)
                ->put('manage_topics', true)
                ->put('other', true);
            $this->bot->invoke((new TlNode('channels.editAdmin'))
                ->put('channel', $peer)
                ->put('participant', $user)
                ->put('admin_rights', $rights)
                ->put('rank', ''), 25);
        } catch (Throwable $e) {
            $this->send($chatId, $this->t($chatId, 'generic_error', ['error' => Util::escapeHtml($e->getMessage())]));
            return;
        }
        $this->send($chatId, $this->t($chatId, $demote ? 'demote_success' : 'promote_success', [
            'user' => $this->mention($uid),
        ]));
    }

    // ── welcome ──────────────────────────────────────────────────────────────

    private function cmdWelcome(int $chatId, int $sender, string $arg): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $a = mb_strtolower(trim($arg));
        if ($a === '') {
            $this->send($chatId, $this->t($chatId, 'welcome_status', [
                'status' => $this->db->welcomeOn($chatId) ? 'ση' : 'σғғ',
            ]));
            return;
        }
        if ($a === 'preview') {
            $this->send($chatId, $this->welcomeText($chatId, $sender, '𝐘‌υϻɪ 𝐌‌υsɪᴄ'));
            return;
        }
        $on = in_array($a, ['on', 'enable', 'enabled', 'true', '1'], true);
        $this->db->setWelcomeOn($chatId, $on);
        $this->send($chatId, $this->t($chatId, $on ? 'welcome_enabled' : 'welcome_disabled'));
    }

    /** @var array<int,string> */
    private array $titleCache = [];

    /** Chat title for greetings — cached, one RPC per chat. */
    private function chatTitle(int $chatId): string
    {
        if (isset($this->titleCache[$chatId])) {
            return $this->titleCache[$chatId];
        }
        $title = 'ᴛʜɪs ᴄʜᴧᴛ';
        try {
            $peer = $this->botPeers->inputPeer($chatId);
            $res = $peer->name === 'inputPeerChannel'
                ? $this->bot->invoke((new TlNode('channels.getFullChannel'))->put('channel', $peer), 20)
                : $this->bot->invoke((new TlNode('messages.getFullChat'))->put('chat_id', -$chatId), 20);
            foreach ($res->list('chats') as $c) {
                if ($c instanceof TlNode && $c->str('title') !== '') {
                    $title = $c->str('title');
                }
            }
        } catch (Throwable $e) {
            echo '[extras] chat title failed: ' . $e->getMessage() . "\n";
        }
        return $this->titleCache[$chatId] = $title;
    }

    private function welcomeText(int $chatId, int $userId, string $title): string    {
        return $this->t($chatId, 'welcome_greeting', [
            'user' => $this->mention($userId),
            'chat' => Util::escapeHtml($title),
            'bot' => $this->botName,
        ]);
    }

    /** Fired from onServiceMessage for joins. */
    private function maybeWelcome(int $chatId, array $userIds, string $chatTitle): void
    {
        if (!$this->db->welcomeOn($chatId)) {
            return;
        }
        foreach ($userIds as $uid) {
            if ($uid === 0 || $uid === $this->botId) {
                continue;
            }
            $this->send($chatId, $this->welcomeText($chatId, $uid, $chatTitle));
        }
    }

    // ── clean mode / logger ──────────────────────────────────────────────────

    private function cmdCleanMode(int $chatId, int $sender, string $arg): void
    {
        if (!$this->canManage($chatId, $sender)) {
            $this->send($chatId, $this->t($chatId, 'only_admin'));
            return;
        }
        $a = mb_strtolower(trim($arg));
        $on = $this->db->cleanMode($chatId);
        if (in_array($a, ['on', 'enable', 'enabled'], true)) {
            $on = true;
        } elseif (in_array($a, ['off', 'disable', 'disabled'], true)) {
            $on = false;
        } else {
            $on = !$on;
        }
        $this->db->setCleanMode($chatId, $on);
        $this->send($chatId, $this->t($chatId, 'cleanmode_status', [
            'action' => $on ? 'єηᴧʙʟєᴅ' : 'ᴅɪsᴧʙʟєᴅ',
            'duration' => '15',
        ]) . "\n\n" . $this->t($chatId, 'cleanmode_hint'));
    }

    private function cmdLogger(int $chatId, int $sender, string $arg): void
    {
        if (!$this->isSudo($sender)) {
            $this->send($chatId, $this->t($chatId, 'only_sudo'));
            return;
        }
        $a = mb_strtolower(trim($arg));
        $on = $this->db->loggerOn(0);
        if (in_array($a, ['on', 'enable', 'enabled'], true)) {
            $on = true;
        } elseif (in_array($a, ['off', 'disable', 'disabled'], true)) {
            $on = false;
        } elseif ($a !== '') {
            $this->send($chatId, $this->t($chatId, 'logger_usage', [
                'status' => $this->t($chatId, 'logger_status', [
                    'action' => $on ? 'єηᴧʙʟєᴅ' : 'ᴅɪsᴧʙʟєᴅ',
                ]),
            ]));
            return;
        } else {
            $on = !$on;
        }
        $this->db->setLogger(0, $on);
        $this->send($chatId, $this->t($chatId, 'logger_updated', [
            'status' => $on ? 'єηᴧʙʟєᴅ' : 'ᴅɪsᴧʙʟєᴅ',
        ]));
    }

    /** Playback log line, only when the logger is on. */
    private function logPlayback(int $chatId, int $userId, string $title, string $source, bool $queued): void
    {
        if (!$this->db->loggerOn(0)) {
            return;
        }
        $target = (int) (getenv('LOG_CHAT_ID') ?: 0);
        $text = $this->t($chatId, 'logger_playback_template', [
            'track' => Util::escapeHtml(Util::shortTitle($title, 48)) . ' — '
                . $this->t($chatId, $queued ? 'logger_playback_queued' : 'logger_playback_started'),
            'source' => Util::escapeHtml($source),
            'group' => 'ᴄʜᴧᴛ',
            'group_id' => (string) $chatId,
            'requested_by' => $this->mention($userId),
            'requested_by_id' => (string) $userId,
        ]);
        $this->send($target !== 0 ? $target : $chatId, $text);
    }

    // ── global sudo ──────────────────────────────────────────────────────────

    private function cmdSudo(int $chatId, int $sender, string $arg, TlNode $msg, bool $add): void
    {
        if (!$this->isSudo($sender)) {
            $this->send($chatId, $this->t($chatId, 'only_sudo'));
            return;
        }
        $uid = $this->targetUser($msg, $arg);
        if ($uid === 0) {
            $this->send($chatId, $this->t($chatId, 'user_extract_fail', ['error' => 'ησ ᴛᴧʀɢєᴛ']));
            return;
        }
        if ($add && $uid === $this->ownerId) {
            $this->send($chatId, $this->t($chatId, 'sudo_owner_self'));
            return;
        }
        if (!$add && $uid === $this->ownerId) {
            $this->send($chatId, $this->t($chatId, 'sudo_owner_remove_block'));
            return;
        }
        if ($add) {
            if (!$this->db->addSudo($uid)) {
                $this->send($chatId, $this->t($chatId, 'sudo_already', [
                    'user' => $this->mention($uid),
                    'id' => (string) $uid,
                ]));
                return;
            }
            $this->send($chatId, $this->t($chatId, 'sudo_add_success', [
                'user' => $this->mention($uid),
                'id' => (string) $uid,
            ]));
            return;
        }
        if (!$this->db->delSudo($uid)) {
            $this->send($chatId, $this->t($chatId, 'sudo_not_exists', [
                'user' => $this->mention($uid),
                'id' => (string) $uid,
            ]));
            return;
        }
        $this->send($chatId, $this->t($chatId, 'sudo_remove_success', [
            'user' => $this->mention($uid),
            'id' => (string) $uid,
        ]));
    }

    private function cmdSudoList(int $chatId): void
    {
        $out = $this->t($chatId, 'sudo_list_header');
        $out .= "\n" . $this->t($chatId, 'sudo_list_owner', [
            'user' => $this->mention($this->ownerId),
        ]);
        $list = $this->db->sudoers();
        if ($list === []) {
            $out .= "\n" . $this->t($chatId, 'sudo_list_no_extra');
        } else {
            foreach ($list as $u) {
                $out .= "\n" . $this->t($chatId, 'sudo_list_entry', ['user' => $this->mention($u)]);
            }
        }
        $this->send($chatId, $out);
    }

    // ── blacklist ────────────────────────────────────────────────────────────

    private function cmdBlockChat(int $chatId, int $sender, bool $block): void
    {
        if (!$this->isSudo($sender)) {
            $this->send($chatId, $this->t($chatId, 'only_sudo'));
            return;
        }
        $ok = $block ? $this->db->addBlockedChat($chatId) : $this->db->delBlockedChat($chatId);
        $key = $block
            ? ($ok ? 'blacklist_block_chat_success' : 'blacklist_unblock_chat_fail')
            : ($ok ? 'blacklist_unblock_chat_success' : 'blacklist_unblock_chat_fail');
        $this->send($chatId, $this->t($chatId, $key, [
            'id' => (string) $chatId,
            'chat' => (string) $chatId,
            'error' => 'ησ ᴄʜᴧηɢє',
        ]));
    }

    private function cmdBlockUser(int $chatId, int $sender, string $arg, TlNode $msg, bool $block): void
    {
        if (!$this->isSudo($sender)) {
            $this->send($chatId, $this->t($chatId, 'only_sudo'));
            return;
        }
        $uid = $this->targetUser($msg, $arg);
        if ($uid === 0) {
            $this->send($chatId, $this->t($chatId, 'user_extract_fail', ['error' => 'ησ ᴛᴧʀɢєᴛ']));
            return;
        }
        if ($block && $uid === $this->ownerId) {
            $this->send($chatId, $this->t($chatId, 'sudo_owner_only'));
            return;
        }
        $ok = $block ? $this->db->addBlockedUser($uid) : $this->db->delBlockedUser($uid);
        $key = $block
            ? ($ok ? 'blacklist_block_user_success' : 'generic_error')
            : ($ok ? 'blacklist_unblock_user_success' : 'blacklist_unblock_user_fail');
        $this->send($chatId, $this->t($chatId, $key, [
            'id' => (string) $uid,
            'user' => $this->mention($uid),
            'error' => 'ησ ᴄʜᴧηɢє',
        ]));
    }

    private function cmdBlacklisted(int $chatId): void
    {
        $out = $this->t($chatId, 'blacklist_list_title') . "\n\n"
            . $this->t($chatId, 'blacklist_list_users') . "\n";
        $users = $this->db->blockedUsers();
        $out .= $users === [] ? '—' : implode("\n", array_map(fn($u) => "• <code>$u</code>", $users));
        $out .= "\n\n" . $this->t($chatId, 'blacklist_list_chats') . "\n";
        $chats = $this->db->blockedChats();
        $out .= $chats === [] ? '—' : implode("\n", array_map(fn($c) => "• <code>$c</code>", $chats));
        $this->send($chatId, $out);
    }
}
