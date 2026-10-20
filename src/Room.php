<?php

declare(strict_types=1);

/** Per-chat playback state. */
final class Room
{
    public array $queue = [];
    public ?array $track = null;
    public string $path = '';
    public bool $playing = false;
    public bool $paused = false;
    public int $position = 0;
    public int $endStreak = 0;
    public bool $autoplay = false;
    public bool $loop = false;
    public int $statusMsg = 0;

    public function __construct(public readonly int $chatId)
    {
    }
}
