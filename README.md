<p align="center">
  <img src="https://capsule-render.vercel.app/api?type=waving&color=gradient&customColorList=6,11,19,26&height=220&section=header&text=Yumi%20Music&fontSize=60&fontColor=ffffff&animation=fadeIn&fontAlignY=38&desc=Telegram%20Voice%20Chat%20Music%20Engine%20in%20Pure%20PHP&descAlignY=60&descAlign=50" width="100%"/>
</p>

<p align="center">
  <img src="https://readme-typing-svg.demolab.com?font=Fira+Code&weight=600&size=20&pause=1000&color=00E5FF&center=true&vCenter=true&width=640&lines=Pure+PHP+8+Streaming+Engine;Raw+MTProto+2.0+%E2%80%94+No+Bot+HTTP+API;ntgcalls+3.0.4+via+PHP+FFI;Live+Message+Editing+%2B+Download+CANCEL;Multi-Language+YAML+Locales;Developed+%26+Maintained+by+%40im_abz" alt="Typing SVG" />
</p>

<p align="center">
  <a href="https://github.com/Iotatg/YumiMusic/stargazers">
    <img src="https://img.shields.io/badge/GitHub-Stars-FFD700?style=for-the-badge&logo=github&logoColor=white" alt="GitHub Stars">
  </a>
  <a href="https://github.com/Iotatg/YumiMusic/fork">
    <img src="https://img.shields.io/badge/GitHub-Forks-00C853?style=for-the-badge&logo=github&logoColor=white" alt="GitHub Forks">
  </a>
  <a href="https://www.php.net/">
    <img src="https://img.shields.io/badge/PHP-8.2+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  </a>
  <a href="https://core.telegram.org/api/mtproto">
    <img src="https://img.shields.io/badge/MTProto-Raw%202.0-2CA5E0?style=for-the-badge&logo=telegram&logoColor=white" alt="MTProto">
  </a>
  <a href="https://github.com/Iotatg/YumiMusic/blob/main/LICENSE">
    <img src="https://img.shields.io/badge/License-MIT-blue?style=for-the-badge&logo=opensourceinitiative&logoColor=white" alt="License">
  </a>
</p>

---

## ✨ Features & Highlights

- ⚡ **Ultra-Low Latency Streaming:** A lean PHP 8 core wired straight into `ntgcalls` 3.0.4 through PHP **FFI**, delivering crystal-clear voice-chat playback — audio **and** video — with minimal overhead.
- 📡 **Raw MTProto 2.0 Client:** A from-scratch MTProto stack (TCP intermediate, DH handshake, AES-IGE via `libcrypto`, SHA-256 key derivation, `phone.joinGroupCall` SDP exchange) instead of the Bot HTTP API, so every status message is **edited in place** through the full search → download → now-playing chain.
- 🎵 **Multi-Platform Provider Tiers:** Resolution order **Iota API (self-hosted)** → **yt-dlp** → direct HTTP. Audio, video, live streams, Telegram files and direct URLs all supported.
- 🔗 **Smart Link Resolver:** Spotify, Apple Music, Deezer, YouTube, SoundCloud and short links are resolved to real tracks automatically — with diacritic folding and artist-aware matching.
- 🧠 **Iota Stream Proxy:** The bundled [Iota-api](https://github.com/Iotatg/Iota-api) backend fans out across parallel googlevideo connections (multi-connection range streaming), so playback starts fast and stays smooth.
- 🖼️ **Signature Cards:** Bold-first-letter + small-caps titles, expandable blockquotes, photo now-playing cards with auto artwork, coloured inline buttons and compact clickable queue lists.
- 🛑 **Real Download Cancellation:** Live progress editing plus a working `/cancel` that aborts the transfer mid-stream.
- 🎛️ **Full Playback Controls:** Seek, speed (ffmpeg `atempo` chain), mute, loop, shuffle, autoplay, force-play variants and complete queue management.
- 🌐 **Multi-Language Engine:** Hot-reloadable YAML locale packs with blockquote-aware formatting.
- 🛡️ **Admin & Security:** Granular sudo management, per-chat authorization, owner-only maintenance flows.

---

## 🚀 Quick Deploy

<details>
  <summary><b>🖥️ [Click to Expand] VPS / Linux Server Build Guide</b></summary>
  <br>

  ### 📋 Prerequisites
  ```bash
  sudo apt-get update && sudo apt-get upgrade -y
  sudo apt-get install -y git php-cli php-curl php-mbstring ffmpeg python3-pip
  sudo pip3 install yt-dlp
  # ntgcalls 3.0.4 shared library at /opt/ntgcalls/lib/libntgcalls.so
  ```

  ### 🚀 Step-by-Step Setup
  ```bash
  # 1. Clone the repository
  git clone https://github.com/Iotatg/YumiMusic.git
  cd YumiMusic

  # 2. (Optional but recommended) Deploy the Iota backend
  git clone https://github.com/Iotatg/Iota-api.git ../iota-api
  pip3 install -r ../iota-api/requirements.txt
  #    point IOTA_API_URL at it and give it your YouTube cookies

  # 3. Configure environment
  cp .env.example .env
  nano .env   # API_ID, API_HASH, TOKEN, OWNER_ID, STRING_SESSIONS, ...

  # 4. YouTube cookies (fixes video extraction)
  #    Export with a browser extension ("Get cookies.txt LOCALLY")
  #    and save as data/cookies.txt

  # 5. Run
  ./run.sh
  ```

  ### 🔄 Running 24/7 in Background
  **Method 1: Using Tmux (Recommended)**
  ```bash
  tmux new -s yumimusic
  ./run.sh
  # Press Ctrl+B then D to detach. Re-attach anytime with: tmux a -t yumimusic
  ```

  **Method 2: Using Systemd Service**
  ```bash
  sudo nano /etc/systemd/system/yumimusic.service
  ```
  Paste the following unit configuration:
  ```ini
  [Unit]
  Description=Yumi Music Telegram Bot
  After=network.target

  [Service]
  Type=simple
  User=ubuntu
  WorkingDirectory=/home/ubuntu/YumiMusic
  ExecStart=/usr/bin/php /home/ubuntu/YumiMusic/bot.php
  Restart=always
  RestartSec=5

  [Install]
  WantedBy=multi-user.target
  ```
  Enable and start the service:
  ```bash
  sudo systemctl daemon-reload
  sudo systemctl enable yumimusic
  sudo systemctl start yumimusic
  sudo systemctl status yumimusic
  ```
</details>

---

## ⚙️ Configuration Variables

<details open>
  <summary><b>🔑 Environment Variables Reference Table</b></summary>
  <br>

  | Variable | Required | Default | Description |
  | :--- | :---: | :---: | :--- |
  | `API_ID` | **Yes** | — | Telegram API ID from [my.telegram.org](https://my.telegram.org) |
  | `API_HASH` | **Yes** | — | Telegram API Hash from [my.telegram.org](https://my.telegram.org) |
  | `TOKEN` | **Yes** | — | Telegram Bot Token from [@BotFather](https://t.me/BotFather) |
  | `OWNER_ID` | **Yes** | — | Telegram User ID of the bot owner |
  | `STRING_SESSIONS` | **Yes** | — | Assistant user session string (Pyrogram hex or MadelineProto base64url) |
  | `IOTA_API_URL` | No | `http://127.0.0.1:8014` | Self-hosted [Iota-api](https://github.com/Iotatg/Iota-api) endpoint |
  | `IOTA_API_KEY` | No | — | Iota API key (`iota_…`, created via the admin API) |
  | `BOT_NAME` | No | `Yumi Music` | Display name used in cards |
  | `BOT_USERNAME` | No | `Yumi_MusicBot` | Bot username used in `@mention` deep links |
  | `VC_ENABLED` | No | `on` | Master switch for voice-chat streaming |
</details>

---

## 🎮 Bot Commands Reference

<details>
  <summary><b>🎵 [Click to Expand] Playback & Stream Commands</b></summary>
  <br>

  | Command | Description |
  | :--- | :--- |
  | `/play <query/url>` | Streams requested song into group voice chat |
  | `/vplay <query/url>` | Streams track with video rendering |
  | `/playforce <query/url>` | Force plays a single track as owner/sudo |
  | `/skip` | Skips current track to the next item in queue |
  | `/pause` | Pauses active playback stream |
  | `/resume` | Resumes paused audio stream |
  | `/stop` | Stops playback and clears the entire playlist queue |
  | `/seek <seconds>` | Seeks playback to an absolute timestamp |
  | `/seekback <seconds>` | Rewinds playback by N seconds |
  | `/speed <0.5-3.0>` | Adjusts voice-chat playback speed |
  | `/mute` / `/unmute` | Mutes or unmutes the stream audio |
  | `/queue` | Displays the current playlist and upcoming tracks |
  | `/replay` | Replays the current playing song from beginning |
  | `/cancel` | Aborts the active track download mid-transfer |
</details>

<details>
  <summary><b>👥 [Click to Expand] Group Admin & Settings Commands</b></summary>
  <br>

  | Command | Description |
  | :--- | :--- |
  | `/auth <user>` | Authorizes non-admin user to use playback commands |
  | `/unauth <user>` | Revokes bot access authorization from a user |
  | `/autoplay` | Toggles continuous auto-play when queue empties |
  | `/loop [off/single/queue/N]` | Sets or cycles loop mode |
  | `/shuffle` | Shuffles the current playlist queue |
  | `/lang` | Opens the interactive language selector panel |
  | `/settings` | Opens the interactive visual settings control panel |
  | `/playmode <admin/everyone>` | Who is allowed to trigger playback |
  | `/adminmode <on/off>` | Restricts commands to chat admins |
  | `/nothumb <on/off>` | Toggles artwork on now-playing cards |
  | `/autoleave <on/off>` | Leaves the voice chat when the queue empties |
  | `/reload` | Refreshes admin caches and locale packs live |
  | `/save` | Persists chat settings to disk |
  | `/askip` | Personal skip that only advances your own request |
</details>

<details>
  <summary><b>🛡️ [Click to Expand] Bot Owner & Sudo Commands</b></summary>
  <br>

  | Command | Description |
  | :--- | :--- |
  | `/addsudo <user>` | Adds a new user to sudoers list |
  | `/delsudo <user>` | Removes a user from sudoers list |
  | `/sudolist` | Shows list of all current bot sudoers |
  | `/stats` | Shows server performance, memory, and uptime metrics |
  | `/ac` | Lists every chat with an active voice-chat session |
  | `/broadcast <msg>` | Broadcasts announcements to all served chats |
  | `/purge <n>` | Deletes last n messages from the current chat |
  | `/ping` | Live latency and response-time benchmark |
</details>

---

## 📁 Project Architecture

```
YumiMusic/
├── bot.php                # entry point: env, bootstrap, cooperative main loop
├── src/
│   ├── MtProto.php        # raw MTProto 2.0 core (RPC, crypto, TCP transport)
│   ├── AesIge.php         # AES-IGE via PHP FFI into libcrypto.so.3
│   ├── Tl.php             # schema-driven TL serializer
│   ├── TlNode.php         # lazy TL node
│   ├── TlSchema.php       # constructor/field tables
│   ├── RsaKey.php         # Telegram RSA key for req_DH_params
│   ├── Ntg.php            # ntgcalls 3.0.4 C-ABI binding via PHP FFI
│   ├── Voice.php          # voice engine: join, stream, re-join on audio<->video
│   ├── Peers.php          # peer cache (updates + dialog refresh)
│   ├── Iota.php           # Iota search / download client
│   ├── UrlResolver.php    # Spotify / Apple / Deezer / YouTube link resolver
│   ├── App.php            # commands, cards, buttons, room engine
│   ├── Buttons.php        # inline keyboard builders
│   ├── Style.php          # unicode-styled cards + formatting helpers
│   ├── Locales.php        # hot-reloadable YAML locale engine
│   ├── MsgEntities.php    # HTML -> messageEntity* (UTF-16 offsets)
│   ├── Db.php             # per-chat settings store
│   └── Room.php           # per-chat playback state
├── locales/               # YAML locale packs (en, hi, ar, tr)
├── tl/                    # .tl schemas (layer 230)
├── data/                  # sessions, downloads (git-ignored)
└── run.sh
```

---

## 🧩 Implementation Notes

- **MTProto transport:** imported sessions must generate a fresh random `session_id` — a zero session id makes the server answer `-404` (auth key not found) even for a valid key.
- **ntgcalls 3.0.4 ABI:** `ntg_create_call` outputs a `char**` (null-terminated SDP); `ntg_pause/resume/mute/unmute` take a `bool* out`; the SOURCE_SHELL input is resolved through `wordexp` — a bare `ffmpeg` works, a full binary path fails with `ENOENT` in this build.
- **Layer 230 inline buttons:** `keyboardInlineButton` + `inlineButtonTypeCallback`, with `keyboardButtonStyle` flags for coloured buttons.
- **HTML → entities:** offsets are UTF-16 code units; zero-length entities are skipped to avoid `ENTITY_BOUNDS_INVALID`.
- **Resilience:** unknown server constructors are logged once and never abort an in-flight RPC; `FLOOD_WAIT_X` is retried transparently.

---

## 📜 License

Released under the **MIT License** — see [LICENSE](LICENSE).

<p align="center">
  <i>Built with pure PHP, raw MTProto and a lot of patience.</i>
</p>
