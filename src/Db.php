<?php

declare(strict_types=1);

/**
 * Lightweight per-chat settings store (JSON on disk) — the Yumi counterpart
 * of Nandani's Database module for the settings the bot actually uses.
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

    private function save(): void
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

    public function servedChats(): int
    {
        return count($this->data);
    }
}
