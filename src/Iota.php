<?php

declare(strict_types=1);

/** iota-api client: search + audio/video download endpoints. */
final class Iota
{
    public function __construct(private string $baseUrl, private string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** @return list<array{media_id:string,title:string,artist:string,duration:int,thumbnail:string}> */
    public function search(string $query, int $limit = 5): array
    {
        $url = $this->baseUrl . '/search?q=' . rawurlencode($query) . '&limit=' . $limit
            . '&key=' . rawurlencode($this->apiKey);
        $body = self::httpGet($url, 20);
        if ($body === null) {
            return [];
        }
        $json = json_decode($body, true);
        $out = [];
        foreach (($json['results'] ?? []) as $r) {
            $id = (string) ($r['media_id'] ?? '');
            $title = (string) ($r['title'] ?? '');
            if ($id === '' || $title === '') {
                continue;
            }
            $out[] = [
                'media_id' => $id,
                'title' => $title,
                'artist' => (string) ($r['artist'] ?? ''),
                'duration' => (int) ($r['duration_seconds'] ?? 0),
                'thumbnail' => (string) ($r['thumbnail_url'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Related tracks for a seed media id — the primary autoplay source.
     * Returns an empty list when the catalogue has no neighbours.
     *
     * @return list<array{media_id:string,title:string,artist:string,duration:int,thumbnail:string}>
     */
    public function similar(string $mediaId, int $limit = 8): array
    {
        if ($mediaId === '') {
            return [];
        }
        $url = $this->baseUrl . '/similar/' . rawurlencode($mediaId)
            . '?limit=' . $limit . '&key=' . rawurlencode($this->apiKey);
        $body = self::httpGet($url, 15);
        if ($body === null) {
            return [];
        }
        $json = json_decode($body, true);
        $out = [];
        foreach (($json['results'] ?? []) as $r) {
            $id = (string) ($r['media_id'] ?? '');
            $title = (string) ($r['title'] ?? '');
            if ($id === '' || $title === '') {
                continue;
            }
            $out[] = [
                'media_id' => $id,
                'title' => $title,
                'artist' => (string) ($r['artist'] ?? ''),
                'duration' => (int) ($r['duration_seconds'] ?? 0),
                'thumbnail' => (string) ($r['thumbnail_url'] ?? ''),
            ];
        }
        return $out;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function downloadUrl(string $mediaId, bool $video = false): string
    {
        return $this->baseUrl . '/download/' . rawurlencode($mediaId)
            . '?kind=' . ($video ? 'video' : 'audio') . '&key=' . rawurlencode($this->apiKey);
    }

    public function streamUrl(string $mediaId): string
    {
        return $this->baseUrl . '/stream/' . rawurlencode($mediaId)
            . '?key=' . rawurlencode($this->apiKey);
    }

    public function downloadToFile(string $mediaId, string $dest, bool $video = false, int $timeout = 300): bool
    {
        $fh = @fopen($dest, 'wb');
        if ($fh === false) {
            return false;
        }
        $ok = false;
        try {
            $ch = curl_init($this->downloadUrl($mediaId, $video));
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FAILONERROR => false,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $ok = $code >= 200 && $code < 300;
        } finally {
            fclose($fh);
        }
        if (!$ok || filesize($dest) < 1024) {
            @unlink($dest);
            return false;
        }
        return true;
    }

    private static function httpGet(string $url, int $timeout): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300 && is_string($body)) ? $body : null;
    }
}
