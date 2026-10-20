<?php

declare(strict_types=1);

/**
 * RiteshApi client (api.riteshyt.in).
 *
 * The public endpoints are uneven, so this client is written against what the
 * service actually returns today:
 *
 *   GET /search?q=&limit=&api_key=            → {result:[{id,title,link,duration,thumbnails}]}
 *   GET /details?link=<11-char video id>      → {title,duration_sec,thumbnail,vidid,link}
 *   GET /details?link=<full url>              → BROKEN (returns an unrelated video)
 *   GET /api/platform/extract?url=&api_key=   → {success,platform,id,title,thumbnail,duration,formats[]}
 *   GET /download?query=&dl_type=&api_key=    → binary (307 → /downloads/...)
 *
 * Notes that matter:
 *  - format URLs from /extract are RELATIVE ("/api/musicbot/play/<id>") and must
 *    be resolved against the API host.
 *  - /api/stream?url=... entries are 30-second previews; never use them when a
 *    full "/api/musicbot/play/<id>" format is present.
 *  - Cloudflare answers 502 sporadically; one retry fixes it.
 *  - The audio stream is an MP4/M4A container even though it is served as
 *    audio/mpeg, so downloads are validated by magic bytes, not by extension.
 */
final class RiteshApi
{
    private string $base;

    public function __construct(string $baseUrl, private string $apiKey, private bool $enabled = true)
    {
        $this->base = rtrim(trim($baseUrl), '/');
        $this->apiKey = trim($apiKey);
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->base !== '' && $this->apiKey !== '';
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    /** Search by text. Same track shape as Iota::search(). */
    public function search(string $query, int $limit = 5): array
    {
        if (!$this->enabled() || trim($query) === '') {
            return [];
        }
        $json = $this->getJson('/search', [
            'q' => $query,
            'limit' => (string) max(1, min(20, $limit)),
            'api_key' => $this->apiKey,
        ], 25);
        if ($json === null) {
            return [];
        }
        $out = [];
        foreach (($json['result'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }
            $id = (string) ($r['id'] ?? '');
            $title = (string) ($r['title'] ?? '');
            if ($id === '' || $title === '') {
                continue;
            }
            $out[] = [
                'media_id' => 'yt:' . $id,
                'title' => $title,
                'artist' => self::channelName($r['channel']['name'] ?? ''),
                'duration' => self::duration((string) ($r['duration'] ?? '')),
                'thumbnail' => self::lastThumb($r['thumbnails'] ?? []),
            ];
        }
        return $out;
    }

    /**
     * Resolve a pasted link (YouTube / Spotify / Apple Music / Deezer / …)
     * through /api/platform/extract.
     *
     * @return array{media_id:string,title:string,artist:string,duration:int,thumbnail:string}|null
     */
    public function resolveUrl(string $url): ?array
    {
        if (!$this->enabled() || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        $json = $this->getJson('/api/platform/extract', [
            'url' => $url,
            'api_key' => $this->apiKey,
        ], 45);
        if ($json === null || (isset($json['success']) && $json['success'] === false)) {
            return null;
        }

        $title = trim((string) ($json['title'] ?? ''));
        if ($title === '') {
            return null;
        }
        $duration = (int) round((float) ($json['duration'] ?? 0));
        $thumb = (string) ($json['thumbnail'] ?? '');
        $artist = trim((string) ($json['author']['name'] ?? ''));
        $platform = strtolower((string) ($json['platform'] ?? ''));
        $extId = (string) ($json['id'] ?? '');

        // Prefer a full-length stream: /api/musicbot/play/<youtube id>.
        $ytId = '';
        $direct = '';
        foreach (($json['formats'] ?? []) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $type = strtolower((string) ($f['type'] ?? ''));
            $u = (string) ($f['url'] ?? '');
            if ($u === '') {
                continue;
            }
            if (preg_match('#/api/musicbot/play/([A-Za-z0-9_-]{11})#', $u, $m)) {
                $ytId = $m[1];
                break;
            }
            if ($type === 'audio' && $direct === '' && !self::isPreview($u)) {
                $direct = $this->absolute($u);
            }
        }
        if ($ytId === '' && $platform === 'youtube' && preg_match('/^[A-Za-z0-9_-]{11}$/', $extId)) {
            $ytId = $extId;
        }

        if ($ytId !== '') {
            return [
                'media_id' => 'yt:' . $ytId,
                'title' => $title,
                'artist' => $artist,
                'duration' => $duration,
                'thumbnail' => $thumb,
            ];
        }
        if ($direct !== '') {
            return [
                'media_id' => 'url:' . $direct,
                'title' => $title,
                'artist' => $artist,
                'duration' => $duration,
                'thumbnail' => $thumb,
            ];
        }
        return null;
    }

    /** Metadata for a bare 11-char video id (the only form /details gets right). */
    public function details(string $videoId): ?array
    {
        if (!$this->enabled() || !preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
            return null;
        }
        $json = $this->getJson('/details', ['link' => $videoId, 'api_key' => $this->apiKey], 25);
        if ($json === null) {
            return null;
        }
        $vid = (string) ($json['vidid'] ?? '');
        $title = trim((string) ($json['title'] ?? ''));
        // A broken lookup echoes the input back as the title.
        if ($vid !== $videoId || $title === '' || $title === $videoId || preg_match('#^https?://#', $title)) {
            return null;
        }
        return [
            'media_id' => 'yt:' . $videoId,
            'title' => $title,
            'artist' => '',
            'duration' => (int) ($json['duration_sec'] ?? self::duration((string) ($json['duration_min'] ?? ''))),
            'thumbnail' => (string) ($json['thumbnail'] ?? ''),
        ];
    }

    /**
     * Download audio/video to $dest. $query may be a video id, a URL or a text
     * query (the API resolves text itself).
     */
    public function downloadToFile(string $query, string $dest, bool $video = false, int $timeout = 300): bool
    {
        if (!$this->enabled() || trim($query) === '') {
            return false;
        }
        $type = $video ? 'video' : 'audio';

        $attempts = [
            ['/download', ['query' => $query, 'dl_type' => $type, 'api_key' => $this->apiKey]],
            ['/downloads/stream', ['query' => $query, 'dl_type' => $type, 'api_key' => $this->apiKey]],
        ];
        // A URL also works through the extractor, which is the only path that
        // returns usable media for some platforms.
        if (preg_match('#^https?://#i', $query)) {
            $resolved = $this->resolveUrl($query);
            if ($resolved !== null) {
                $mid = (string) $resolved['media_id'];
                if (str_starts_with($mid, 'url:')) {
                    $attempts[] = [substr($mid, 4), []];
                } elseif (str_starts_with($mid, 'yt:')) {
                    $id = substr($mid, 3);
                    $attempts[] = ['/download', ['query' => $id, 'dl_type' => $type, 'api_key' => $this->apiKey]];
                }
            }
        }

        foreach ($attempts as [$path, $params]) {
            for ($try = 0; $try < 2; $try++) {
                $ok = $this->fetchTo($path, $params, $dest, $timeout);
                if ($ok === true) {
                    echo "[ritesh] downloaded via $path\n";
                    return true;
                }
                if ($ok === null) { // 5xx — Cloudflare hiccup, retry once
                    usleep(400000);
                    continue;
                }
                break; // hard failure: next endpoint
            }
        }
        @unlink($dest);
        echo "[ritesh] download failed for " . mb_substr($query, 0, 40) . "\n";
        return false;
    }

    /** @return bool|null true = ok, false = hard fail, null = retryable */
    private function fetchTo(string $path, array $params, string $dest, int $timeout): ?bool
    {
        $url = preg_match('#^https?://#i', $path)
            ? $path
            : $this->base . $path . ($params === [] ? '' : '?' . http_build_query($params));

        $tmp = $dest . '.part';
        $fh = @fopen($tmp, 'wb');
        if ($fh === false) {
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_ENCODING => '',
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        fclose($fh);

        if ($errno !== 0 || $code >= 500) {
            @unlink($tmp);
            echo "[ritesh] $path http=$code errno=$errno\n";
            return $code >= 500 ? null : false;
        }
        if ($code < 200 || $code >= 300 || !self::looksLikeMedia($tmp)) {
            @unlink($tmp);
            echo "[ritesh] $path rejected (http=$code, size=" . (is_file($tmp) ? (string) filesize($tmp) : '0') . ")\n";
            return false;
        }
        if (!@rename($tmp, $dest)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    private static function looksLikeMedia(string $file): bool
    {
        if (!is_file($file) || filesize($file) < 20480) {
            return false;
        }
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = (string) fread($fh, 16);
        fclose($fh);
        if ($head === '') {
            return false;
        }
        // JSON / HTML error bodies start with { or <.
        if ($head[0] === '{' || $head[0] === '<') {
            return false;
        }
        return str_contains(substr($head, 0, 12), 'ftyp')   // mp4 / m4a
            || str_starts_with($head, 'ID3')                 // mp3 with tags
            || str_starts_with($head, 'OggS')
            || str_starts_with($head, 'RIFF')
            || str_starts_with($head, "\xFF\xFB")
            || str_starts_with($head, "\xFF\xF3")
            || str_starts_with($head, "\xFF\xF2")
            || str_starts_with($head, 'fLaC');
    }

    /** @return array<string,mixed>|null */
    private function getJson(string $path, array $params, int $timeout): ?array
    {
        $url = $this->base . $path . '?' . http_build_query($params);
        for ($try = 0; $try < 2; $try++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code >= 500) {
                usleep(400000);
                continue;
            }
            if ($code === 401 || $code === 403) {
                echo "[ritesh] auth rejected ($code) — check RITESH_API_KEY\n";
                return null;
            }
            if ($code < 200 || $code >= 300 || !is_string($body)) {
                echo "[ritesh] $path http=$code\n";
                return null;
            }
            $json = json_decode($body, true);
            if (!is_array($json)) {
                echo "[ritesh] $path bad json: " . mb_substr($body, 0, 120) . "\n";
                return null;
            }
            return $json;
        }
        return null;
    }

    private function absolute(string $u): string
    {
        if (preg_match('#^https?://#i', $u)) {
            return $u;
        }
        return $this->base . '/' . ltrim($u, '/');
    }

    private static function isPreview(string $url): bool
    {
        return str_contains($url, 'p.scdn.co')
            || str_contains($url, 'mp3-preview')
            || str_contains($url, 'AudioPreview')
            || str_contains($url, '/api/stream?url=');
    }

    private static function channelName(mixed $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        // "Times Music Spiritual and 2 more" → "Times Music Spiritual"
        return trim((string) preg_replace('/\s+and\s+\d+\s+more$/i', '', $name));
    }

    private static function lastThumb(mixed $thumbs): string
    {
        if (!is_array($thumbs)) {
            return '';
        }
        $url = '';
        foreach ($thumbs as $t) {
            if (is_array($t) && isset($t['url'])) {
                $url = (string) $t['url'];
            }
        }
        return $url;
    }

    /** "4:46" / "1:02:03" / "184" → seconds. */
    private static function duration(string $d): int
    {
        $d = trim($d);
        if ($d === '') {
            return 0;
        }
        $total = 0;
        foreach (explode(':', $d) as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                return 0;
            }
            $total = $total * 60 + (int) $part;
        }
        return $total;
    }
}
