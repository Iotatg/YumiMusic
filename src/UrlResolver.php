<?php

declare(strict_types=1);

/**
 * Universal stream-link resolver.
 *
 * Turns any pasted music link into playable media ids. The iota provider
 * registry understands `<prefix>:<id>` for yt / sp / dz / am / jv / sc / bp /
 * rs, so whenever a platform exposes a stable id we map straight onto it and
 * skip fuzzy search entirely. Everything else falls back to page metadata
 * (oEmbed / OpenGraph / iTunes / Deezer public API / yt-dlp) followed by a
 * provider search.
 *
 * Resolution order per link
 *   1. direct media file            → url:<href>       (ffmpeg streams it)
 *   2. exact provider id            → yt: / am: / sp: / dz: / jv:
 *   3. album / playlist link        → every track, in order
 *   4. metadata + provider search   → best title/artist match
 *   5. yt-dlp metadata oracle       → search (covers ~1800 extra sites)
 *
 * Every positive and negative answer is cached in data/urlcache.json so a
 * repeated link resolves instantly and does not hammer the providers.
 */
final class UrlResolver
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0 Safari/537.36';

    private const CACHE_TTL = 604800;   // 7 days for a good answer
    private const MISS_TTL  = 900;      // 15 min for "nothing found"
    private const MAX_ENTRIES = 600;

    /** Upper bound for album / playlist expansion. */
    public const MAX_TRACKS = 25;

    private static string $cacheFile = '';
    private static ?array $cache = null;

    public static function boot(?string $cacheFile = null): void
    {
        self::$cacheFile = $cacheFile ?? dirname(__DIR__) . '/data/urlcache.json';
    }

    public static function isUrl(string $s): bool
    {
        return (bool) preg_match('~^https?://\S+$~i', trim($s));
    }

    /** Strip tracking params that break provider parsing. */
    public static function clean(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('~[?&](?:si|utm_[a-z]+|context|ref|nd|app|at|ct|itscg|itsct)=[^&]*~i', '', $url) ?? $url;
        return rtrim($url, '?&');
    }

    /**
     * @return list<array{media_id:string,title:string,artist:string,duration:int,thumbnail:string}>
     */
    public static function resolve(string $raw, Iota $iota, ?RiteshApi $ritesh = null, int $limit = self::MAX_TRACKS): array
    {
        $url = self::clean($raw);
        $key = 'u:' . md5($url);

        if (($hit = self::cacheGet($key)) !== null) {
            return array_slice($hit, 0, $limit);
        }

        // spotify.link / deezer.page.link / on.soundcloud.com / amzn.to hide
        // the real entity behind a redirect — unwrap it before routing.
        $url = self::expand($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $platform = self::platform($host);

        $tracks = [];
        try {
            switch ($platform) {
                case 'file':
                    $tracks = self::directFile($url);
                    break;
                case 'youtube':
                    $tracks = self::youtube($url, $iota, $limit);
                    break;
                case 'apple':
                    $tracks = self::apple($url, $limit);
                    break;
                case 'spotify':
                    $tracks = self::spotify($url, $limit);
                    break;
                case 'deezer':
                    $tracks = self::deezer($url, $limit);
                    break;
                case 'jiosaavn':
                    $tracks = self::jioSaavn($url);
                    break;
                case 'soundcloud':
                    $tracks = self::soundcloud($url, $iota, $ritesh, $limit);
                    break;
                case 'boomplay':
                    $tracks = self::boomplay($url);
                    break;
                case 'resso':
                    $tracks = self::resso($url);
                    break;
                case 'tidal':
                    $tracks = self::tidal($url, $iota, $ritesh, $limit);
                    break;
                default:
                    $tracks = [];
            }

            // Exact ids can still point at a dead/geo-blocked item — verify and
            // degrade to a search instead of failing at download time.
            if ($tracks !== [] && !self::verify($iota, $tracks[0]['media_id'])) {
                echo "[resolver] {$tracks[0]['media_id']} not servable, searching instead\n";
                // Keep the title/artist we already know: searching blind would
                // pick an unrelated song.
                $seed = $tracks[0];
                $tracks = [];
                if (($seed['title'] ?? '') !== '') {
                    $tracks = self::metadataSearch($url, $platform, $iota, $ritesh, $limit, [
                        'title' => (string) $seed['title'],
                        'artist' => (string) ($seed['artist'] ?? ''),
                        'duration' => (int) ($seed['duration'] ?? 0),
                        'thumbnail' => (string) ($seed['thumbnail'] ?? ''),
                    ]);
                }
                $tracks = self::unique($tracks);
                self::cachePut($key, $tracks);
                return array_slice($tracks, 0, $limit);
            }

            // A playlist link with no video id has nothing to search for; if
            // expansion failed the honest answer is "no tracks", not a random
            // song that happens to match the URL.
            $playlistOnly = $platform === 'youtube'
                && preg_match('~[?&]list=~', $url)
                && !preg_match('~youtu\.be/|/shorts/|/embed/|/live/|[?&]v=~', $url);

            if ($tracks === [] && !$playlistOnly) {
                $tracks = self::metadataSearch($url, $platform, $iota, $ritesh, $limit);
            }
        } catch (Throwable $e) {
            echo '[resolver] ' . $e->getMessage() . "\n";
            $tracks = [];
        }

        $tracks = self::unique($tracks);
        self::cachePut($key, $tracks);
        return array_slice($tracks, 0, $limit);
    }

    /** @return list<array> */
    private static function directFile(string $url): array
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        return [[
            'media_id' => 'url:' . $url,
            'title' => $name !== '' ? rawurldecode($name) : 'Audio stream',
            'artist' => (string) parse_url($url, PHP_URL_HOST),
            'duration' => 0,
            'thumbnail' => '',
        ]];
    }

    // ── platform detection ───────────────────────────────────────────────────

    private static function platform(string $host): string
    {
        if (preg_match('~\.(mp3|m4a|aac|ogg|opus|wav|flac|webm|mp4|m4v)$~i', $host)) {
            return 'file';
        }
        $map = [
            'music.apple.com' => 'apple', 'itunes.apple.com' => 'apple',
            'open.spotify.com' => 'spotify', 'spotify.com' => 'spotify', 'spotify.link' => 'spotify',
            'deezer.com' => 'deezer', 'deezer.page.link' => 'deezer',
            'music.youtube.com' => 'youtube', 'youtube.com' => 'youtube',
            'youtu.be' => 'youtube', 'youtube-nocookie.com' => 'youtube',
            'soundcloud.com' => 'soundcloud', 'soundcloud.app.goo.gl' => 'soundcloud',
            'on.soundcloud.com' => 'soundcloud', 'snd.sc' => 'soundcloud',
            'jiosaavn.com' => 'jiosaavn', 'saavn.com' => 'jiosaavn', 'savn.link' => 'jiosaavn',
            'tidal.com' => 'tidal', 'tidal.link' => 'tidal',
            'music.amazon.com' => 'amazon', 'music.amazon.in' => 'amazon',
            'music.amazon.co.uk' => 'amazon', 'music.amazon.de' => 'amazon',
            'amzn.to' => 'amazon', 'amzn.eu' => 'amazon', 'amzn.in' => 'amazon',
            'gaana.com' => 'gaana', 'wynk.in' => 'wynk',
            'audiomack.com' => 'audiomack', 'bandcamp.com' => 'bandcamp',
            'boomplay.com' => 'boomplay', 'bfan.link' => 'boomplay',
            'boomplay.app.link' => 'boomplay', 'resso.com' => 'resso',
            'resso.page.link' => 'resso',
            'napster.com' => 'napster', 'pandora.com' => 'pandora',
            'iheart.com' => 'iheart', 'vk.com' => 'vk', 'ok.ru' => 'ok',
            'bilibili.com' => 'bilibili', 'dailymotion.com' => 'dailymotion',
            'vimeo.com' => 'vimeo', 'nicovideo.jp' => 'niconico',
        ];
        foreach ($map as $needle => $name) {
            if ($host === $needle || str_ends_with($host, '.' . $needle)) {
                return $name;
            }
        }
        return 'generic';
    }

    // ── YouTube ──────────────────────────────────────────────────────────────

    /** @return list<array> */
    private static function youtube(string $url, Iota $iota, int $limit): array
    {
        $out = [];
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/|v/))([A-Za-z0-9_-]{6,20})~i', $url, $m)) {
            $id = $m[1];
            $meta = self::youtubeMeta($id);
            $out[] = [
                'media_id' => 'yt:' . $id,
                'title' => $meta['title'] ?? ('YouTube · ' . $id),
                'artist' => $meta['artist'] ?? 'YouTube',
                'duration' => (int) ($meta['duration'] ?? 0),
                'thumbnail' => $meta['thumbnail'] ?? ('https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg'),
            ];
        }
        // A playlist link carries more than the single video — expand it.
        if (preg_match('~[?&]list=([A-Za-z0-9_-]+)~', $url, $lm)) {
            $pl = self::ytDlpPlaylist('https://www.youtube.com/playlist?list=' . $lm[1], max(1, $limit - count($out)));
            foreach ($pl as $t) {
                $out[] = $t;
            }
        }
        return $out;
    }

    /** @return array{title?:string,artist?:string,duration?:int,thumbnail?:string} */
    private static function youtubeMeta(string $id): array
    {
        $j = self::json('https://www.youtube.com/oembed?format=json&url='
            . rawurlencode('https://www.youtube.com/watch?v=' . $id), 10);
        $out = [];
        if ($j !== null) {
            $out['title'] = (string) ($j['title'] ?? '');
            $out['artist'] = (string) ($j['author_name'] ?? '');
            $out['thumbnail'] = (string) ($j['thumbnail_url'] ?? '');
        }
        return $out;
    }

    // ── Apple Music ──────────────────────────────────────────────────────────

    /**
     * iTunes' public lookup API is the only reliable source here: the HTML
     * page changes shape constantly, the API returns trackId + duration +
     * artwork for a song, and every track when given an album id.
     *
     * @return list<array>
     */
    private static function apple(string $url, int $limit): array
    {
        $country = preg_match('~music\.apple\.com/([a-z]{2})/~i', $url, $m) ? strtolower($m[1]) : 'us';

        $songId = '';
        if (preg_match('~[?&]i=(\d+)~', $url, $m)) {
            $songId = $m[1];
        } elseif (preg_match('~/(?:song|music-video)/[^/]*/(\d+)~', $url, $m)) {
            $songId = $m[1];
        }

        if ($songId !== '') {
            $j = self::json("https://itunes.apple.com/lookup?id={$songId}&country={$country}", 12);
            $t = self::fromItunes($j, 1);
            if ($t !== []) {
                return $t;
            }
            // API hiccup — the id itself is still valid for iota.
            return [[
                'media_id' => 'am:' . $songId,
                'title' => 'Apple Music · ' . $songId,
                'artist' => 'Apple Music',
                'duration' => 0,
                'thumbnail' => '',
            ]];
        }

        if (preg_match('~/(?:album|playlist)/[^/]*/(\d+)~', $url, $m)) {
            $j = self::json("https://itunes.apple.com/lookup?id={$m[1]}&entity=song&limit=200&country={$country}", 15);
            return self::fromItunes($j, $limit);
        }
        return [];
    }

    /** @param array<string,mixed>|null $j */
    private static function fromItunes(?array $j, int $limit): array
    {
        if ($j === null || !isset($j['results']) || !is_array($j['results'])) {
            return [];
        }
        $out = [];
        foreach ($j['results'] as $r) {
            if (!is_array($r) || ($r['wrapperType'] ?? '') !== 'track') {
                continue;
            }
            $id = (string) ($r['trackId'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'media_id' => 'am:' . $id,
                'title' => (string) ($r['trackName'] ?? ''),
                'artist' => (string) ($r['artistName'] ?? ''),
                'duration' => (int) round(((int) ($r['trackTimeMillis'] ?? 0)) / 1000),
                'thumbnail' => str_replace('100x100bb', '600x600bb', (string) ($r['artworkUrl100'] ?? '')),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    // ── Spotify ──────────────────────────────────────────────────────────────

    /**
     * The embed page ships a __NEXT_DATA__ blob with the real title, artist
     * list, duration and (for albums/playlists) every track uri — enough to
     * build exact `sp:` ids without any API credentials.
     *
     * @return list<array>
     */
    private static function spotify(string $url, int $limit): array
    {
        if (!preg_match('~/(track|album|playlist|episode)/([A-Za-z0-9]+)~', $url, $m)) {
            return [];
        }
        $type = $m[1];
        $id = $m[2];

        $oembed = self::json('https://open.spotify.com/oembed?url=' . rawurlencode($url), 10);
        $thumb = (string) ($oembed['thumbnail_url'] ?? '');

        $entity = self::spotifyEntity($id, $type);
        if ($type === 'track' || $type === 'episode') {
            $title = trim((string) ($entity['title'] ?? $oembed['title'] ?? ''));
            if ($title === '') {
                return [];
            }
            return [[
                'media_id' => ($type === 'track' ? 'sp:' : 'sp:episode:') . $id,
                'title' => $title,
                'artist' => (string) ($entity['artist'] ?? ''),
                'duration' => (int) ($entity['duration'] ?? 0),
                'thumbnail' => $thumb,
            ]];
        }

        $out = [];
        foreach (($entity['tracks'] ?? []) as $t) {
            if (!is_array($t) || ($t['id'] ?? '') === '') {
                continue;
            }
            $out[] = [
                'media_id' => 'sp:' . $t['id'],
                'title' => (string) ($t['title'] ?? ''),
                'artist' => (string) ($t['artist'] ?? ''),
                'duration' => (int) ($t['duration'] ?? 0),
                'thumbnail' => $thumb,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** @return array{title?:string,artist?:string,duration?:int,tracks?:list<array>} */
    private static function spotifyEntity(string $id, string $type): array
    {
        $html = self::get('https://open.spotify.com/embed/' . $type . '/' . $id, 15);
        if ($html === null) {
            return [];
        }
        if (!preg_match('~<script id="__NEXT_DATA__" type="application/json">(.*?)</script>~s', $html, $m)) {
            return [];
        }
        $d = json_decode($m[1], true);
        $ent = $d['props']['pageProps']['state']['data']['entity'] ?? null;
        if (!is_array($ent)) {
            return [];
        }
        $artists = [];
        foreach (($ent['artists'] ?? []) as $a) {
            if (is_array($a) && ($a['name'] ?? '') !== '') {
                $artists[] = $a['name'];
            }
        }
        $out = [
            'title' => (string) ($ent['title'] ?? $ent['name'] ?? ''),
            'artist' => implode(', ', $artists),
            'duration' => (int) round(((int) ($ent['duration'] ?? 0)) / 1000),
        ];
        $list = [];
        foreach (($ent['trackList'] ?? []) as $t) {
            if (!is_array($t)) {
                continue;
            }
            $uri = (string) ($t['uri'] ?? '');
            if (!preg_match('~spotify:track:([A-Za-z0-9]+)~', $uri, $tm)) {
                continue;
            }
            $list[] = [
                'id' => $tm[1],
                'title' => (string) ($t['title'] ?? ''),
                'artist' => (string) ($t['subtitle'] ?? ''),
                'duration' => (int) round(((int) ($t['duration'] ?? 0)) / 1000),
            ];
        }
        if ($list !== []) {
            $out['tracks'] = $list;
        }
        return $out;
    }

    // ── Deezer ───────────────────────────────────────────────────────────────

    /** @return list<array> */
    private static function deezer(string $url, int $limit): array
    {
        if (preg_match('~/track/(\d+)~', $url, $m)) {
            $j = self::json("https://api.deezer.com/track/{$m[1]}", 12);
            if (is_array($j) && ($j['title'] ?? '') !== '') {
                return [self::dzTrack($j)];
            }
            return [];
        }
        if (preg_match('~/(album|playlist)/(\d+)~', $url, $m)) {
            $j = self::json("https://api.deezer.com/{$m[1]}/{$m[2]}/tracks?limit=100", 15);
            $out = [];
            foreach (($j['data'] ?? []) as $r) {
                if (!is_array($r) || ($r['title'] ?? '') === '') {
                    continue;
                }
                $out[] = self::dzTrack($r);
                if (count($out) >= $limit) {
                    break;
                }
            }
            return $out;
        }
        return [];
    }

    /** @param array<string,mixed> $r */
    private static function dzTrack(array $r): array
    {
        return [
            'media_id' => 'dz:' . (string) ($r['id'] ?? ''),
            'title' => (string) ($r['title'] ?? ''),
            'artist' => (string) ($r['artist']['name'] ?? ''),
            'duration' => (int) ($r['duration'] ?? 0),
            'thumbnail' => (string) ($r['album']['cover_medium'] ?? ''),
        ];
    }

    // ── JioSaavn ─────────────────────────────────────────────────────────────

    /** @return list<array> */
    private static function jioSaavn(string $url): array
    {
        // /song/<slug>/<token> — the provider searches the slug on YouTube.
        if (preg_match('~/song/([^/?#]+)~', $url, $m)) {
            $slug = rawurldecode($m[1]);
            return [[
                'media_id' => 'jv:' . $m[1],
                'title' => ucwords(str_replace('-', ' ', $slug)),
                'artist' => '',
                'duration' => 0,
                'thumbnail' => '',
            ]];
        }
        return [];
    }

    // ── SoundCloud ───────────────────────────────────────────────────────────

    /** @return list<array> */
    private static function soundcloud(string $url, Iota $iota, ?RiteshApi $ritesh, int $limit): array
    {
        $j = self::json('https://soundcloud.com/oembed?format=json&url=' . rawurlencode($url), 12);
        $meta = [];
        if ($j !== null) {
            $meta['title'] = (string) ($j['title'] ?? '');
            $meta['artist'] = (string) ($j['author_name'] ?? '');
            $meta['thumbnail'] = (string) ($j['thumbnail_url'] ?? '');
        }
        // oEmbed is blocked from some networks; the permalink still tells us
        // who published the track and what it is called.
        if ($meta === [] && preg_match('~soundcloud\.com/([^/]+)/([^/?#]+)~', $url, $m)) {
            $meta['artist'] = ucwords(str_replace('-', ' ', rawurldecode($m[1])));
            $meta['title'] = ucwords(str_replace('-', ' ', rawurldecode($m[2])));
        }
        // The provider accepts the full track URL as its native id — that is
        // exact, no fuzzy search involved.
        if (preg_match('~soundcloud\.com/[^/]+/[^/?#]+~', $url)) {
            return [[
                'media_id' => 'sc:' . $url,
                'title' => (string) ($meta['title'] ?? ''),
                'artist' => (string) ($meta['artist'] ?? ''),
                'duration' => 0,
                'thumbnail' => (string) ($meta['thumbnail'] ?? ''),
            ]];
        }
        return self::metadataSearch($url, 'soundcloud', $iota, $ritesh, $limit,
            ($meta['title'] ?? '') !== '' ? $meta : null);
    }

    // ── Boomplay / Resso / Tidal ─────────────────────────────────────────────

    /** @return list<array> */
    private static function boomplay(string $url): array
    {
        if (!preg_match('~/(?:songs?|tracks?)/(\d+)~', $url, $m)) {
            return [];
        }
        $name = '';
        if (preg_match('~/(?:songs?|tracks?)/[^/]*?([a-z0-9-]{3,})~i', $url, $sm)) {
            $name = trim(str_replace('-', ' ', $sm[1]));
        }
        return [[
            'media_id' => 'bp:' . $m[1],
            'title' => $name !== '' ? ucwords($name) : '',
            'artist' => '',
            'duration' => 0,
            'thumbnail' => '',
        ]];
    }

    /** @return list<array> */
    private static function resso(string $url): array
    {
        if (!preg_match('~/(?:song|track|songs)/([A-Za-z0-9]+)~', $url, $m)) {
            return [];
        }
        $name = '';
        if (preg_match('~/(?:song|track|songs)/[^/]*?([a-z0-9-]{3,})~i', $url, $sm)) {
            $name = trim(str_replace('-', ' ', $sm[1]));
        }
        return [[
            'media_id' => 'rs:' . $m[1],
            'title' => $name !== '' ? ucwords($name) : '',
            'artist' => '',
            'duration' => 0,
            'thumbnail' => '',
        ]];
    }

    /** @return list<array> */
    private static function tidal(string $url, Iota $iota, ?RiteshApi $ritesh, int $limit): array
    {
        $meta = [];
        $j = self::json('https://embed.tidal.com/oembed?url=' . rawurlencode($url) . '&format=json', 10);
        if ($j !== null) {
            $meta['title'] = (string) ($j['title'] ?? '');
            $meta['artist'] = (string) ($j['author_name'] ?? '');
            $meta['thumbnail'] = (string) ($j['thumbnail_url'] ?? '');
        }
        return self::metadataSearch($url, 'tidal', $iota, $ritesh, $limit,
            ($meta['title'] ?? '') !== '' ? $meta : null);
    }

    // ── generic: metadata → search ───────────────────────────────────────────

    /** @return list<array> */
    private static function metadataSearch(
        string $url,
        string $platform,
        Iota $iota,
        ?RiteshApi $ritesh,
        int $limit,
        ?array $known = null
    ): array {
        $meta = $known ?? self::genericMeta($url, $platform);
        $title = trim((string) ($meta['title'] ?? ''));
        $artist = trim((string) ($meta['artist'] ?? ''));
        $thumb = (string) ($meta['thumbnail'] ?? '');
        $duration = (int) ($meta['duration'] ?? 0);

        $queries = [];
        $push = static function (string $q) use (&$queries): void {
            $q = trim(preg_replace('~\s+~u', ' ', $q) ?? $q);
            if ($q !== '' && !in_array($q, $queries, true)) {
                $queries[] = $q;
            }
        };
        $push($title . ' ' . $artist);
        $push($title);
        $push(self::fold($title . ' ' . $artist));
        $push(self::fold($title));
        $push(self::slugQuery($url));

        $want = self::norm($title);
        $aTokens = array_values(array_filter(explode(' ', self::norm($artist))));
        $results = [];

        foreach (array_slice($queries, 0, 4) as $qi => $q) {
            foreach (self::safeSearch($iota, $q, 8) as $r) {
                $id = (string) ($r['media_id'] ?? '');
                if ($id !== '' && !isset($results[$id])) {
                    $results[$id] = $r;
                }
            }
            if ($qi >= 1) {
                foreach ($results as $r) {
                    if ($want !== '' && self::norm((string) ($r['title'] ?? '')) === $want) {
                        $hay = self::norm((string) ($r['artist'] ?? ''));
                        foreach ($aTokens as $a) {
                            if ($a !== '' && str_contains($hay, $a)) {
                                break 3;
                            }
                        }
                    }
                }
            }
        }

        $best = self::pick(array_values($results), $title, $artist);
        // A "song" is never a two-hour full-movie upload: drop those before the
        // fuzzy matcher gets a chance to prefer them on a weak title match.
        if ($best !== null && (int) ($best['duration'] ?? 0) > 1200) {
            $short = array_values(array_filter($results, static function (array $r): bool {
                $d = (int) ($r['duration'] ?? 0);
                return $d === 0 || ($d >= 30 && $d <= 1200);
            }));
            $best = $short !== [] ? self::pick($short, $title, $artist) : null;
        }
        if ($best === null && $title !== '' && $ritesh !== null && $ritesh->enabled()) {
            $hit = $ritesh->resolveUrl($url);
            if ($hit === null && $title !== '') {
                $rs = $ritesh->search(trim($title . ' ' . $artist), 5);
                $hit = self::pick($rs, $title, $artist);
            }
            if ($hit !== null) {
                $best = $hit;
            }
        }
        if ($best === null) {
            return [];
        }
        if ($title !== '' && ($best['title'] ?? '') === '') {
            $best['title'] = $title;
        }
        if ((int) ($best['duration'] ?? 0) === 0 && $duration > 0) {
            $best['duration'] = $duration;
        }
        if (($best['thumbnail'] ?? '') === '' && $thumb !== '') {
            $best['thumbnail'] = $thumb;
        }
        return [$best];
    }

    /** @return array{title?:string,artist?:string,duration?:int,thumbnail?:string} */
    private static function genericMeta(string $url, string $platform): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (str_contains($host, 'spotify.com')) {
            $j = self::json('https://open.spotify.com/oembed?url=' . rawurlencode($url), 10);
            return [
                'title' => (string) ($j['title'] ?? ''),
                'thumbnail' => (string) ($j['thumbnail_url'] ?? ''),
            ];
        }

        // yt-dlp understands ~1800 sites and is the widest metadata oracle —
        // but for the platforms we already query through their own API it only
        // invents results (a dead Deezer album became "Natacha - Wunger").
        if (!in_array($platform, ['deezer', 'apple', 'spotify', 'tidal'], true)) {
            $yd = self::ytDlpMeta($url);
            if ($yd !== []) {
                return $yd;
            }
        }

        $out = [];
        $html = self::get($url, 15);
        if ($html !== null) {
            if (preg_match('~<meta[^>]+property="og:title"[^>]+content="([^"]*)"~i', $html, $m)) {
                $out['title'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
                $out['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (preg_match('~<meta[^>]+property="og:image"[^>]+content="([^"]*)"~i', $html, $m)) {
                $out['thumbnail'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (preg_match('~<meta[^>]+(?:property|name)="(?:og:description|description)"[^>]+content="([^"]*)"~i', $html, $m)) {
                $desc = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('~by\s+([^.|]+)~u', $desc, $am)) {
                    $out['artist'] = trim($am[1]);
                }
            }
        }
        return $out;
    }

    /** @return array{title?:string,artist?:string,duration?:int,thumbnail?:string} */
    private static function ytDlpMeta(string $url): array
    {
        $raw = self::run(self::ytDlpCmd($url) . ' --no-playlist --skip-download --dump-single-json', 30);
        if ($raw === null) {
            return [];
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return [];
        }
        return [
            'title' => (string) ($j['track'] ?? $j['title'] ?? ''),
            'artist' => (string) ($j['artist'] ?? $j['creator'] ?? $j['uploader'] ?? ''),
            'duration' => (int) round((float) ($j['duration'] ?? 0)),
            'thumbnail' => (string) ($j['thumbnail'] ?? ''),
        ];
    }

    /** @return list<array> */
    private static function ytDlpPlaylist(string $url, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $raw = self::run(self::ytDlpCmd($url) . ' --flat-playlist --skip-download --dump-single-json', 30);
        if ($raw === null) {
            return [];
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return [];
        }
        $out = [];
        foreach (($j['entries'] ?? []) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $id = (string) ($e['id'] ?? '');
            if (!preg_match('~^[A-Za-z0-9_-]{6,20}$~', $id)) {
                continue;
            }
            $out[] = [
                'media_id' => 'yt:' . $id,
                'title' => (string) ($e['title'] ?? ''),
                'artist' => (string) ($e['uploader'] ?? $e['channel'] ?? ''),
                'duration' => (int) round((float) ($e['duration'] ?? 0)),
                'thumbnail' => (string) ($e['thumbnail'] ?? ''),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    private static function ytDlpCmd(string $url): string
    {
        $bin = getenv('YTDLP_BIN') ?: 'yt-dlp';
        return escapeshellcmd($bin) . ' --no-warnings --socket-timeout 15 '
            . escapeshellarg($url) . ' 2>/dev/null';
    }

    // ── matching helpers ─────────────────────────────────────────────────────

    /** Is this media id actually servable by the configured provider? */
    private static function verify(Iota $iota, string $mediaId): bool
    {
        if (str_starts_with($mediaId, 'url:')) {
            return true;
        }
        // Only check ids we built from a platform page; a search result is
        // already known-good and an extra round-trip would slow playback.
        if (!preg_match('~^(am|sp|dz|jv|sc|bp|rs):~', $mediaId)) {
            return true;
        }
        try {
            return $iota->info($mediaId) !== null;
        } catch (Throwable $e) {
            return true; // provider hiccup — let the download decide
        }
    }

    /** @return list<array> */
    private static function unique(array $tracks): array
    {
        $out = [];
        $seen = [];
        foreach ($tracks as $t) {
            $id = (string) ($t['media_id'] ?? '');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $t;
        }
        return $out;
    }

    /** @return list<array> */
    private static function safeSearch(Iota $iota, string $q, int $limit): array
    {
        $q = trim(preg_replace('~\s+~u', ' ', $q) ?? $q);
        if ($q === '') {
            return [];
        }
        try {
            return $iota->search($q, $limit);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Pick the result whose title matches best; exact normalized equality wins,
     * otherwise the highest token-overlap score (artist tokens add weight).
     *
     * @param list<array> $results
     */
    public static function pick(array $results, string $wantTitle, string $wantArtist = ''): ?array
    {
        if ($results === []) {
            return null;
        }
        $want = self::norm($wantTitle);
        if ($want !== '') {
            $exact = [];
            foreach ($results as $r) {
                if (self::norm((string) ($r['title'] ?? '')) === $want) {
                    $exact[] = $r;
                }
            }
            if (count($exact) === 1) {
                return $exact[0];
            }
            if ($exact !== []) {
                $aTokens = array_values(array_filter(explode(' ', self::norm($wantArtist))));
                $bestExact = $exact[0];
                $bestHits = -1;
                foreach ($exact as $r) {
                    $hay = self::norm((string) ($r['artist'] ?? ''));
                    $hits = 0;
                    foreach ($aTokens as $a) {
                        if ($a !== '' && str_contains($hay, $a)) {
                            $hits++;
                        }
                    }
                    if ($hits > $bestHits) {
                        $bestHits = $hits;
                        $bestExact = $r;
                    }
                }
                return $bestExact;
            }
        }
        $wantTokens = $want === '' ? [] : array_unique(explode(' ', $want));
        $artistTokens = array_unique(explode(' ', self::norm($wantArtist)));
        $best = null;
        $bestScore = -1.0;
        foreach ($results as $r) {
            $norm = self::norm((string) ($r['title'] ?? ''));
            if ($norm === '' || $want === '') {
                $score = 0.0;
            } elseif ($norm === $want) {
                $score = 100.0;
            } else {
                $tokens = array_unique(explode(' ', $norm));
                $common = count(array_intersect($wantTokens, $tokens));
                $score = $wantTokens === [] ? 0.0 : 60.0 * $common / max(1, count($wantTokens));
                if ($common === count($tokens) && count($tokens) > 0) {
                    $score += 8.0;
                }
                if ($artistTokens !== [] && $artistTokens !== ['']) {
                    $hay = self::norm((string) ($r['artist'] ?? ''));
                    foreach ($artistTokens as $a) {
                        if ($a !== '' && str_contains($hay, $a)) {
                            $score += 12.0;
                            break;
                        }
                    }
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }
        // With a real title in hand a weak match is worse than no match: it
        // would play an unrelated song. Without one (slug-only links) the
        // first result is still the best guess available.
        if ($want !== '' && $bestScore < 15.0) {
            return null;
        }
        return $bestScore >= 12.0 ? $best : ($results[0] ?? null);
    }

    /** Strip diacritics so "løve.wav" and "love.wav" hit the same index. */
    public static function fold(string $s): string
    {
        $map = ['ø' => 'o', 'Ø' => 'O', 'æ' => 'ae', 'Æ' => 'AE', 'ß' => 'ss',
            'ł' => 'l', 'Ł' => 'L', 'đ' => 'd', 'Đ' => 'D', 'ð' => 'd',
            'þ' => 'th', 'œ' => 'oe', 'Œ' => 'OE'];
        $s = strtr($s, $map);
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') {
                $s = $t;
            }
        }
        return $s;
    }

    public static function norm(string $s): string
    {
        $s = self::fold($s);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('~\([^)]*\)|\[[^\]]*\]~u', ' ', $s) ?? $s;
        $s = preg_replace('~\b(feat|ft|featuring|official|video|lyric|lyrics|audio|song|remastered|from)\b.*$~u', ' ', $s) ?? $s;
        $s = preg_replace('~[^\p{L}\p{N}]+~u', ' ', $s) ?? $s;
        return trim(preg_replace('~\s+~u', ' ', $s) ?? $s);
    }

    /** "kusu-kusu-from-satyameva-jayate-2-feat-nora-fatehi" → readable query. */
    private static function slugQuery(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/', $path), static fn($p) => $p !== ''));
        $slug = '';
        foreach ($parts as $p) {
            if (preg_match('~^[a-z0-9]+(?:-[a-z0-9]+){2,}$~i', $p)) {
                $slug = $p;
                break;
            }
        }
        if ($slug === '') {
            return '';
        }
        $slug = preg_replace('~-(?:album|single|ep|track)$~i', '', $slug) ?? $slug;
        return trim(str_replace('-', ' ', $slug));
    }

    // ── resolution cache ─────────────────────────────────────────────────────

    /** @return list<array>|null */
    private static function cacheGet(string $key): ?array
    {
        $c = self::cache();
        $e = $c[$key] ?? null;
        if (!is_array($e) || !isset($e['ts'])) {
            return null;
        }
        $ttl = ($e['tracks'] ?? []) === [] ? self::MISS_TTL : self::CACHE_TTL;
        if (time() - (int) $e['ts'] > $ttl) {
            return null;
        }
        return is_array($e['tracks'] ?? null) ? $e['tracks'] : null;
    }

    private static function cachePut(string $key, array $tracks): void
    {
        $c = self::cache();
        $c[$key] = ['ts' => time(), 'tracks' => array_values($tracks)];
        if (count($c) > self::MAX_ENTRIES) {
            uasort($c, static fn($a, $b) => (int) $b['ts'] <=> (int) $a['ts']);
            $c = array_slice($c, 0, self::MAX_ENTRIES, true);
        }
        self::$cache = $c;
        self::persist();
    }

    private static function cache(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        if (self::$cacheFile === '') {
            self::boot();
        }
        $raw = @file_get_contents(self::$cacheFile);
        $j = $raw !== false ? json_decode($raw, true) : null;
        return self::$cache = is_array($j) ? $j : [];
    }

    private static function persist(): void
    {
        $dir = dirname(self::$cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        @file_put_contents(self::$cacheFile, json_encode(self::$cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ── http helpers ─────────────────────────────────────────────────────────

    /** Hosts that only exist to bounce you somewhere else. */
    private const SHORT_HOSTS = [
        'spotify.link', 'link.tospotify.com', 'spotify.app.link',
        'deezer.page.link', 'link.deezer.com', 'deezer.link',
        'on.soundcloud.com', 'soundcloud.app.goo.gl', 'snd.sc',
        'amzn.to', 'amzn.eu', 'amzn.in',
        'apple.co', 'tidal.link', 'bfan.link', 'boomplay.app.link',
        'resso.page.link', 'gaana.page.link', 'savn.link', 'napster.link',
        'song.link', 'songwhip.com', 'odesli.co',
    ];

    /**
     * Unwrap a shortener. Returns the original string when the host is not a
     * known shortener or the redirect cannot be followed.
     */
    private static function expand(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $short = false;
        foreach (self::SHORT_HOSTS as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) {
                $short = true;
                break;
            }
        }
        if (!$short) {
            return $url;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 6,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => self::UA,
        ]);
        curl_exec($ch);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if ($final === '' || !str_starts_with($final, 'http')) {
            return $url;
        }
        echo "[resolver] short link → {$final}\n";
        return self::clean($final);
    }

    private static function get(string $url, int $timeout = 15): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300 && is_string($body)) ? $body : null;
    }

    private static function json(string $url, int $timeout = 12): ?array
    {
        $body = self::get($url, $timeout);
        if ($body === null) {
            return null;
        }
        $j = json_decode($body, true);
        return is_array($j) ? $j : null;
    }

    /**
     * Run a shell command with a hard timeout. pclose() would wait for the
     * child to exit, so a stalled yt-dlp could freeze the bot for minutes —
     * the process is killed instead.
     */
    private static function run(string $cmd, int $timeout): ?string
    {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $desc, $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $start = time();
        while (true) {
            $chunk = fread($pipes[1], 8192);
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
            }
            while (($err = fread($pipes[2], 8192)) !== false && $err !== '') {
                // drain stderr so the child never blocks on a full pipe
            }
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($proc, 9);
                echo '[resolver] command timed out after ' . $timeout . "s\n";
                break;
            }
            usleep(50000);
        }
        foreach ($pipes as $p) {
            @fclose($p);
        }
        @proc_close($proc);
        return $out !== '' ? $out : null;
    }
}
