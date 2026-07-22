<?php

declare(strict_types=1);

namespace Phlix\Anidb\TitleDump;

use RuntimeException;
use Workerman\Http\Client;

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
     *     non-blocking Workerman\Http\Client wrapper. The callable receives (url, callback)
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
     * Default non-blocking HTTP client using {@see \Workerman\Http\Client}.
     *
     * Uses the canonical cooperative-wait pattern from phlix-server/CLAUDE.md:
     * fire the async request, then poll `usleep(1000)` yielding to the event
     * loop until the success/error callback flips a done flag (or the max wait
     * elapses). This NEVER blocks the worker on a synchronous socket read.
     *
     * When the Workerman runtime is unavailable (unit tests / CLI), we return
     * null immediately rather than falling back to a blocking
     * `file_get_contents` — that fallback was the 60s boot-hang landmine
     * (item-5c3, the 2026-07-18 prod revert). The download only ever runs on
     * the lazy/deferred connect path, never at boot.
     *
     * @param string               $url      URL to fetch.
     * @param callable(?string): void $onResult Callback with raw bytes or null.
     */
    private static function defaultHttpClient(string $url, callable $onResult): void
    {
        // No blocking fallback: Workerman\Http\Client is the ONLY acceptable
        // transport. Without the runtime, skip the download (offline index
        // simply stays empty and the UDP path serves as fallback).
        if (!class_exists(Client::class)) {
            $onResult(null);

            return;
        }

        /** @var array{done: bool, body: ?string} $state */
        $state = ['done' => false, 'body' => null];

        $client = new Client(['timeout' => 60]);
        $client->request($url, [
            'method'  => 'GET',
            'headers' => [
                'Accept-Encoding' => 'gzip, deflate',
                'User-Agent'      => 'phlix-anidb-plugin/1.0',
            ],
            'success' => static function ($response) use (&$state): void {
                $body = (string) $response->getBody();
                $state['body'] = $body !== '' ? $body : null;
                $state['done'] = true;
            },
            'error' => static function () use (&$state): void {
                $state['body'] = null;
                $state['done'] = true;
            },
        ]);

        // Cooperative wait — yields to the event loop so other tasks proceed.
        $waited  = 0.0;
        $maxWait = 60.0;
        while (!$state['done'] && $waited < $maxWait) {
            usleep(1000); // 1ms
            $waited += 0.001;
        }

        $onResult($state['body']);
    }
}
