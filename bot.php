<?php

declare(strict_types=1);

error_reporting(E_ALL);
set_time_limit(0);
gc_enable();

define('YUMI_BASE', __DIR__);

// ── mbstring polyfill (deploy anywhere — no extension required) ──
if (!function_exists('mb_ord')) {
    function mb_ord(string $s, string $enc = 'UTF-8'): int
    {
        if ($enc !== 'UTF-8' || $s === '') {
            return 0;
        }
        $b = ord($s[0]);
        if ($b < 0x80) {
            return $b;
        }
        if ($b < 0xE0) {
            return (($b & 0x1F) << 6) | (ord($s[1]) & 0x3F);
        }
        if ($b < 0xF0) {
            return (($b & 0x0F) << 12) | ((ord($s[1]) & 0x3F) << 6) | (ord($s[2]) & 0x3F);
        }
        return (($b & 0x07) << 18) | ((ord($s[1]) & 0x3F) << 12) | ((ord($s[2]) & 0x3F) << 6) | (ord($s[3]) & 0x3F);
    }

    function mb_substr(string $s, int $start, ?int $len = null, string $enc = 'UTF-8'): string
    {
        if ($enc !== 'UTF-8') {
            return $s;
        }
        preg_match_all('/./us', $s, $m);
        return implode('', array_slice($m[0], $start, $len ?? PHP_INT_MAX));
    }
}

// ── env ──
$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v));
    }
}
$env = fn(string $k, string $d = '') => getenv($k) ?: $d;

require __DIR__ . '/src/AesIge.php';
require __DIR__ . '/src/TlNode.php';
require __DIR__ . '/src/TlSchema.php';
require __DIR__ . '/src/Tl.php';
require __DIR__ . '/src/RsaKey.php';
require __DIR__ . '/src/MtProto.php';
require __DIR__ . '/src/Ntg.php';
require __DIR__ . '/src/Iota.php';
require __DIR__ . '/src/UrlResolver.php';
require __DIR__ . '/src/Peers.php';
require __DIR__ . '/src/Voice.php';
require __DIR__ . '/src/Style.php';
require __DIR__ . '/src/Locales.php';
require __DIR__ . '/src/Buttons.php';
require __DIR__ . '/src/Db.php';
require __DIR__ . '/src/MsgEntities.php';
require __DIR__ . '/src/Room.php';
require __DIR__ . '/src/Extras.php';
require __DIR__ . '/src/App.php';

echo "╔══════════════════════════════════════╗\n";
echo "║   YumiMusic  v1.0.0  (PHP/MTProto)   ║\n";
echo "╚══════════════════════════════════════╝\n";

$assistantSession = null;
$assistantSource = $env('STRING_SESSIONS', '');
if ($assistantSource !== '') {
    if (str_starts_with($assistantSource, 'file:')) {
        $f = substr($assistantSource, 5);
        if (!str_starts_with($f, '/')) {
            $f = __DIR__ . '/' . $f;
        }
        if (is_file($f)) {
            $assistantSession = trim((string) file_get_contents($f));
            echo "[env] assistant session: $f\n";
        } else {
            echo "[env] assistant session file missing: $f\n";
        }
    } else {
        $assistantSession = $assistantSource;
    }
}

$vcEnabled = $env('VC_ENABLED', 'true') === 'true' && $assistantSession !== null;

$app = new App(
    token: $env('TOKEN'),
    apiId: (int) $env('API_ID', '24168862'),
    apiHash: $env('API_HASH', '916a9424dd1e58ab7955001ccc0172b3'),
    iotaUrl: $env('IOTA_API_URL', 'http://127.0.0.1:8014'),
    iotaKey: $env('IOTA_API_KEY'),
    assistantSession: $assistantSession,
    vcEnabled: $vcEnabled,
);

set_error_handler(function ($no, $str, $file, $line) {
    echo "[php] $str at $file:$line\n";
    return true;
});

$app->start();

// ── main cooperative loop ──
$lastAlive = time();
while (true) {
    MtProto::pump(0.1);
    $app->mainLoopTick();

    // connection watchdog: reconnect any dead client
    foreach (MtProto::clients() as $c) {
        if (!$c->connected()) {
            echo "[watchdog] {$c->tag} down, reconnecting…\n";
            try {
                $c->connect();
                if ($c->authKeyAvailable()) {
                    // auth key survived? no — for bot we redo full start
                }
            } catch (Throwable $e) {
                echo "[watchdog] reconnect failed: " . $e->getMessage() . "\n";
            }
        }
    }
}
