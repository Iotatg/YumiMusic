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
    public int $loopLeft = 0;
    public float $speed = 1.0;
    public int $statusMsg = 0;
    /** @var list<string> recent "id:<media>" / "title:<normalised>" markers */
    public array $history = [];

    private const HISTORY_CAP = 100;

    public function __construct(public readonly int $chatId)
    {
    }

    /** Record a track so autoplay never offers it again in this room. */
    public function remember(array $t): void
    {
        $id = (string) ($t['media_id'] ?? '');
        if ($id !== '') {
            $this->history[] = 'id:' . $id;
        }
        $n = Util::normTitle((string) ($t['title'] ?? ''));
        if ($n !== '') {
            $this->history[] = 'title:' . $n;
        }
        if (count($this->history) > self::HISTORY_CAP) {
            $this->history = array_slice($this->history, -self::HISTORY_CAP);
        }
    }

    /** True when this exact track (or the same song from another upload) played. */
    public function played(array $t): bool
    {
        $id = (string) ($t['media_id'] ?? '');
        if ($id !== '' && in_array('id:' . $id, $this->history, true)) {
            return true;
        }
        $n = Util::normTitle((string) ($t['title'] ?? ''));
        if ($n === '') {
            return false;
        }
        if (in_array('title:' . $n, $this->history, true)) {
            return true;
        }
        foreach ($this->history as $h) {
            if (str_starts_with($h, 'title:') && self::sameTitle($n, substr($h, 6))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Two uploads of one song rarely normalise to the same string
     * ("aavagaman" vs "aavagaman full"). Treat a near-equal title as the
     * same track, but never match short generic words.
     */
    public static function sameTitle(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b) {
            return true;
        }
        $short = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $long = $short === $a ? $b : $a;
        if (mb_strlen($short) < 8) {
            return false;
        }
        return str_contains($long, $short)
            && mb_strlen($short) / max(1, mb_strlen($long)) >= 0.6;
    }
}
