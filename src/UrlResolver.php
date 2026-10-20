<?php

declare(strict_types=1);

/**
 * Stream-link resolver: turns a pasted page URL (Spotify / Apple Music /
 * Deezer / SoundCloud / YouTube / Tidal / direct file) into a playable
 * iota track. iota-api itself treats a URL as a *text query*, which is why
 * "/play https://open.spotify.com/..." used to end in "Download Failed".
 *
 * Strategy:
 *   • YouTube link            → exact `yt:<id>` (no search, no mismatch)
 *   • Apple Music link        → exact `am:<song id>` (the `?i=` parameter)
 *   • Deezer link             → exact `dz:<track id>`
 *   • Spotify / Tidal / other → oEmbed + OpenGraph metadata → iota search,
 *                               ranked by normalized title/artist similarity
 *   • direct media file URL   → streamed straight by ffmpeg
 */
final class UrlResolver
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/124.0 Safari/537.36';

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
    public static function resolve(string $raw, Iota $iota): array
    {
        $url = self::clean($raw);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        // 1. direct media file — ffmpeg streams it, nothing to resolve
        if (preg_match('~\.(mp3|m4a|aac|ogg|opus|wav|flac|webm|mp4|m4v)(?:\?|$)~i', $url)) {
            $name = basename((string) parse_url($url, PHP_URL_PATH));
            return [[
                'media_id' => 'url:' . $url,
                'title' => $name !== '' ? rawurldecode($name) : 'Audio stream',
                'artist' => (string) parse_url($url, PHP_URL_HOST),
                'duration' => 0,
                'thumbnail' => '',
            ]];
        }

        // 2. YouTube family — exact video id, no fuzzy search
        if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/|v/))([A-Za-z0-9_-]{6,20})~i', $url, $m)) {
            $id = $m[1];
            $meta = self::youtubeMeta($id);
            $found = self::pick(self::safeSearch($iota, trim(($meta['title'] ?? '') . ' ' . ($meta['artist'] ?? '')), 8),
                (string) ($meta['title'] ?? ''));
            if ($found !== null && str_starts_with((string) $found['media_id'], 'yt:')) {
                return [$found];
            }
            return [[
                'media_id' => 'yt:' . $id,
                'title' => $meta['title'] ?? ('YouTube · ' . $id),
                'artist' => $meta['artist'] ?? 'YouTube',
                'duration' => (int) ($meta['duration'] ?? 0),
                'thumbnail' => $meta['thumbnail'] ?? ('https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg'),
            ]];
        }

        // 3. Apple Music — the ?i= song id maps straight onto iota's am: provider
        if (str_contains($host, 'music.apple.com')) {
            $meta = self::appleMeta($url);
            $sid = '';
            if (preg_match('~[?&]i=(\d+)~', $url, $mm)) {
                $sid = $mm[1];
            } elseif (preg_match('~/song/[^/]+/(\d+)~', $url, $mm)) {
                $sid = $mm[1];
            }
            if ($sid !== '') {
                $t = [
                    'media_id' => 'am:' . $sid,
                    'title' => $meta['title'] ?? ('Apple Music · ' . $sid),
                    'artist' => $meta['artist'] ?? 'Apple Music',
                    'duration' => (int) ($meta['duration'] ?? 0),
                    'thumbnail' => $meta['thumbnail'] ?? '',
                ];
                // fill duration/artist from search when Apple's page had none
                if ($t['duration'] === 0) {
                    $alt = self::pick(self::safeSearch($iota, $t['title'] . ' ' . $t['artist'], 5), $t['title']);
                    if ($alt !== null && (int) $alt['duration'] > 0) {
                        $t['duration'] = (int) $alt['duration'];
                        if ($t['thumbnail'] === '') {
                            $t['thumbnail'] = (string) $alt['thumbnail'];
                        }
                    }
                }
                return [$t];
            }
            return self::searchFallback($url, $meta, $iota);
        }

        // 4. Deezer — exact track id
        if (str_contains($host, 'deezer.com') && preg_match('~/track/(\d+)~', $url, $mm)) {
            $meta = self::genericMeta($url);
            $alt = self::pick(self::safeSearch($iota, trim(($meta['title'] ?? '') . ' ' . ($meta['artist'] ?? '')), 5),
                (string) ($meta['title'] ?? ''));
            return [[
                'media_id' => 'dz:' . $mm[1],
                'title' => $meta['title'] ?? ('Deezer · ' . $mm[1]),
                'artist' => $meta['artist'] ?? 'Deezer',
                'duration' => (int) ($alt['duration'] ?? $meta['duration'] ?? 0),
                'thumbnail' => $meta['thumbnail'] ?? (string) ($alt['thumbnail'] ?? ''),
            ]];
        }

        // 5. Spotify / SoundCloud / Tidal / everything else → metadata + search
        $meta = self::genericMeta($url);
        return self::searchFallback($url, $meta, $iota);
    }

    /** @param array<string,mixed> $meta */
    private static function searchFallback(string $url, array $meta, Iota $iota): array
    {
        $title = trim((string) ($meta['title'] ?? ''));
        $artist = trim((string) ($meta['artist'] ?? ''));
        $query = trim($title . ' ' . $artist);
        if ($query === '') {
            $query = self::slugQuery($url);
        }
        if ($query === '') {
            return [];
        }
        // Providers choke on diacritics ("løve.wav") and on long
        // "title + artist" strings, so search folded variants and merge.
        $queries = [];
        foreach ([$query, $title, self::fold($query), self::fold($title)] as $q) {
            $q = trim(preg_replace('~\s+~u', ' ', $q) ?? $q);
            if ($q !== '' && !in_array($q, $queries, true)) {
                $queries[] = $q;
            }
        }
        $slug = self::slugQuery($url);
        if ($slug !== '') {
            $queries[] = $slug;
        }
        $want = self::norm($title);
        $aTokens = array_values(array_filter(explode(' ', self::norm($artist))));
        $results = [];
        foreach (array_slice($queries, 0, 3) as $qi => $q) {
            foreach (self::safeSearch($iota, $q, 8) as $r) {
                $id = (string) ($r['media_id'] ?? '');
                if ($id !== '' && !isset($results[$id])) {
                    $results[$id] = $r;
                }
            }
            // Stop as soon as an exact title *and* a matching artist is in —
            // each extra provider search costs ~2s.
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
        $results = array_values($results);
        $best = self::pick($results, $title, $artist);
        if ($best === null) {
            return [];
        }
        if ($title !== '' && ($best['title'] ?? '') === '') {
            $best['title'] = $title;
        }
        if ($best['duration'] === 0 && isset($meta['duration'])) {
            $best['duration'] = (int) $meta['duration'];
        }
        return [$best];
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
                // Several songs share the title — let the artist decide.
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
                    $score += 8.0; // candidate fully contained in the wanted title
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

    // ── metadata providers ───────────────────────────────────────────────────

    /** @return array{title?:string,artist?:string,duration?:int,thumbnail?:string} */
    private static function youtubeMeta(string $id): array
    {
        $j = self::json('https://www.youtube.com/oembed?format=json&url='
            . rawurlencode('https://www.youtube.com/watch?v=' . $id));
        $out = [];
        if ($j !== null) {
            $out['title'] = (string) ($j['title'] ?? '');
            $out['artist'] = (string) ($j['author_name'] ?? '');
            $out['thumbnail'] = (string) ($j['thumbnail_url'] ?? '');
        }
        return $out;
    }

    /** @return array{title?:string,artist?:string,duration?:int,thumbnail?:string} */
    private static function appleMeta(string $url): array
    {
        $html = self::get($url);
        $out = [];
        if ($html !== null) {
            if (preg_match('~<meta[^>]+property="og:title"[^>]+content="([^"]*)"~i', $html, $m)) {
                $t = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // "Song by Artist, Artist on Apple Music"
                if (preg_match('~^(.*?)\s+by\s+(.*?)(?:\s+on\s+Apple\s*Music)?$~u', $t, $mm)) {
                    $out['title'] = trim($mm[1]);
                    $out['artist'] = trim($mm[2]);
                } else {
                    $out['title'] = $t;
                }
            }
            if (preg_match('~<meta[^>]+property="og:image"[^>]+content="([^"]*)"~i', $html, $m)) {
                $out['thumbnail'] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (preg_match('~"duration":\s*"?PT(?:(\d+)M)?(?:(\d+)S)?"?~i', $html, $m)) {
                $out['duration'] = ((int) ($m[1] ?? 0)) * 60 + (int) ($m[2] ?? 0);
            }
            if (preg_match('~<meta[^>]+property="og:description"[^>]+content="[^"]*Duration\s+(\d+):(\d+)"~i', $html, $m)) {
                $out['duration'] = ((int) $m[1]) * 60 + (int) $m[2];
            }
        }
        return $out;
    }

    /** OpenGraph / oEmbed extraction for any provider. */
    private static function genericMeta(string $url): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $out = [];

        if (str_contains($host, 'spotify.com')) {
            $j = self::json('https://open.spotify.com/oembed?url=' . rawurlencode($url));
            if ($j !== null) {
                $out['title'] = (string) ($j['title'] ?? '');
                $out['thumbnail'] = (string) ($j['thumbnail_url'] ?? '');
            }
            // the embed page carries the artist list in its JSON state
            if (preg_match('~/(track|album|playlist)/([A-Za-z0-9]+)~', $url, $m)) {
                $html = self::get('https://open.spotify.com/embed/' . $m[1] . '/' . $m[2]);
                if ($html !== null && preg_match('~"artists":\s*\[\s*\{\s*"name":\s*"([^"]+)"~u', $html, $am)) {
                    $out['artist'] = html_entity_decode($am[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if ($html !== null && preg_match('~"duration":\s*(\d{2,7})~', $html, $dm)) {
                    $out['duration'] = (int) round(((int) $dm[1]) / 1000);
                }
            }
            return $out;
        }

        if (str_contains($host, 'soundcloud.com')) {
            $j = self::json('https://soundcloud.com/oembed?format=json&url=' . rawurlencode($url));
            if ($j !== null) {
                $out['title'] = (string) ($j['title'] ?? '');
                $out['artist'] = (string) ($j['author_name'] ?? '');
                $out['thumbnail'] = (string) ($j['thumbnail_url'] ?? '');
            }
            return $out;
        }

        // yt-dlp knows a lot of sites — use it as a metadata oracle (no download)
        $yd = self::ytDlpMeta($url);
        if ($yd !== []) {
            return $yd;
        }

        $html = self::get($url);
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
        $bin = getenv('YTDLP_BIN') ?: 'yt-dlp';
        $cmd = escapeshellcmd($bin) . ' --no-playlist --no-warnings --skip-download --dump-single-json '
            . '--socket-timeout 15 ' . escapeshellarg($url) . ' 2>/dev/null';
        $raw = self::run($cmd, 25);
        if ($raw === null) {
            return [];
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return [];
        }
        return [
            'title' => (string) ($j['track'] ?? $j['title'] ?? ''),
            'artist' => (string) ($j['artist'] ?? $j['uploader'] ?? $j['creator'] ?? ''),
            'duration' => (int) round((float) ($j['duration'] ?? 0)),
            'thumbnail' => (string) ($j['thumbnail'] ?? ''),
        ];
    }

    // ── http helpers ─────────────────────────────────────────────────────────

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

    private static function json(string $url): ?array
    {
        $body = self::get($url);
        if ($body === null) {
            return null;
        }
        $j = json_decode($body, true);
        return is_array($j) ? $j : null;
    }

    private static function run(string $cmd, int $timeout): ?string
    {
        $proc = @popen($cmd, 'r');
        if (!is_resource($proc)) {
            return null;
        }
        $out = '';
        $start = time();
        stream_set_blocking($proc, false);
        while (!feof($proc)) {
            $chunk = fread($proc, 8192);
            if ($chunk !== false && $chunk !== '') {
                $out .= $chunk;
            }
            if (time() - $start > $timeout) {
                break;
            }
            usleep(50000);
        }
        @pclose($proc);
        return $out !== '' ? $out : null;
    }
}
