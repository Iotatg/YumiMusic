# YumiMusic (PHP / raw MTProto 2.0)

Telegram voice-chat music bot written in **pure PHP** — no Bot API, no frameworks.
It speaks **raw MTProto 2.0** (TCP intermediate transport, DH handshake, AES-IGE
via libcrypto FFI) and streams audio into voice chats with **ntgcalls 3.0.4**
(C ABI via PHP FFI) — the exact same stack as NandaniMusic.

## Architecture

```
bot.php                entry point: env, bootstrap, cooperative main loop
src/
  MtProto.php          raw MTProto 2.0 client: handshake, encrypted RPC, DC
                       migration, session import/export, cooperative pump()
  AesIge.php           AES-IGE via PHP FFI into libcrypto.so.3
  TlSchema/Tl/TlNode   schema-driven TL serialiser (mtproto + telegram + custom)
  RsaKey.php           Telegram RSA key for req_DH_params
  Ntg.php              ntgcalls 3.0.4 C-ABI binding (PHP FFI)
  Voice.php            per-chat voice engine: join VC, stream file, camera pusher
  Peers.php            peer cache (updates + periodic dialog refresh)
  Iota.php             iota search / download API client
  App.php              commands, now-playing cards, inline buttons, room engine
  Style.php            unicode-styled output cards
  MsgEntities.php      HTML → messageEntity* (UTF-16 offsets)
tl/                    .tl schemas (layer 230)
```

## Commands

`/start /help /ping /play /song /video /skip /pause /resume /stop /leave
/queue /loop /shuffle /autoplay /seek /stats`

Inline buttons: pause/resume, replay, skip, stop, autoplay toggle, close.

## Setup

1. Requirements: PHP 8.2+ (php-gmp, php-curl recommended, mbstring optional —
   a polyfill is bundled), `ffmpeg` on PATH, libntgcalls at `/opt/ntgcalls/lib/libntgcalls.so`.
2. Copy `.env` (TOKEN, API_ID, API_HASH, OWNER_ID, IOTA_API_URL, IOTA_API_KEY,
   STRING_SESSIONS — a Pyrogram/MadelineProto session for the assistant userbot).
3. `./run.sh`

The bot authenticates with `auth.importBotAuthorization` and persists its own
session to `data/bot.session`; the assistant session is imported (v1 hex or
MadelineProto base64url format) and reused across restarts.

## Notes

- **MTProto transport quirks**: session imports must generate a fresh random
  `session_id` — a zero session id makes the server answer `-404` (auth key
  not found) even for a valid key.
- **ntgcalls 3.0.4 ABI**: `ntg_create_call` outputs a `char**` (null-terminated
  SDP); `ntg_pause/resume/mute/unmute` take a `bool* out`; the SOURCE_SHELL
  input is resolved through wordexp — a bare `ffmpeg` works, a full binary
  path fails with ENOENT in this build.
