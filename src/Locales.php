<?php

declare(strict_types=1);

/**
 * YAML localization engine — exact port of Nandani's Locales.java.
 * Flat-YAML parser: `key:` at column 0; quoted or plain scalars;
 * block scalars `|` (keep newlines) and `>` (fold to spaces).
 */
final class Locales
{
    /** @var array<string, array<string, string>> */
    private array $langs = [];
    private string $defaultLang = 'en';
    private string $botName = '𝐘‌υϻɪ 𝘅 ϻ‌υsɪᴄ';

    private static ?Locales $instance = null;
    private static ?string $currentCommand = null;

    public static function load(string $dir): Locales
    {
        $l = new self();
        foreach (glob(rtrim($dir, '/') . '/*.yml') ?: [] as $p) {
            $stem = basename($p, '.yml');
            $l->langs[$stem] = self::parseYamlFlat((string) file_get_contents($p));
        }
        if ($l->langs === []) {
            throw new RuntimeException("locales: no yml in $dir");
        }
        self::$instance = $l;
        return $l;
    }

    public static function i(): Locales
    {
        if (self::$instance === null) {
            self::load(dirname(__DIR__) . '/locales');
        }
        return self::$instance;
    }

    public static function setCurrentCommand(?string $command): void
    {
        self::$currentCommand = $command;
    }

    public function setDefaultLang(string $lang): void
    {
        $this->defaultLang = $lang;
    }

    public function defaultLang(): string
    {
        return $this->defaultLang;
    }

    /** @return string[] */
    public function available(): array
    {
        $codes = array_keys($this->langs);
        sort($codes);
        return $codes;
    }

    /** Fallback chain: lang -> defaultLang -> en; missing -> "[key]". */
    public function get(string $lang, string $key, array $args = []): string
    {
        foreach ([$lang, $this->defaultLang, 'en'] as $l) {
            if ($l === null) {
                continue;
            }
            $m = $this->langs[$l] ?? null;
            if ($m !== null && array_key_exists($key, $m)) {
                $text = Util::stylishTemplate(self::expandEmojis($m[$key]));
                $text = $this->substitute($text, $args);
                $text = str_replace('{bot}', $this->botName, $text);
                if (self::$currentCommand !== null) {
                    $text = str_replace('{cmd}', self::$currentCommand, $text);
                }
                // Optional template lines ({speed_line} etc.) that no caller fills
                // must not leak as literal placeholders — drop those lines.
                $text = preg_replace('/^[ \t]*\{[a-z0-9_]+\}[ \t]*\R?/mi', '', $text) ?? $text;
                return $text;
            }
        }
        fwrite(STDERR, "[locales] missing key $key\n");
        return "[$key]";
    }

    public function setBotName(string $name): void
    {
        if ($name !== null && trim($name) !== '') {
            $this->botName = $name;
        }
    }

    public function f(string $lang, string $key, string ...$kv): string
    {
        $args = [];
        for ($i = 0; $i + 1 < count($kv); $i += 2) {
            $args[$kv[$i]] = $kv[$i + 1];
        }
        return $this->get($lang, $key, $args);
    }

    /** @param array<string,string> $args */
    private function substitute(string $t, array $args): string
    {
        foreach ($args as $k => $v) {
            $t = str_replace('{' . $k . '}', $v ?? '', $t);
        }
        return $t;
    }

    /** {emoji:X} renders as the bare emoji. */
    public static function expandEmojis(string $t): string
    {
        return preg_replace_callback('/\{emoji:([^}]*)\}/', fn($m) => $m[1], $t) ?? $t;
    }

    // ---- flat YAML parser ----

    /** @return array<string,string> */
    private static function parseYamlFlat(string $text): array
    {
        $map = [];
        $key = '';
        $buf = [];
        $mode = ' ';

        foreach (explode("\n", $text) as $raw) {
            $line = rtrim($raw, "\r");
            if (trim($line) === '') {
                if ($key === '' || $mode !== '|') {
                    continue;
                }
                $buf[] = '';
                continue;
            }
            $trimmed = ltrim($line);
            if ($trimmed[0] === '#') {
                continue;
            }
            $ind = strlen($line) - strlen($trimmed);
            if ($ind === 0) {
                self::flush($key, $buf, $mode, $map);
                $key = '';
                $buf = [];
                $mode = ' ';
                $c = strpos($line, ':');
                if ($c !== false) {
                    $key = trim(substr($line, 0, $c));
                    $v = trim(substr($line, $c + 1));
                    if ($v === '|' || $v === '>') {
                        $mode = $v;
                    } elseif ($v !== '') {
                        $map[$key] = self::unquote($v);
                        $key = '';
                    }
                }
            } elseif ($key !== '') {
                $buf[] = trim($line);
            }
        }
        self::flush($key, $buf, $mode, $map);
        return $map;
    }

    /** @param string[] $buf @param array<string,string> $map */
    private static function flush(string $key, array $buf, string $mode, array &$map): void
    {
        if ($key === '' || $buf === []) {
            return;
        }
        $val = $mode === '|' ? implode("\n", $buf) : implode(' ', $buf);
        $map[$key] = self::unquote(rtrim($val));
    }

    private static function unquote(string $v): string
    {
        $s = trim($v);
        $n = strlen($s);
        if ($n >= 2 && $s[0] === '"' && $s[$n - 1] === '"') {
            $inner = substr($s, 1, -1);
            $out = '';
            $len = strlen($inner);
            for ($i = 0; $i < $len; $i++) {
                $c = $inner[$i];
                if ($c !== '\\') {
                    $out .= $c;
                    continue;
                }
                if (++$i >= $len) {
                    $out .= '\\';
                    break;
                }
                $nx = $inner[$i];
                switch ($nx) {
                    case 'n': $out .= "\n"; break;
                    case 't': $out .= "\t"; break;
                    case 'r': $out .= "\r"; break;
                    case '"': $out .= '"'; break;
                    case '\\': $out .= '\\'; break;
                    case 'u':
                        if ($i + 4 < $len) {
                            $hex = substr($inner, $i + 1, 4);
                            if (ctype_xdigit($hex)) {
                                $out .= json_decode('"\u' . strtolower($hex) . '"') ?? '';
                                $i += 4;
                                break;
                            }
                        }
                        $out .= '\u';
                        break;
                    default:
                        $out .= '\\' . $nx;
                }
            }
            return $out;
        }
        if ($n >= 2 && $s[0] === "'" && $s[$n - 1] === "'") {
            return str_replace("''", "'", substr($s, 1, -1));
        }
        return $s;
    }
}
