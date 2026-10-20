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
