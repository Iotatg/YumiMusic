<?php

declare(strict_types=1);

/**
 * Lightweight per-chat settings store (JSON on disk) — the Yumi counterpart
 * for the settings the bot actually uses.
 */
final class Db
{
    private string $file;
    /** @var array<string, array<string, mixed>> */
    private array $data;

    public function __construct(string $file)
    {
        $this->file = $file;
        $this->data = [];
        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $this->data = $decoded;
            }
        }
    }

    public function save(): void
    {
        @file_put_contents($this->file, json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function chat(int $chatId): array
    {
        $k = 'chat:' . $chatId;
        return $this->data[$k] ?? [];
    }

    private function set(int $chatId, string $key, mixed $val): void
    {
        $k = 'chat:' . $chatId;
        $c = $this->chat($chatId);
        $c[$key] = $val;
        $this->data[$k] = $c;
        $this->save();
    }

    public function lang(int $chatId): string
    {
        return (string) ($this->chat($chatId)['lang'] ?? 'en');
    }

    public function setLang(int $chatId, string $lang): void
    {
        $this->set($chatId, 'lang', $lang);
    }

    public function autoplay(int $chatId): bool
    {
        return (bool) ($this->chat($chatId)['autoplay'] ?? false);
    }

    public function setAutoplay(int $chatId, bool $v): void
    {
        $this->set($chatId, 'autoplay', $v);
    }

    public function linkedChannel(int $chatId): int
    {
        return (int) ($this->chat($chatId)['linked'] ?? 0);
    }

    public function setLinkedChannel(int $chatId, int $channelId): void
    {
        $this->set($chatId, 'linked', $channelId);
    }

    public function thumbnailsDisabled(int $chatId): bool
    {
        return (bool) ($this->chat($chatId)['nothumb'] ?? false);
    }

    public function setThumbnailsDisabled(int $chatId, bool $v): void
    {
        $this->set($chatId, 'nothumb', $v);
    }

    /** "everyone" | "admin" — who may start playback in this chat. */
    public function playMode(int $chatId): string
    {
        return (string) ($this->chat($chatId)['playmode'] ?? 'everyone');
    }

    public function setPlayMode(int $chatId, string $mode): void
    {
        $this->set($chatId, 'playmode', $mode === 'admin' ? 'admin' : 'everyone');
    }

    public function adminMode(int $chatId): bool
    {
        return (bool) ($this->chat($chatId)['adminmode'] ?? true);
    }

    public function setAdminMode(int $chatId, bool $v): void
    {
        $this->set($chatId, 'adminmode', $v);
    }

    public function autoLeave(int $chatId): bool
    {
        return (bool) ($this->chat($chatId)['autoleave'] ?? true);
    }

    public function setAutoLeave(int $chatId, bool $v): void
    {
        $this->set($chatId, 'autoleave', $v);
    }

    public function speed(int $chatId): float
    {
        $v = (float) ($this->chat($chatId)['speed'] ?? 1.0);
        return $v >= 0.5 && $v <= 4.0 ? $v : 1.0;
    }

    public function setSpeed(int $chatId, float $v): void
    {
        $this->set($chatId, 'speed', $v);
    }

    /** @return list<int> */
    public function authUsers(int $chatId): array
    {
        $u = $this->chat($chatId)['auth'] ?? [];
        return is_array($u) ? array_values(array_map('intval', $u)) : [];
    }

    public function isAuth(int $chatId, int $userId): bool
    {
        return in_array($userId, $this->authUsers($chatId), true);
    }

    public function addAuth(int $chatId, int $userId): bool
    {
        $u = $this->authUsers($chatId);
        if (in_array($userId, $u, true)) {
            return false;
        }
        $u[] = $userId;
        $this->set($chatId, 'auth', $u);
        return true;
    }

    public function delAuth(int $chatId, int $userId): bool
    {
        $u = $this->authUsers($chatId);
        $i = array_search($userId, $u, true);
        if ($i === false) {
            return false;
        }
        unset($u[$i]);
        $this->set($chatId, 'auth', array_values($u));
        return true;
    }

    /** @return array{count:int,reasons:list<string>} */
    public function warns(int $chatId, int $userId): array
    {
        $w = $this->chat($chatId)['warns'][(string) $userId] ?? null;
        if (!is_array($w)) {
            return ['count' => 0, 'reasons' => []];
        }
        return [
            'count' => (int) ($w['count'] ?? 0),
            'reasons' => is_array($w['reasons'] ?? null) ? array_values($w['reasons']) : [],
        ];
    }

    public function addWarn(int $chatId, int $userId, string $reason): int
    {
        $c = $this->chat($chatId);
        $w = $this->warns($chatId, $userId);
        $w['count']++;
        if ($reason !== '') {
            $w['reasons'][] = $reason;
        }
        $c['warns'][(string) $userId] = $w;
        $this->data['chat:' . $chatId] = $c;
        $this->save();
        return $w['count'];
    }

    public function resetWarns(int $chatId, int $userId): int
    {
        $c = $this->chat($chatId);
        $w = $this->warns($chatId, $userId);
        unset($c['warns'][(string) $userId]);
        $this->data['chat:' . $chatId] = $c;
        $this->save();
        return $w['count'];
    }

    public function warnLimit(int $chatId): int
    {
        $v = (int) ($this->chat($chatId)['warnlimit'] ?? 3);
        return $v >= 2 && $v <= 20 ? $v : 3;
    }

    public function setWarnLimit(int $chatId, int $v): void
    {
        $this->set($chatId, 'warnlimit', $v);
    }

    public function warnMode(int $chatId): string
    {
        $m = (string) ($this->chat($chatId)['warnmode'] ?? 'ban');
        return in_array($m, ['ban', 'kick', 'mute'], true) ? $m : 'ban';
    }

    public function setWarnMode(int $chatId, string $m): void
    {
        $this->set($chatId, 'warnmode', $m);
    }

    // ── notes ────────────────────────────────────────────────────────────────

    /** @return array<string,string> name → body */
    public function notes(int $chatId): array
    {
        $n = $this->chat($chatId)['notes'] ?? [];
        return is_array($n) ? $n : [];
    }

    public function note(int $chatId, string $name): string
    {
        return (string) ($this->notes($chatId)[$name] ?? '');
    }

    public function setNote(int $chatId, string $name, string $text): void
    {
        $c = $this->chat($chatId);
        $c['notes'][$name] = $text;
        $this->data['chat:' . $chatId] = $c;
        $this->save();
    }

    public function delNote(int $chatId, string $name): bool
    {
        $c = $this->chat($chatId);
        if (!isset($c['notes'][$name])) {
            return false;
        }
        unset($c['notes'][$name]);
        $this->data['chat:' . $chatId] = $c;
        $this->save();
        return true;
    }

    // ── filters ──────────────────────────────────────────────────────────────

    /** @return array<string,string> keyword → reply */
    public function filters(int $chatId): array
    {
        $f = $this->chat($chatId)['filters'] ?? [];
        return is_array($f) ? $f : [];
    }

    public function saveFilter(int $chatId, string $keyword, string $text): void
    {
        $c = $this->chat($chatId);
        $c['filters'][mb_strtolower($keyword)] = $text;
        $this->data['chat:' . $chatId] = $c;
        $this->save();
    }

    public function delFilter(int $chatId, string $keyword): bool
    {
        $k = mb_strtolower($keyword);
        $c = $this->chat($chatId);
        if (!isset($c['filters'][$k])) {
            return false;
        }
        unset($c['filters'][$k]);
        $this->data['chat:' . $chatId] = $c;
        $this->save();
        return true;
    }

    public function clearFilters(int $chatId): int
    {
        $c = $this->chat($chatId);
        $n = count($c['filters'] ?? []);
        unset($c['filters']);
        $this->data['chat:' . $chatId] = $c;
        $this->save();
        return $n;
    }

    /** First filter whose keyword appears in $text (case-insensitive). */
    public function matchFilter(int $chatId, string $text): ?string
    {
        $low = mb_strtolower($text);
        foreach ($this->filters($chatId) as $kw => $reply) {
            if ($kw !== '' && str_contains($low, $kw)) {
                return $reply;
            }
        }
        return null;
    }

    // ── playlists (per user) ─────────────────────────────────────────────────

    private function user(int $userId): array
    {
        $u = $this->data['user:' . $userId] ?? [];
        return is_array($u) ? $u : [];
    }

    /** @return array<string, list<array{title:string,url:string,duration:int,by:string}>> */
    public function playlists(int $userId): array
    {
        $p = $this->user($userId)['playlists'] ?? [];
        return is_array($p) ? $p : [];
    }

    public function playlistEntries(int $userId, string $name): array
    {
        $e = $this->playlists($userId)[$name] ?? [];
        return is_array($e) ? $e : [];
    }

    public function createPlaylist(int $userId, string $name): bool
    {
        $u = $this->user($userId);
        if (isset($u['playlists'][$name])) {
            return false;
        }
        $u['playlists'][$name] = [];
        $this->data['user:' . $userId] = $u;
        $this->save();
        return true;
    }

    public function deletePlaylist(int $userId, string $name): bool
    {
        $u = $this->user($userId);
        if (!isset($u['playlists'][$name])) {
            return false;
        }
        unset($u['playlists'][$name]);
        $this->data['user:' . $userId] = $u;
        $this->save();
        return true;
    }

    public function addToPlaylist(int $userId, string $name, array $entry): bool
    {
        $u = $this->user($userId);
        if (!isset($u['playlists'][$name])) {
            return false;
        }
        $u['playlists'][$name][] = $entry;
        $this->data['user:' . $userId] = $u;
        $this->save();
        return true;
    }

    public function removeFromPlaylist(int $userId, string $name, int $index): bool
    {
        $u = $this->user($userId);
        if (!isset($u['playlists'][$name][$index])) {
            return false;
        }
        array_splice($u['playlists'][$name], $index, 1);
        $this->data['user:' . $userId] = $u;
        $this->save();
        return true;
    }

    // ── chat feature switches ────────────────────────────────────────────────

    public function welcomeOn(int $chatId): bool
    {
        return (bool) ($this->chat($chatId)['welcome'] ?? false);
    }

    public function setWelcomeOn(int $chatId, bool $v): void
    {
        $this->set($chatId, 'welcome', $v);
    }

    public function cleanMode(int $chatId): bool
    {
        return (bool) ($this->chat($chatId)['cleanmode'] ?? false);
    }

    public function setCleanMode(int $chatId, bool $v): void
    {
        $this->set($chatId, 'cleanmode', $v);
    }

    public function loggerOn(int $chatId): bool
    {
        return (bool) ($this->chat($chatId)['logger'] ?? false);
    }

    public function setLogger(int $chatId, bool $v): void
    {
        $this->set($chatId, 'logger', $v);
    }

    // ── global provider preference ───────────────────────────────────────────

    /** "auto" | "iota" | "ritesh" — which search/download backend runs first. */
    public function provider(): string
    {
        $p = (string) ($this->data['provider'] ?? 'auto');
        return in_array($p, ['auto', 'iota', 'ritesh'], true) ? $p : 'auto';
    }

    public function setProvider(string $p): void
    {
        $this->data['provider'] = in_array($p, ['auto', 'iota', 'ritesh'], true) ? $p : 'auto';
        $this->save();
    }

    // ── global sudo list ─────────────────────────────────────────────────────

    /** @return list<int> */
    public function sudoers(): array
    {
        $l = $this->data['sudoers'] ?? [];
        return is_array($l) ? array_values(array_map('intval', $l)) : [];
    }

    public function isSudo(int $userId): bool
    {
        return $userId !== 0 && in_array($userId, $this->sudoers(), true);
    }

    public function addSudo(int $userId): bool
    {
        $l = $this->sudoers();
        if (in_array($userId, $l, true)) {
            return false;
        }
        $l[] = $userId;
        $this->data['sudoers'] = $l;
        $this->save();
        return true;
    }

    public function delSudo(int $userId): bool
    {
        $l = $this->sudoers();
        $i = array_search($userId, $l, true);
        if ($i === false) {
            return false;
        }
        unset($l[$i]);
        $this->data['sudoers'] = array_values($l);
        $this->save();
        return true;
    }

    // ── global blacklist ─────────────────────────────────────────────────────

    /** @return list<int> */
    public function blockedChats(): array
    {
        $l = $this->data['blocked_chats'] ?? [];
        return is_array($l) ? array_values(array_map('intval', $l)) : [];
    }

    public function isBlockedChat(int $chatId): bool
    {
        return in_array($chatId, $this->blockedChats(), true);
    }

    public function addBlockedChat(int $chatId): bool
    {
        $l = $this->blockedChats();
        if (in_array($chatId, $l, true)) {
            return false;
        }
        $l[] = $chatId;
        $this->data['blocked_chats'] = $l;
        $this->save();
        return true;
    }

    public function delBlockedChat(int $chatId): bool
    {
        $l = $this->blockedChats();
        $i = array_search($chatId, $l, true);
        if ($i === false) {
            return false;
        }
        unset($l[$i]);
        $this->data['blocked_chats'] = array_values($l);
        $this->save();
        return true;
    }

    /** @return list<int> */
    public function blockedUsers(): array
    {
        $l = $this->data['blocked_users'] ?? [];
        return is_array($l) ? array_values(array_map('intval', $l)) : [];
    }

    public function isBlockedUser(int $userId): bool
    {
        return $userId !== 0 && in_array($userId, $this->blockedUsers(), true);
    }

    public function addBlockedUser(int $userId): bool
    {
        $l = $this->blockedUsers();
        if (in_array($userId, $l, true)) {
            return false;
        }
        $l[] = $userId;
        $this->data['blocked_users'] = $l;
        $this->save();
        return true;
    }

    public function delBlockedUser(int $userId): bool
    {
        $l = $this->blockedUsers();
        $i = array_search($userId, $l, true);
        if ($i === false) {
            return false;
        }
        unset($l[$i]);
        $this->data['blocked_users'] = array_values($l);
        $this->save();
        return true;
    }

    /** All chat ids the bot has served (broadcast targets). */
    public function chatIds(): array
    {
        $out = [];
        foreach (array_keys($this->data) as $k) {
            if (str_starts_with((string) $k, 'chat:')) {
                $out[] = (int) substr((string) $k, 5);
            }
        }
        return $out;
    }

    public function servedChats(): int
    {
        return count($this->chatIds());
    }
}
