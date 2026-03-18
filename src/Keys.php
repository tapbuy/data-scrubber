<?php

declare(strict_types=1);

namespace Tapbuy\DataScrubber;

class Keys
{
    private const CACHE_TTL = 86400; // 1 day in seconds

    private array $keys = [];
    private string $url;
    private string $file;

    /**
     * @param string $url HTTPS URL exposing the keys API endpoint
     * @param string|null $cachePath Absolute path to the cache file. Defaults to var/data-scrubbing-keys.json inside the package.
     * @throws \InvalidArgumentException if the URL is not a valid HTTP/HTTPS URL
     */
    public function __construct(string $url, ?string $cachePath = null)
    {
        $this->validateUrl($url);
        $this->url = $url;
        $this->file = $cachePath ?? __DIR__ . '/../var/data-scrubbing-keys.json';
        $this->loadFromCache();
    }

    /**
     * Fetch keys from the API and update the local cache.
     *
     * @throws \RuntimeException on network failure or unexpected API response
     */
    public function fetchKeys(): void
    {
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $this->url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($response === false) {
            throw new \RuntimeException('Failed to fetch keys: ' . $curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException('Failed to fetch keys: HTTP status ' . $httpCode);
        }

        $json = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response from keys API: ' . json_last_error_msg());
        }

        if (!isset($json['success']) || $json['success'] !== true || !isset($json['data'])) {
            throw new \RuntimeException('Failed to load keys: unexpected API response');
        }

        file_put_contents($this->file, json_encode($json['data']), LOCK_EX);
        $this->keys = $json['data'];
    }

    /**
     * Load keys from the local cache file. Falls back to fetchKeys() if the cache is absent, corrupt, or stale.
     */
    private function loadFromCache(): void
    {
        if (!file_exists($this->file) || filesize($this->file) === 0) {
            $this->fetchKeys();
            return;
        }

        $content = file_get_contents($this->file);
        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            $this->fetchKeys();
            return;
        }

        if (time() - filemtime($this->file) > self::CACHE_TTL) {
            $this->fetchKeys();
            return;
        }

        $this->keys = $decoded;
    }

    /**
     * Validate that the URL uses an allowed scheme and has a host.
     *
     * @throws \InvalidArgumentException
     */
    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? '';
        $host   = $parsed['host']   ?? '';

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('Keys URL must be a valid HTTP or HTTPS URL.');
        }
    }

    /**
     * Get the current set of anonymization keys.
     */
    public function getKeys(): array
    {
        return $this->keys;
    }
}
