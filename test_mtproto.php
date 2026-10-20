<?php
declare(strict_types=1);
define('YUMI_BASE', __DIR__);
require __DIR__.'/src/AesIge.php';
require __DIR__.'/src/TlNode.php';
require __DIR__.'/src/TlSchema.php';
require __DIR__.'/src/Tl.php';
require __DIR__.'/src/RsaKey.php';
require __DIR__.'/src/MtProto.php';

// 1. AesIge roundtrip
$key = random_bytes(32); $iv = random_bytes(32); $data = random_bytes(64);
$enc = (new AesIge($key))->encrypt($iv, $data);
$dec = (new AesIge($key))->decrypt($iv, $enc);
echo $dec === $data ? "IGE roundtrip OK\n" : "IGE FAIL\n";

// 2. schema size
echo "schema ctors: " . TlSchema::size() . "\n";

// 3. live bot auth (with DC migration retry)
$mt = new MtProto(24168862, '916a9424dd1e58ab7955001ccc0172b3', 2);
$mt->tag = 'yumi-bot';
$mt->connect();
$mt->handshake();
$res = null;
for ($i = 0; $i < 4; $i++) {
    try {
        $res = $mt->invoke((new TlNode('auth.importBotAuthorization'))
            ->put('flags', 0)
            ->put('api_id', 24168862)
            ->put('api_hash', '916a9424dd1e58ab7955001ccc0172b3')
            ->put('bot_auth_token', '8713141466:AAHZlz1uerpqZrwe-cs5V7szhFiEOI7lKZw'), 30);
        break;
    } catch (RuntimeException $e) {
        if (preg_match('/USER_MIGRATE_(\d)|NETWORK_MIGRATE_(\d)|PHONE_MIGRATE_(\d)/', $e->getMessage(), $mm)) {
            $dc = (int) ($mm[1] ?: $mm[2] ?: $mm[3]);
            echo "migrating to dc$dc\n";
            $mt->migrateTo($dc);
            continue;
        }
        throw $e;
    }
}
echo "auth result: " . $res->name . "\n";
$me = $mt->invoke((new TlNode('users.getFullUser'))->put('id', new TlNode('inputUserSelf')), 20);
foreach ($me->list('users') as $u) {
    if ($u instanceof TlNode && $u->name === 'user') {
        echo "logged in as: " . $u->str('first_name') . " @" . $u->str('username') . " (" . $u->l('id') . ")\n";
    }
}
file_put_contents(__DIR__.'/data/bot.session', $mt->exportSession());
echo "session saved\n";
