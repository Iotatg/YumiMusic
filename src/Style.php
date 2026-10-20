<?php

declare(strict_types=1);

/**
 * Pure formatting/parsing helpers — exact port of Nandani's Util.java,
 * including the signature stylish() output style (math-bold capital +
 * ZWNJ word starts + small caps bodies).
 */
final class Util
{
    public const SMALL_CAPS = [
        'ᴧ', 'ʙ', 'ᴄ', 'ᴅ', 'є', 'ғ', 'ɢ', 'ʜ', 'ɪ', 'ᴊ', 'ᴋ', 'ʟ', 'ϻ',
        'η', 'σ', 'ᴘ', 'ǫ', 'ꝛ', 's', 'ᴛ', 'υ', 'ᴠ', 'ᴡ', 'x', 'ʏ', 'ᴢ',
    ];

    private const GLYPH_PAIRS = 'ᴀ:a,ʙ:b,ᴄ:c,ᴅ:d,ᴇ:e,є:e,ғ:f,ɢ:g,ʜ:h,ɪ:i,ᴊ:j,ᴋ:k,ʟ:l,'
        . 'ϻ:m,ᴍ:m,η:n,ɴ:n,σ:o,ᴏ:o,ᴘ:p,ǫ:q,ʀ:r,ꝛ:r,ᴛ:t,υ:u,ᴜ:u,'
        . 'ᴠ:v,ᴡ:w,ʏ:y,ᴢ:z,ᴧ:a';

    /** @var array<string,string> styled-glyph → ASCII */
    private static array $glyphToAscii = [];

    private static function glyphMap(): array
    {
        if (self::$glyphToAscii === []) {
            foreach (explode(',', self::GLYPH_PAIRS) as $p) {
                self::$glyphToAscii[mb_substr($p, 0, 1)] = mb_substr($p, 2, 1);
            }
        }
        return self::$glyphToAscii;
    }

    public static function formatDuration(int $sec): string
    {
        $sec = max(0, $sec);
        if ($sec < 60) return $sec . 's';
        if ($sec < 3600) return intdiv($sec, 60) . 'm ' . ($sec % 60) . 's';
        if ($sec < 86400) return intdiv($sec, 3600) . 'h ' . intdiv($sec % 3600, 60) . 'm';
        return intdiv($sec, 86400) . 'd ' . intdiv($sec % 86400, 3600) . 'h';
    }

    private static function padZero(int $v): string
    {
        return $v < 10 ? '0' . $v : (string) $v;
    }

    public static function formatTime(int $sec): string
    {
        $sec = max(0, $sec);
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        if ($h > 0) return $h . ':' . self::padZero($m) . ':' . self::padZero($s);
        return self::padZero($m) . ':' . self::padZero($s);
    }

    /** Back-compat alias. */
    public static function fmtTime(int $sec): string
    {
        return self::formatTime($sec);
    }

    public static function formatUptime(int $seconds): string
    {
        $s = max(0, $seconds);
        $r = '';
        $days = intdiv($s, 86400);
        $s %= 86400;
        $hours = intdiv($s, 3600);
        $s %= 3600;
        $minutes = intdiv($s, 60);
        $secs = $s % 60;
        if ($days > 0) $r .= $days . 'd ';
        if ($r !== '' || $hours > 0) $r .= $hours . 'h:';
        return $r . $minutes . 'm:' . self::padZero($secs) . 's';
    }

    private const BAR = [
        '◉———————', '—◉——————', '——◉—————', '———◉————', '————◉———',
        '—————◉——', '——————◉—', '———————◉', '————————◉',
    ];

    public static function getProgressBar(int $playedSec, int $durationSec): string
    {
        if ($durationSec <= 0 || $playedSec <= 0) return self::BAR[0];
        if ($playedSec >= $durationSec) return self::BAR[8];
        $index = intdiv($playedSec * 9, $durationSec);
        if ($index > 8) $index = 8;
        return self::BAR[$index];
    }

    /** Byte-based truncation with U+2026 suffix. */
    public static function shortTitle(string $title, int $max): string
    {
        if (strlen($title) <= $max) return $title;
        $out = '';
        $len = 0;
        foreach (mb_str_split($title) as $ch) {
            $bl = strlen($ch);
            if ($len + $bl > $max) break;
            $out .= $ch;
            $len += $bl;
        }
        return $out . '…';
    }

    public static function escapeHtml(string $s): string
    {
        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $s);
    }

    /** Back-compat alias. */
    public static function escape(string $s): string
    {
        return self::escapeHtml($s);
    }

    public static function htmlMention(string $name, int $id): string
    {
        $n = trim($name);
        if ($n === '') $n = 'user';
        return '<a href="tg://user?id=' . $id . '">' . self::escapeHtml($n) . '</a>';
    }

    // ── signature style engine ───────────────────────────────────────────────

    /** Astral (supplementary-plane) styled letters → ASCII, by code point. */
    private static function astralToAscii(int $cp): int
    {
        $idx = -1;
        if ($cp >= 0x1D400 && $cp <= 0x1D433) $idx = $cp - 0x1D400;      // Mathematical Bold
        elseif ($cp >= 0x1D468 && $cp <= 0x1D49B) $idx = $cp - 0x1D468;  // Bold Italic
        elseif ($cp >= 0x1D538 && $cp <= 0x1D56B) $idx = $cp - 0x1D538;  // Double-struck
        elseif ($cp >= 0x1D5D4 && $cp <= 0x1D60B) $idx = $cp - 0x1D5D4;  // Sans-serif Bold
        elseif ($cp >= 0x1D670 && $cp <= 0x1D6A3) $idx = $cp - 0x1D670;  // Monospace
        if ($idx < 0) return 0;
        return $idx < 26 ? 0x41 + $idx : 0x61 + ($idx - 26);
    }

    /** Pre-pass: rewrite astral styled letters to ASCII before the char loop. */
    private static function normalize(string $in): string
    {
        if (!preg_match('/[\x{1D400}-\x{1D6A3}]/u', $in)) {
            return $in;
        }
        return preg_replace_callback('/[\x{1D400}-\x{1D6A3}]/u', function ($m) {
            $cp = ord($m[0][0]) < 0x80 ? -1 : 0;
            $cps = unpack('V', mb_convert_encoding($m[0], 'UTF-32LE', 'UTF-8'))[1];
            $a = self::astralToAscii($cps);
            return $a > 0 ? chr($a) : $m[0];
        }, $in) ?? $in;
    }

    /**
     * Signature output style: every word opens with a Mathematical-Bold
     * capital + ZWNJ, the rest of the word is small caps
     * (e.g. "Music Stream" → "𝐌‌υsɪᴄ 𝐒‌ᴛꝛєᴧᴍ").
     *
     * Skipped untouched: HTML tags, <code>…</code> spans (commands stay
     * typeable), HTML entities, and (for templates) {placeholder} args.
     */
    private static function styleCore(string $input, bool $protectArgs): string
    {
        if ($input === '') return '';
        $input = self::normalize($input);
        $chars = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        $n = count($chars);
        $inTag = false; $inArg = false; $inEntity = false; $inCode = false;
        $wordStart = true; $slashWord = false; $inBracket = false;
        $glyphs = self::glyphMap();

        for ($i = 0; $i < $n; $i++) {
            $ch = $chars[$i];
            if ($ch === '<') {
                $inTag = true;
                if (implode('', array_slice($chars, $i, 6)) === '<code>') $inCode = true;
                $out .= $ch;
                continue;
            }
            if ($inTag) {
                $out .= $ch;
                if ($ch === '>') {
                    $inTag = false;
                    if ($i >= 6 && implode('', array_slice($chars, $i - 6, 7)) === '</code>') $inCode = false;
                }
                continue;
            }
            if ($protectArgs && $ch === '{') {
                $inArg = true;
                $out .= $ch;
                continue;
            }
            if ($protectArgs && $inArg) {
                $out .= $ch;
                if ($ch === '}') $inArg = false;
                continue;
            }
            if ($ch === '&') {
                $inEntity = true;
                $out .= $ch;
                continue;
            }
            if ($inEntity) {
                $out .= $ch;
                if ($ch === ';') $inEntity = false;
                elseif ($ch === ' ' || $ch === '<') $inEntity = false;
                continue;
            }
            $isAscii = strlen($ch) === 1 && (($ch >= 'A' && $ch <= 'Z') || ($ch >= 'a' && $ch <= 'z'));
            $mapped = (!$isAscii && !in_array($ch, ['<', '>'], true)) ? ($glyphs[$ch] ?? null) : null;
            if ($mapped !== null && !$inCode) {
                $ch = $mapped;
                $isAscii = true;
            } elseif (!$isAscii && $mapped === null) {
                if ($ch === "\u{200C}") continue; // stray ZWNJ in template text
            }
            if ($isAscii) {
                if ($inCode || $slashWord) {
                    $out .= $ch;
                    continue;
                }
                $up = strtoupper($ch);
                $isUpper = $ch >= 'A' && $ch <= 'Z';
                if ($wordStart || $isUpper) {
                    // word-initial letters and UPPER-CASE letters inside a word
                    // get the Mathematical-Bold treatment ("OS" → 𝐎‌𝐒,
                    // "CPlay" → 𝐂‌𝐏‌ʟᴧʏ), lowercase gets small caps.
                    $out .= mb_chr(0x1D400 + (ord($up) - 0x41), 'UTF-8') . "\u{200C}";
                    $wordStart = false;
                } else {
                    $out .= self::SMALL_CAPS[ord(strtolower($ch)) - 0x61];
                }
            } else {
                // digits consume the word-start flag ("780ms" → "780ϻs");
                // the apostrophe inside a word doesn't start a new one either.
                if ($ch >= '0' && $ch <= '9') {
                    $wordStart = false;
                } elseif ($ch !== "'" && $ch !== '_' && $ch !== "\u{200C}") {
                    // '/cmd' and '@mention' stay unstyled so they remain
                    // executable / resolvable; '[...]' spans stay plain.
                    if ($ch === '/' || $ch === '@' || $ch === '[') {
                        $slashWord = true;
                        if ($ch === '[') $inBracket = true;
                    } elseif ($ch === ']') {
                        $inBracket = false;
                        $slashWord = false;
                        $wordStart = true;
                    } elseif (!$inBracket && ($ch === ' ' || $ch === "\n" || $ch === "\t"
                        || $ch === ')' || $ch === ',' || $ch === '"' || $ch === '…')) {
                        $slashWord = false;
                        $wordStart = true;
                    }
                }
                $out .= $ch;
            }
        }
        return $out;
    }

    /** Same as stylish() but leaves {placeholder} spans alone. */
    public static function stylishTemplate(string $input): string
    {
        return self::styleCore($input, true);
    }

    /** Smallcaps for ASCII lowercase outside tags. */
    public static function stylish(string $input): string
    {
        return self::styleCore($input, false);
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['K', 'M', 'G', 'T', 'P', 'E'];
        if ($bytes < 1024) return $bytes . ' B';
        $n = $bytes / 1024.0;
        $exp = 0;
        while ($n >= 1024.0 && $exp + 1 < count($units)) {
            $n /= 1024.0;
            $exp++;
        }
        return sprintf('%.1f %siB', $n, $units[$exp]);
    }

    // ── system metrics ───────────────────────────────────────────────────────

    /** "used / total GB" from /proc/meminfo. */
    public static function ramInfo(): string
    {
        [$total, $avail] = self::memTotals();
        if ($total <= 0) return 'N/A';
        return sprintf('%.2f / %.2f GB', $total - $avail, $total);
    }

    /** "used / total GiB" for RAM. */
    public static function ramGiB(): string
    {
        [$total, $avail] = self::memTotals();
        if ($total <= 0) return 'N/A';
        return sprintf('%.2f / %.2f GiB', $total - $avail, $total);
    }

    /** @return float[] [totalGiB, availGiB] */
    private static function memTotals(): array
    {
        $total = 0.0;
        $avail = 0.0;
        $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) < 2) continue;
            $v = (float) $p[1] / 1024.0 / 1024.0;
            if ($p[0] === 'MemTotal:') $total = $v;
            elseif ($p[0] === 'MemAvailable:') $avail = $v;
        }
        return [$total, $avail];
    }

    public static function diskInfo(): string
    {
        return self::diskStr('%');
    }

    public static function diskGiB(): string
    {
        return self::diskStr('GiB');
    }

    private static function diskStr(string $unit): string
    {
        $total = @disk_total_space('/') ?: 0;
        $free = @disk_free_space('/') ?: 0;
        if ($total <= 0) return 'N/A';
        if ($unit === 'GiB') {
            $gb = 1024.0 ** 3;
            return sprintf('%.2f / %.2f GiB', ($total - $free) / $gb, $total / $gb);
        }
        return sprintf('%.1f%%', ($total - $free) / $total * 100.0);
    }

    public static function ramPercent(): string
    {
        [$total, $avail] = self::memTotals();
        if ($total <= 0) return 'N/A';
        return sprintf('%.1f%%', ($total - $avail) / $total * 100.0);
    }

    private static int $lastCpuUsec = 0;
    private static int $lastCpuAt = 0;

    /** CPU load % from the cgroup v2 counter, falling back to loadavg / ncpu. */
    public static function cpuInfo(): string
    {
        $usage = -1;
        $stat = @file('/sys/fs/cgroup/cpu.stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($stat as $line) {
            if (str_starts_with($line, 'usage_usec ')) {
                $usage = (int) trim(substr($line, 11));
                break;
            }
        }
        $now = (int) (microtime(true) * 1e9);
        $pct = 0.0;
        if ($usage >= 0) {
            if (self::$lastCpuAt > 0 && $usage >= self::$lastCpuUsec) {
                $dt = ($now - self::$lastCpuAt) / 1e9;
                if ($dt > 0) $pct = ($usage - self::$lastCpuUsec) / 1e6 / $dt * 100.0;
            }
            self::$lastCpuUsec = $usage;
            self::$lastCpuAt = $now;
        } else {
            $load = trim((string) @file_get_contents('/proc/loadavg'));
            $first = (float) (explode(' ', $load)[0] ?? 0);
            $ncpu = max(1, (int) shell_exec('nproc') ?: 1);
            $pct = $first / $ncpu * 100.0;
        }
        return sprintf('%.2f%%', $pct);
    }
}
