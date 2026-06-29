<?php

declare(strict_types=1);

namespace Phlix\Anidb\TitleDump;

use RuntimeException;

/**
 * Downloads and indexes the AniDB anime-titles.dat.gz dump.
 *
 * The dump format is pipe-delimited with one title per line:
 *   aid|type|lang|title
 *
 * Lines starting with # are comments and are skipped.
 *
 * Produces title_index.json in the shape:
 *   [
 *     ["aid" => 12345, "titles" => [["title" => "Fate/stay night", "type" => "main", "lang" => "en"], ...]],
 *     ...
 *   ]
 *
 * The index file is only re-downloaded if its mtime is older than MAX_AGE_SECONDS (24 hours).
 */
final class TitleDumpIndexer
{
    /**
     * Default maximum age in seconds before re-download (24 hours).
     */
    public const MAX_AGE_SECONDS = 86400;

    /**
     * Path to the cache directory.
     */
    private string $cacheDir;

    /**
     * URL to the anime-titles.dat.gz file.
     */
    private string $titleDumpUrl;

    /**
     * HTTP client that fetches the URL and returns raw bytes.
     *
     * @var callable(string): string|null
     */
    private $httpClient;

    /**
     * @param string      $cacheDir     Directory to store the index file.
     * @param string      $titleDumpUrl URL to anime-titles.dat.gz.
     * @param callable|null $httpClient Injectable HTTP client. Defaults to a
     *     callable that uses file_get_contents with gzip Accept-Encoding.
     */
    public function __construct(
        string $cacheDir,
        string $titleDumpUrl,
        ?callable $httpClient = null,
    ) {
        $this->cacheDir = $cacheDir;
        $this->titleDumpUrl = $titleDumpUrl;
        $this->httpClient = $httpClient ?? self::defaultHttpClient(...);
    }

    /**
     * Download and index the title dump if the local copy is stale or absent.
     *
     * Guard: only re-downloads if the index file is absent or its mtime is older
     * than MAX_AGE_SECONDS. Returns true on success, false on failure.
     *
     * @return bool True on success, false on failure.
     */
    public function downloadAndIndex(): bool
    {
        $indexFile = $this->indexFilePath();

        if (is_file($indexFile) && !$this->isStale($indexFile)) {
            return true;
        }

        $raw = $this->fetch();
        if ($raw === null) {
            return false;
        }

        $decoded = @gzdecode($raw);
        if ($decoded === false) {
            return false;
        }

        $index = $this->parse($decoded);
        return $this->writeIndex($index);
    }

    /**
     * Check if the index file is stale (older than MAX_AGE_SECONDS).
     *
     * @param string $indexFile Absolute path to the index file.
     *
     * @return bool True if stale or does not exist.
     */
    public function isStale(string $indexFile): bool
    {
        if (!is_file($indexFile)) {
            return true;
        }

        $mtime = filemtime($indexFile);
        return (time() - $mtime) > self::MAX_AGE_SECONDS;
    }

    /**
     * Parse raw decompressed title dump content into grouped index structure.
     *
     * @param string $content Decompressed content of anime-titles.dat.
     *
     * @return list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}>
     */
    public function parse(string $content): array
    {
        $lines = explode("\n", trim($content));
        $grouped = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('|', $line, 4);
            if (count($parts) < 4) {
                continue;
            }

            [$aidStr, $type, $lang, $title] = $parts;
            $aid = (int) $aidStr;

            if ($aid <= 0) {
                continue;
            }

            if (!isset($grouped[$aid])) {
                $grouped[$aid] = ['aid' => $aid, 'titles' => []];
            }

            $grouped[$aid]['titles'][] = [
                'title' => $title,
                'type'  => $type,
                'lang'  => $lang,
            ];
        }

        return array_values($grouped);
    }

    /**
     * Fetch the gzipped title dump from the configured URL.
     *
     * @return string|null Raw gzipped bytes, or null on failure.
     */
    private function fetch(): ?string
    {
        if ($this->httpClient === null) {
            return null;
        }

        $body = ($this->httpClient)($this->titleDumpUrl);

        return $body !== '' ? $body : null;
    }

    /**
     * Write the index array to the index file as JSON.
     *
     * @param list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}> $index
     *
     * @return bool True on success.
     */
    private function writeIndex(array $index): bool
    {
        $indexFile = $this->indexFilePath();
        $dir = dirname($indexFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($index, JSON_PRETTY_PRINT);
        $result = file_put_contents($indexFile, $json);

        return $result !== false;
    }

    /**
     * Path to the title_index.json file.
     */
    private function indexFilePath(): string
    {
        return $this->cacheDir . '/title_index.json';
    }

    /**
     * Default HTTP client using file_get_contents with gzip decoding.
     *
     * @param string $url URL to fetch.
     *
     * @return string|null Raw bytes or null on failure.
     */
    private static function defaultHttpClient(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 60,
                'header'  => [
                    'Accept-Encoding: gzip, deflate',
                    'User-Agent: phlix-anidb-plugin/1.0',
                ],
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        return $body !== false ? $body : null;
    }
}
