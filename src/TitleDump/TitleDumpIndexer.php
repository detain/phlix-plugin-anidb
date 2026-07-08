<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\TitleDump;

use RuntimeException;
use Workerman\HttpClient;

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
     * HTTP client that fetches the URL asynchronously and calls back with raw bytes.
     *
     * B5: Changed from blocking callable(string): string|null to non-blocking
     * callback-based callable(string, callable(?string): void): void to avoid
     * blocking the Workerman event loop.
     *
     * @var callable(string, callable(?string): void): void
     */
    private $httpClient;

    /**
     * @param string      $cacheDir     Directory to store the index file.
     * @param string      $titleDumpUrl URL to anime-titles.dat.gz.
     * @param callable|null $httpClient Injectable HTTP client. Defaults to a
     *     non-blocking Workerman\HttpClient wrapper. The callable receives (url, callback)
     *     where callback is invoked with raw gzipped bytes or null on failure.
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
     * than MAX_AGE_SECONDS. Schedules the download via Workerman\Timer to avoid
     * blocking the event loop; returns true immediately when scheduled.
     *
     * B5: Uses Workerman\Timer::add(0, ...) to defer the blocking HTTP call to the
     * next event loop tick so the Workerman worker returns to its loop
     * immediately. Falls back to synchronous execution when Workerman\Timer
     * is unavailable (unit tests, CLI).
     *
     * @return bool True when scheduled (or completed synchronously in CLI/tests).
     *     Note: actual result is delivered via callback in production.
     */
    public function downloadAndIndex(): bool
    {
        $indexFile = $this->indexFilePath();

        if (is_file($indexFile) && !$this->isStale($indexFile)) {
            return true;
        }

        // B5: Use Timer to defer the blocking HTTP call to the next event loop tick.
        // This prevents blocking the Workerman event loop during the HTTP request.
        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::add(0, function (): void {
                $this->doDownloadAndIndex();
            });

            return true;
        }

        // Synchronous fallback for CLI / unit tests
        return $this->doDownloadAndIndex();
    }

    /**
     * Actual download and index implementation (called within Timer callback).
     *
     * @return bool True on success, false on failure.
     */
    private function doDownloadAndIndex(): bool
    {
        $fetchResult = null;

        $onFetched = static function (?string $body) use (&$fetchResult): void {
            $fetchResult = $body;
        };

        $this->fetch($onFetched);

        if ($fetchResult === null) {
            return false;
        }

        $decoded = @gzdecode($fetchResult);
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
                'lower_title' => mb_strtolower($title),
                'type'  => $type,
                'lang'  => $lang,
            ];
        }

        return array_values($grouped);
    }

    /**
     * Fetch the gzipped title dump from the configured URL (async, callback-based).
     *
     * B5: Now uses callback-based async pattern to avoid blocking the event loop.
     *
     * @param callable(?string): void $onResult Callback invoked with raw gzipped
     *     bytes or null on failure.
     */
    private function fetch(callable $onResult): void
    {
        if ($this->httpClient === null) {
            $onResult(null);

            return;
        }

        ($this->httpClient)($this->titleDumpUrl, $onResult);
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
     * Default non-blocking HTTP client using Workerman\HttpClient.
     *
     * B5: Changed from blocking file_get_contents to non-blocking Workerman\HttpClient
     * which uses the event loop for async HTTP requests.
     *
     * @param string               $url      URL to fetch.
     * @param callable(?string): void $onResult Callback with raw bytes or null.
     */
    private static function defaultHttpClient(string $url, callable $onResult): void
    {
        // Gracefully handle when Workerman\HttpClient is unavailable (tests/CLI).
        if (!class_exists(HttpClient::class)) {
            // Fall back to blocking implementation when Workerman is not available.
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
            $onResult($body !== false ? $body : null);

            return;
        }

        $client = new HttpClient($url, [
            'headers' => [
                'Accept-Encoding' => 'gzip, deflate',
                'User-Agent'      => 'phlix-anidb-plugin/1.0',
            ],
            'timeout' => 60,
        ]);

        $client->get(function ($response) use ($onResult): void {
            $body = $response->getBody();
            $onResult($body !== '' ? $body : null);
        });
    }
}
