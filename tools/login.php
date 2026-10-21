<?php
declare(strict_types=1);
/**
 * Assistant session generator.
 *
 * The bot streams into voice chats from a *user* account (a bot account cannot
 * join a group call). This script logs that user account in once and prints the
 * session string the bot needs.
 *
 *   php tools/login.php                 # writes data/assistant.session.string
 *   php tools/login.php /path/to/file   # writes somewhere else
 *
 * You need: API_ID + API_HASH from my.telegram.org, the phone number of the
 * assistant account, and the login code Telegram sends. Two-step verification
 * (cloud password) is not supported here — turn it off for this account first.
 */

define('YUMI_BASE', dirname(__DIR__));
foreach (['AesIge', 'TlNode', 'TlSchema', 'Tl', 'RsaKey', 'MtProto'] as $f) {
    require YUMI_BASE . "/src/$f.php";
}

$env = [];
foreach (file(YUMI_BASE . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), "\"'");
}

$apiId = (int) ($env['API_ID'] ?? getenv('API_ID') ?: 0);
$apiHash = (string) ($env['API_HASH'] ?? getenv('API_HASH') ?: '');
if ($apiId === 0 || $apiHash === '') {
    fwrite(STDERR, "API_ID / API_HASH missing in .env\n");
    exit(1);
}

function ask(string $label): string
{
    echo $label;
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

$phone = ask('Phone number (+9198xxxxxx): ');
if ($phone === '') {
    fwrite(STDERR, "phone number required\n");
    exit(1);
}

$mt = new MtProto($apiId, $apiHash, 4);
$mt->tag = 'login';
$mt->connect();
$mt->handshake();

$sent = $mt->invoke((new TlNode('auth.sendCode'))
    ->put('phone_number', $phone)
    ->put('api_id', $apiId)
    ->put('api_hash', $apiHash)
    ->put('settings', (new TlNode('codeSettings'))->put('flags', 0)), 30);

if ($sent->name !== 'auth.sentCode') {
    fwrite(STDERR, 'unexpected answer: ' . $sent->name . "\n");
    exit(1);
}
$hash = $sent->str('phone_code_hash');
echo "code sent to Telegram (app / SMS)\n";

$code = ask('Login code: ');
$auth = $mt->invoke((new TlNode('auth.signIn'))
    ->put('flags', 0)
    ->put('phone_number', $phone)
    ->put('phone_code_hash', $hash)
    ->put('phone_code', $code), 30);

if ($auth->name !== 'auth.authorization') {
    fwrite(STDERR, 'sign-in failed: ' . $auth->name . "\n");
    exit(1);
}

$session = (string) $mt->exportSession();
$target = $argv[1] ?? YUMI_BASE . '/data/assistant.session.string';
if (@file_put_contents($target, $session . "\n") === false) {
    fwrite(STDERR, "could not write $target\n$session\n");
    exit(1);
}

$me = $auth->node('user');
echo "\nsession saved to $target\n";
echo 'account: ' . ($me !== null ? $me->str('first_name') . ' @' . $me->str('username') : 'ok') . "\n";
echo "restart the bot to pick it up.\n";
