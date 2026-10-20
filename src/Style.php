<?php

declare(strict_types=1);

/** Yumi style — unicode premium look (math-bold + small-caps + blockquote). */
final class YumiStyle
{
    public const HEADER = "\u{1D418}\u200c\u03c5\u03fb\u026a \u{1D405} \u03fb\u200c\u03c5s\u026a\u1d04";

    public static function header(string $body): string
    {
        return self::HEADER . ":\n\n" . $body;
    }

    public static function nowPlaying(array $t, string $by, string $pos = '', string $bar = ''): string
    {
        $dur = Util::fmtTime((int) $t['duration']);
        $title = Util::escape((string) $t['title']);
        $artist = $t['artist'] !== '' ? Util::escape((string) $t['artist']) : '𝐔‌ηᴋησωη';
        $line = "\u{1F3B5} <b>𝐍‌σᴡ 𝐏‌ʟᴧʏɪηɢ!</b>\n\n"
            . "<blockquote>❖ 𝐓‌ɪᴛʟє : $title\n"
            . "❖ 𝐀ꝛᴛɪsᴛ : $artist\n"
            . "❖ 𝐃‌υꝛᴧᴛɪση : $dur\n"
            . "❖ 𝐑‌єǫυєsᴛєᴅ 𝐁‌ʏ : $by</blockquote>";
        if ($pos !== '') {
            $line .= "\n\u{23F1} $pos $bar";
        }
        return $line;
    }

    public static function queued(array $t, int $index, string $by): string
    {
        return "\u{2795} <b>𝐀‌ᴅᴅєᴅ 𝐓‌σ 𝐐‌υєυє!</b>\n\n"
            . "<blockquote>❖ 𝐏‌σsɪᴛɪση : #$index\n"
            . "❖ 𝐓‌ɪᴛʟє : " . Util::escape((string) $t['title']) . "\n"
            . "❖ 𝐑‌єǫυєsᴛєᴅ 𝐁‌ʏ : $by</blockquote>";
    }

    public static function searchResults(array $results, string $query): string
    {
        $lines = "\u{1F50D} <b>𝐒‌єᴧꝛᴄʜ 𝐑‌єsυʟᴛs!</b>\n\n<blockquote>❖ 𝐐‌υєꝛʏ : " . Util::escape($query) . "</blockquote>\n\n";
        foreach ($results as $i => $r) {
            $dur = Util::fmtTime((int) $r['duration']);
            $lines .= "\u{279C} <b>" . ($i + 1) . ".</b> " . Util::escape($r['title']) . " \u{275B}$dur\u{275C}\n";
        }
        $lines .= "\n\u{1F447} <b>𝐁‌υᴛᴛση sє 𝐏‌ʟᴧʏ ᴋᴧꝛσ!</b>";
        return $lines;
    }

    public static function btn(string $text): string
    {
        return $text;
    }
}

final class Util
{
    public static function fmtTime(int $sec): string
    {
        if ($sec <= 0) {
            return '00:00';
        }
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        $s = $sec % 60;
        return $h > 0
            ? sprintf('%02d:%02d:%02d', $h, $m, $s)
            : sprintf('%02d:%02d', $m, $s);
    }

    public static function escape(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    /** Small-caps + bold unicode for progress bar decoration. */
    public static function bar(int $pos, int $dur): string
    {
        if ($dur <= 0) {
            return '';
        }
        $filled = (int) round(min(1.0, $pos / max(1, $dur)) * 10);
        $filled = max(0, min(10, $filled));
        return "\u{2502}" . str_repeat("\u{25E7}", $filled) . str_repeat("\u{25E6}", 10 - $filled) . "\u{2502}";
    }
}
