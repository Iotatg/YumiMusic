<?php

declare(strict_types=1);

/**
 * Parses the canonical Telegram TL schema (.tl files) so the client can
 * serialize/deserialize every constructor at the layer the server expects.
 */
final class TlSchema
{
    public const FLAG_WORD_MAIN = 0;
    public const FLAG_WORD_ALT = 1;

    /** @var array<string, array{name:string, id:int, fields:list<array{name:string,type:string,word:int,bit:int,flags:bool}>}> */
    private static array $byId = [];
    /** @var array<string, array> */
    private static array $byName = [];
    private static bool $loaded = false;

    /** @return array|null ctor descriptor */
    public static function byId(int $id): ?array
    {
        self::load();
        return self::$byId[$id] ?? null;
    }

    public static function byName(string $name): ?array
    {
        self::load();
        return self::$byName[$name] ?? null;
    }

    public static function size(): int
    {
        self::load();
        return count(self::$byId);
    }

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $base = defined('YUMI_BASE') ? YUMI_BASE : __DIR__;
        self::parse($base . '/tl/mtproto_api.tl');
        self::parse($base . '/tl/telegram_api.tl');
        self::parse($base . '/tl/custom.tl');
    }

    private static function parse(string $file): void
    {
        if (!is_file($file)) {
            fwrite(STDERR, "[tl] missing schema $file\n");
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $left = trim(substr($line, 0, $eq));
            $result = trim(substr($line, $eq + 1));
            if (str_starts_with($result, 'Vector') || str_starts_with($result, 'vector')) {
                continue;
            }
            if (str_starts_with($left, 'vector')) {
                continue;
            }
            $hash = strpos($left, '#');
            if ($hash === false) {
                continue;
            }
            $name = trim(substr($left, 0, $hash));
            $sp = strpos($left, ' ', $hash);
            $idHex = trim($sp === false ? substr($left, $hash + 1) : substr($left, $hash + 1, $sp - $hash - 1));
            $id = (int) hexdec($idHex);
            if ($id >= 0x80000000) {
                $id -= 0x100000000; // match TlReader's signed i32
            }
            $ctor = ['name' => $name, 'id' => $id, 'fields' => []];
            if ($sp !== false) {
                $rest = trim(substr($left, $sp + 1));
                while (($a = strpos($rest, '{')) !== false && ($z = strpos($rest, '}', $a)) !== false) {
                    $rest = trim(substr($rest, 0, $a) . ' ' . substr($rest, $z + 1));
                }
                foreach (preg_split('/\s+/', $rest) as $tok) {
                    if ($tok === '' || $tok === '#' || $tok === '[' || $tok === ']') {
                        continue;
                    }
                    $colon = strpos($tok, ':');
                    if ($colon === false) {
                        continue;
                    }
                    $fname = substr($tok, 0, $colon);
                    $ftype = substr($tok, $colon + 1);
                    if ($ftype === '#') {
                        $ctor['fields'][] = [
                            'name' => $fname, 'type' => '#',
                            'word' => $fname === 'flags2' ? 1 : 0,
                            'bit' => -1, 'flags' => true,
                        ];
                        continue;
                    }
                    $word = 0;
                    $bit = -1;
                    if (str_starts_with($ftype, 'flags2.')) {
                        $word = 1;
                        $ftype = substr($ftype, 7);
                    } elseif (str_starts_with($ftype, 'flags.')) {
                        $ftype = substr($ftype, 6);
                    }
                    $mark = strpos($ftype, '?');
                    if ($mark !== false && $mark > 0 && ctype_digit(substr($ftype, 0, $mark))) {
                        $bit = (int) substr($ftype, 0, $mark);
                        $ftype = substr($ftype, $mark + 1);
                    }
                    $ctor['fields'][] = ['name' => $fname, 'type' => $ftype, 'word' => $word, 'bit' => $bit, 'flags' => false];
                }
            }
            self::$byId[$id] = $ctor;
            self::$byName[$name] = $ctor;
        }
    }
}
