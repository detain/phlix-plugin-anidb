<?php

declare(strict_types=1);

namespace Phlix\Anidb;

use Phlix\Shared\Plugin\LifecycleInterface;
use Psr\Container\ContainerInterface;

/**
 * AniDB metadata provider plugin for Phlix.
 *
 * Fetches anime metadata (titles, descriptions, episodes, ratings) from
 * the AniDB UDP API and the daily anime-titles.dat.gz dump.
 *
 * ## Features
 *
 * - UDP API integration with strict flood protection (4s between commands)
 * - Session management with keep-alive pings
 * - Daily title dump for fast offline title→AID lookups
 * - Maps AniDB responses to Phlix MetadataManager's expected return shape
 *
 * ## Configuration (plugin.json settings)
 *
 * - username: AniDB username (required)
 * - api_key: AniDB API password from profile (required, secret)
 * - use_title_dump: whether to download/use title dump (default: true)
 * - title_dump_url: URL to anime-titles.dat.gz (default: AniDB official)
 *
 * ## Protocol notes
 *
 * AniDB uses UDP (not HTTP) on api.anidb.net:9000.
 * Flood protection: max 0.5 pkt/sec after first 5, min 4s between packets long-term.
 * Session timeout: 35 minutes. Keep alive with PING every ~30 min.
 *
 * @see https://wiki.anidb.net/UDP_API_Definition
 * @package Phlix\Anidb
 * @since 0.1.0
 */
final class AnidbMetadataProvider implements LifecycleInterface
{
    /**
     * Default anime data mask — bytes 1-4 (basic info + names + episodes + ratings).
     * Format: hexstr where each byte corresponds to a field group.
     */
    private const DEFAULT_ANIME_MASK = '00f0f0f0000000';

    /**
     * AniDB UDP API server hostname.
     */
    private const API_HOST = 'api.anidb.net';

    /**
     * AniDB UDP API server port.
     */
    private const API_PORT = 9000;

    /**
     * Minimum interval between UDP commands in seconds (flood protection).
     */
    private const FLOOD_PROTECTION_INTERVAL_SEC = 4.0;

    /**
     * Session timeout in seconds (35 minutes).
     */
    private const SESSION_TIMEOUT_SEC = 35 * 60;

    /**
     * Ping interval in seconds (30 minutes).
     */
    private const PING_INTERVAL_SEC = 30 * 60;

    /**
     * Plugin settings from plugin.json.
     *
     * @var array{username: string, api_key: string, use_title_dump: bool, title_dump_url: string}
     */
    private array $settings;

    /**
     * Host PSR-11 container for resolving services.
     */
    private ?ContainerInterface $container = null;

    /**
     * Active AniDB session key (null if not authenticated).
     */
    private ?string $sessionKey = null;

    /**
     * Timestamp of last API command (for flood protection).
     */
    private float $lastSendTimestamp = 0.0;

    /**
     * Timestamp of last session activity.
     */
    private ?int $lastActivityTime = null;

    /**
     * Local UDP socket resource.
     *
     * @var resource|null
     */
    private $socket = null;

    /**
     * Title dump index for fast offline search.
     *
     * @var array<string, array{aid: int, title: string, type: string}>|null
     */
    private ?array $titleIndex = null;

    /**
     * Path to cached title dump file.
     */
    private string $cacheDir;

    /**
     * @param array{username: string, api_key: string, use_title_dump: bool, title_dump_url: string} $settings
     *     Plugin settings from plugin.json. api_key is the AniDB API password
     *     (NOT the website login password).
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
        $this->cacheDir = dirname(__DIR__) . '/var/plugins/phlix-plugin-anidb';
    }

    /**
     * Called by the loader once when the plugin is enabled.
     *
     * Opens the UDP socket, authenticates to AniDB, and loads the title dump.
     * Keep onEnable() cheap — do heavy work (title dump parsing) lazily.
     *
     * @param ContainerInterface $container Host PSR-11 container.
     *
     * @return void
     *
     * @throws \RuntimeException If AUTH fails (bad credentials, banned, etc.)
     */
    public function onEnable(ContainerInterface $container): void
    {
        $this->container = $container;

        $this->openSocket();
        $this->authenticate();

        // Lazy-load title index on first lookup if enabled
        if ($this->settings['use_title_dump']) {
            $this->ensureCacheDir();
        }
    }

    /**
     * Called by the loader once when the plugin is disabled.
     *
     * Sends LOGOUT, closes the UDP socket, and cleans up.
     *
     * @return void
     */
    public function onDisable(): void
    {
        $this->logout();
        $this->closeSocket();
        $this->container = null;
    }

    /**
     * Return the PSR-14 listener subscriptions this plugin wants.
     *
     * This plugin is invoked directly by MetadataManager via lookup()
     * rather than through the PSR-14 event dispatcher, so no subscriptions.
     *
     * @return array<class-string, string|callable> Empty for this plugin.
     */
    public function subscribedEvents(): array
    {
        return [];
    }

    /**
     * Look up anime metadata by file path.
     *
     * Parses the filename to extract anime title/episode info, resolves it
     * to an AniDB AID (via title dump or API), fetches full anime details,
     * and returns structured metadata.
     *
     * @param string $filePath Absolute filesystem path of the media item.
     *
     * @return array{
     *     title: string,
     *     original_name: string|null,
     *     overview: string|null,
     *     year: int|null,
     *     genres: array<int, string>,
     *     rating: float|null,
     *     vote_count: int|null,
     *     poster_url: string|null,
     *     fanart_url: string|null,
     *     episodes: int|null,
     *     type: string|null,
     *     anidb_id: int,
     *     titles: array<int, string>,
     *     status: string|null,
     *     runtime_ticks: int|null,
     *     studio: string|null
     * }|array{} Matched anime metadata or empty array when not found.
     */
    public function lookup(string $filePath): array
    {
        // Step 1: Extract anime name from filename
        $animeName = $this->extractAnimeName($filePath);
        if ($animeName === null) {
            return [];
        }

        // Step 2: Find AID via title dump (fast, no API call) or API fallback
        $aid = $this->findAidByTitle($animeName);
        if ($aid === null) {
            return [];
        }

        // Step 3: Fetch anime details from AniDB
        $anime = $this->fetchAnimeDetails($aid);
        if ($anime === null) {
            return [];
        }

        // Step 4: Map to return shape expected by MetadataManager
        return $this->mapToMetadataReturn($anime);
    }

    // -------------------------------------------------------------------------
    // Private: Socket & Session
    // -------------------------------------------------------------------------

    /**
     * Open the UDP socket with a fixed local port (>1024) to avoid multi-port ban.
     *
     * @return void
     *
     * @throws \RuntimeException If socket creation fails.
     */
    private function openSocket(): void
    {
        $this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($this->socket === false) {
            throw new \RuntimeException('Failed to create UDP socket: ' . socket_strerror(socket_last_error()));
        }

        // Reuse local port to avoid triggering AniDB's multi-port ban detection
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        // Bind to a fixed local port above 1024
        $localPort = 9001; // Could be made configurable if needed
        if (!@socket_bind($this->socket, '0.0.0.0', $localPort)) {
            $err = socket_strerror(socket_last_error($this->socket));
            $this->closeSocket();
            throw new \RuntimeException("Failed to bind UDP socket to port {$localPort}: {$err}");
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 10, 'usec' => 0]);
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 10, 'usec' => 0]);
    }

    /**
     * Close the UDP socket.
     *
     * @return void
     */
    private function closeSocket(): void
    {
        if ($this->socket !== null) {
            @socket_close($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Authenticate to AniDB via AUTH command.
     *
     * @return void
     *
     * @throws \RuntimeException If AUTH fails.
     */
    private function authenticate(): void
    {
        $cmd = sprintf(
            'AUTH user=%s&pass=%s&protover=3&client=phlix&clientver=1',
            urlencode($this->settings['username']),
            urlencode($this->settings['api_key'])
        );

        $response = $this->sendCommand($cmd);

        if ($response === null) {
            throw new \RuntimeException('AniDB AUTH: no response (timeout or network failure)');
        }

        // Parse: "200 SESSION_KEY LOGIN ACCEPTED" or "201 SESSION_KEY ..."
        if (!preg_match('/^(200|201)\s+(\S+)\s+/', $response, $matches)) {
            throw new $this->parseAuthFailure($response);
        }

        $this->sessionKey = $matches[2];
        $this->lastActivityTime = time();

        // TODO: Handle 201 (new version available) — notify operator
    }

    /**
     * Send LOGOUT to AniDB and clear session.
     *
     * @return void
     */
    private function logout(): void
    {
        if ($this->sessionKey === null) {
            return;
        }

        $this->sendCommand('LOGOUT s=' . $this->sessionKey);
        $this->sessionKey = null;
        $this->lastActivityTime = null;
    }

    /**
     * Send a PING to keep the session alive.
     *
     * @return void
     */
    private function ping(): void
    {
        if ($this->sessionKey === null) {
            return;
        }

        $this->sendCommand('PING s=' . $this->sessionKey);
        $this->lastActivityTime = time();
    }

    /**
     * Check if the session needs a keepalive ping.
     *
     * @return bool True if ping should be sent.
     */
    private function needsKeepAlive(): bool
    {
        if ($this->lastActivityTime === null || $this->sessionKey === null) {
            return false;
        }

        return (time() - $this->lastActivityTime) >= self::PING_INTERVAL_SEC;
    }

    /**
     * Parse an AUTH failure response and return an appropriate exception.
     *
     * @param string $response Raw response string.
     *
     * @return \RuntimeException
     */
    private function parseAuthFailure(string $response): \RuntimeException
    {
        if (str_starts_with($response, '500')) {
            return new \RuntimeException('AniDB AUTH failed: Invalid username or API password');
        }
        if (str_starts_with($response, '503')) {
            return new \RuntimeException('AniDB AUTH failed: Client version outdated');
        }
        if (str_starts_with($response, '504')) {
            return new \RuntimeException('AniDB AUTH failed: Client banned — ' . substr($response, 4));
        }
        if (str_starts_with($response, '555')) {
            return new \RuntimeException('AniDB AUTH failed: Banned — ' . substr($response, 4));
        }

        return new \RuntimeException('AniDB AUTH failed: ' . $response);
    }

    // -------------------------------------------------------------------------
    // Private: UDP Command Execution
    // -------------------------------------------------------------------------

    /**
     * Send a command to AniDB with flood protection and session key.
     *
     * @param string $command Full command string (without session key).
     *
     * @return string|null Raw response string or null on timeout/error.
     */
    private function sendCommand(string $command): ?string
    {
        // Attach session key if we have one
        if ($this->sessionKey !== null) {
            $fullCommand = $command . '&s=' . $this->sessionKey;
        } else {
            $fullCommand = $command;
        }

        // Flood protection: enforce minimum interval between sends
        $this->enforceFloodProtection();

        // Keep session alive if needed
        if ($this->needsKeepAlive()) {
            $this->ping();
        }

        $result = $this->udpSend($fullCommand);

        if ($result !== null) {
            $this->lastActivityTime = time();
        }

        // Handle session-expired response: re-authenticate and retry once
        if ($result !== null && str_starts_with(trim($result), '506')) {
            $this->authenticate();
            $retryCommand = str_replace('&s=' . $this->sessionKey, '', $command);
            $result = $this->sendCommand($retryCommand);
        }

        return $result;
    }

    /**
     * Low-level UDP send/receive.
     *
     * @param string $data Command string to send.
     *
     * @return string|null Response string or null on timeout.
     */
    private function udpSend(string $data): ?string
    {
        if ($this->socket === null) {
            throw new \RuntimeException('UDP socket not open');
        }

        $this->lastSendTimestamp = microtime(true);

        $bytesSent = @socket_sendto(
            $this->socket,
            $data,
            strlen($data),
            0,
            self::API_HOST,
            self::API_PORT
        );

        if ($bytesSent === false) {
            return null;
        }

        $recvBuf = '';
        $recvFrom = '';
        $port = 0;

        $recvResult = @socket_recvfrom($this->socket, $recvBuf, 1400, 0, $recvFrom, $port);

        if ($recvResult === false) {
            return null;
        }

        return trim($recvBuf);
    }

    /**
     * Enforce the 4-second minimum between UDP commands.
     *
     * @return void
     */
    private function enforceFloodProtection(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastSendTimestamp;

        if ($elapsed < self::FLOOD_PROTECTION_INTERVAL_SEC) {
            usleep((int)((self::FLOOD_PROTECTION_INTERVAL_SEC - $elapsed) * 1_000_000));
        }

        $this->lastSendTimestamp = microtime(true);
    }

    // -------------------------------------------------------------------------
    // Private: Title Lookup
    // -------------------------------------------------------------------------

    /**
     * Find an anime AID by title, using the title dump first then API fallback.
     *
     * @param string $title Anime title to search for.
     *
     * @return int|null AniDB AID or null if not found.
     */
    private function findAidByTitle(string $title): ?int
    {
        // Try title dump first (fast, no API quota cost)
        if ($this->settings['use_title_dump']) {
            $aid = $this->searchTitleDump($title);
            if ($aid !== null) {
                return $aid;
            }
        }

        // Fallback: ANIME command by name (rate-limited)
        $response = $this->sendCommand('ANIME aname=' . urlencode($title) . '&amask=' . self::DEFAULT_ANIME_MASK);

        if ($response === null) {
            return null;
        }

        // Parse 230 ANIME response: first field is AID
        if (preg_match('/^230\s+ANIME\s*\n(\d+)\|/', $response, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }

    /**
     * Search the title dump index for a matching anime.
     *
     * @param string $query Title to search for.
     *
     * @return int|null Best-matching AID or null.
     */
    private function searchTitleDump(string $query): ?int
    {
        $this->ensureTitleIndexLoaded();

        if ($this->titleIndex === null) {
            return null;
        }

        $queryLower = mb_strtolower($query);
        $bestAID = null;
        $bestScore = 0;

        foreach ($this->titleIndex as $entry) {
            $titleLower = mb_strtolower($entry['title']);

            // Exact match (after trimming): highest score
            if ($titleLower === $queryLower) {
                return $entry['aid'];
            }

            // Prefix match: score by length
            if (str_starts_with($titleLower, $queryLower)) {
                $score = strlen($entry['title']);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestAID = $entry['aid'];
                }
            }

            // Contains match: lower score
            if (str_contains($titleLower, $queryLower)) {
                $score = strlen($entry['title']) / 2;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestAID = $entry['aid'];
                }
            }
        }

        return $bestAID;
    }

    /**
     * Load the title dump index from cache if not yet loaded.
     *
     * @return void
     */
    private function ensureTitleIndexLoaded(): void
    {
        if ($this->titleIndex !== null) {
            return;
        }

        $indexFile = $this->cacheDir . '/title_index.json';

        if (file_exists($indexFile) && is_readable($indexFile)) {
            $data = file_get_contents($indexFile);
            if ($data !== false) {
                /** @var array<string, array{aid: int, title: string, type: string}> $decoded */
                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    $this->titleIndex = $decoded;
                    return;
                }
            }
        }

        // If no cached index, we'll rely on API lookups
        $this->titleIndex = [];
    }

    /**
     * Ensure the cache directory exists.
     *
     * @return void
     */
    private function ensureCacheDir(): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    // -------------------------------------------------------------------------
    // Private: Anime Details Fetch & Parse
    // -------------------------------------------------------------------------

    /**
     * Fetch full anime details from AniDB by AID.
     *
     * @param int $aid AniDB anime ID.
     *
     * @return array<string, mixed>|null Parsed anime data or null on failure.
     */
    private function fetchAnimeDetails(int $aid): ?array
    {
        // Fetch main anime data
        $response = $this->sendCommand('ANIME aid=' . $aid . '&amask=' . self::DEFAULT_ANIME_MASK);

        if ($response === null || !str_starts_with(trim($response), '230')) {
            return null;
        }

        $anime = $this->parseAnimeResponse($response);

        if ($anime === null) {
            return null;
        }

        // Fetch description separately (may be truncated to 1400 bytes)
        $description = $this->fetchAnimeDescription($aid);
        if ($description !== null) {
            $anime['description'] = $description;
        }

        return $anime;
    }

    /**
     * Fetch the anime description via ANIMEDESC command.
     *
     * @param int $aid AniDB anime ID.
     *
     * @return string|null Description text or null on failure.
     */
    private function fetchAnimeDescription(int $aid): ?string
    {
        $response = $this->sendCommand('ANIMEDESC aid=' . $aid . '&part=0');

        if ($response === null || !str_starts_with(trim($response), '233')) {
            return null;
        }

        // Response format: "233 ANIMEDESC\n0|1|<description>"
        $lines = explode("\n", trim($response), 2);
        if (count($lines) < 2) {
            return null;
        }

        $parts = explode('|', $lines[1], 3);
        if (count($parts) < 3) {
            return null;
        }

        return $parts[2];
    }

    /**
     * Parse a 230 ANIME response into a structured array.
     *
     * @param string $raw Raw response string.
     *
     * @return array<string, mixed>|null Parsed fields or null on parse failure.
     */
    private function parseAnimeResponse(string $raw): ?array
    {
        $lines = explode("\n", trim($raw), 2);
        if (count($lines) < 2) {
            return null;
        }

        $fields = explode('|', $lines[1]);

        // Based on amask=00f0f0f0000000 (bytes 1-4):
        // Byte 1: aid(8)|dateflags(8)|year(8)|type(8)|related_aid_list(8)|related_aid_type(8)
        // Byte 2: romaji(8)|kanji(8)|english(8)|other(8)|short_names(8)|synonyms(8)
        // Byte 3: episodes(8)|highest_ep(8)|specials(8)|air_date(8)|end_date(8)|url(8)|picname(8)
        // Byte 4: rating(8)|vote_count(8)|temp_rating(8)|temp_vote_count(8)|avg_review(8)|review_count(8)|award_list(8)|is_18+(8)

        // Field order with amask=00f0f0f0000000:
        // 0: aid, 1: dateflags, 2: year, 3: type, 4: related_aid_list, 5: related_aid_type,
        // 6: romaji, 7: kanji, 8: english, 9: other, 10: short_names, 11: synonyms,
        // 12: episodes, 13: highest_ep, 14: specials, 15: air_date, 16: end_date, 17: url, 18: picname,
        // 19: rating, 20: vote_count, 21: temp_rating, 22: temp_vote_count, 23: avg_review, 24: review_count, 25: award_list, 26: is_18+

        if (count($fields) < 27) {
            return null;
        }

        // Decode escaped characters: ` → '  / → |  \n → newline
        $decode = fn(string $s): string => str_replace(["`", '/', "\n"], ["'", '|', ' '], $s);

        $categories = array_filter(array_map('trim', explode(',', $decode($fields[3]))));

        // Parse year: "1999-2000" or "1999"
        $yearStr = $decode($fields[2]);
        $year = null;
        if ($yearStr !== '' && $yearStr !== '0000') {
            $year = (int)explode('-', $yearStr)[0];
            if ($year === 0) {
                $year = null;
            }
        }

        // AniDB rating is stored as e.g. "825" meaning 8.25
        $rating = (int)$fields[19];
        $ratingFloat = $rating > 0 ? $rating / 100 : null;

        $anime = [
            'aid'            => (int)$fields[0],
            'romaji'         => $decode($fields[6]),
            'english'        => $decode($fields[8]),
            'kanji'          => $decode($fields[7]),
            'other'          => $decode($fields[9]),
            'synonyms'       => array_filter(array_map('trim', explode(',', $decode($fields[10])))),
            'episodes'       => (int)$fields[12],
            'specials'       => (int)$fields[14],
            'highest_ep'     => (int)$fields[13],
            'year'           => $yearStr,
            'year_int'       => $year,
            'type'           => $decode($fields[3]),
            'categories'     => $categories,
            'rating'         => $ratingFloat,
            'vote_count'     => (int)$fields[20],
            'temp_rating'    => ((int)$fields[21]) / 100,
            'temp_vote_count'=> (int)$fields[22],
            'start_date'     => (int)$fields[15] ?: null,
            'end_date'       => (int)$fields[16] ?: null,
            'url'            => 'https://anidb.net/' . $fields[0],
            'picname'        => $decode($fields[18]),
            'is_18plus'      => (int)$fields[26] === 1,
        ];

        return $anime;
    }

    // -------------------------------------------------------------------------
    // Private: Filename Parsing
    // -------------------------------------------------------------------------

    /**
     * Extract a likely anime title from a file path.
     *
     * Uses heuristics: strips S##E## patterns, group tags, file extensions,
     * resolution suffixes, etc.
     *
     * @param string $filePath Absolute path to media file.
     *
     * @return string|null Extracted title or null if no clear match.
     */
    private function extractAnimeName(string $filePath): ?string
    {
        $filename = pathinfo($filePath, PATHINFO_FILENAME);

        // Strip common release group patterns: [GroupName], Group-Name, etc.
        $clean = preg_replace('/\[[^\]]+\]/', '', $filename);
        $clean = preg_replace('/\(TX\)/', '', $clean);
        $clean = preg_replace('/\([^\)]+\)/', '', $clean);

        // Strip episode patterns: S01E02, 01x02, Episode 01, Episode.01, standalone 01, 1000
        $clean = preg_replace('/[Ss]\d{1,2}[Ee]\d{1,4}/', '', $clean);
        $clean = preg_replace('/\d{1,2}[Xx]\d{1,4}/', '', $clean);
        $clean = preg_replace('/[.\-_ ]*[Ee]p?[i]?[t]?[.]?\d{1,4}/i', '', $clean);
        // Strip standalone episode numbers: leading ., -, _, space before 1-4 digits
        $clean = preg_replace('/[.\- ][0-9]{1,4}$/', '', $clean);

        // Strip common suffixes: 720p, 1080p, BluRay, HDTV, etc.
        $clean = preg_replace('/(720p|1080p|2160p|480p|BluRay|BRRip|HDRip|HDTV|DVDRip|x264|x265|HEVC|AAC|AC3)/i', '', $clean);

        // Strip year patterns: (2016), 2001, 2023 (at end of string)
        $clean = preg_replace('/\(\d{4}\)/', '', $clean);
        $clean = preg_replace('/\s+\d{4}$/', '', $clean);

        // Strip resolution and codec patterns
        $clean = preg_replace('/\d{3,4}[xX]\d{3,4}/', '', $clean);

        // Strip leading/trailing dashes, dots, underscores, spaces
        $clean = trim($clean, '.-_ ');

        // Replace remaining dots with spaces (common in anime filenames)
        $clean = str_replace('.', ' ', $clean);

        // If result is too short or looks like garbage, skip
        if (strlen($clean) < 2) {
            return null;
        }

        return $clean ?: null;
    }

    // -------------------------------------------------------------------------
    // Private: Response Mapping
    // -------------------------------------------------------------------------

    /**
     * Map a parsed AniDB anime array to the return shape expected by MetadataManager.
     *
     * @param array<string, mixed> $anime Parsed from parseAnimeResponse().
     *
     * @return array<string, mixed> Mapped metadata array.
     */
    private function mapToMetadataReturn(array $anime): array
    {
        $allTitles = array_filter(array_merge(
            [$anime['romaji']],
            $anime['english'] !== '' ? [$anime['english']] : [],
            $anime['kanji'] !== '' ? [$anime['kanji']] : [],
            $anime['synonyms']
        ));

        $posterUrl = null;
        if (!empty($anime['picname'])) {
            $posterUrl = 'https://api.anidb.net/images/' . $anime['picname'];
        }

        return [
            'title'         => $anime['romaji'],
            'original_name' => $anime['english'] ?: ($anime['kanji'] ?: null),
            'overview'     => $anime['description'] ?? null,
            'year'         => $anime['year_int'] ?? null,
            'genres'       => $anime['categories'],
            'rating'       => $anime['rating'],
            'vote_count'   => $anime['vote_count'],
            'poster_url'   => $posterUrl,
            'fanart_url'   => null,
            'episodes'     => $anime['episodes'] ?: null,
            'type'         => $anime['type'],
            'anidb_id'     => $anime['aid'],
            'titles'       => array_values(array_unique($allTitles)),
            'status'       => $this->mapAnimeStatus($anime),
            'runtime_ticks'=> null, // AniDB doesn't provide episode length in basic response
            'studio'       => null, // AniDB doesn't have a single "studio" field; categories used instead
        ];
    }

    /**
     * Map AniDB dateflags + start/end dates to a status string.
     *
     * @param array<string, mixed> $anime Parsed anime fields.
     *
     * @return string|null Status string or null.
     */
    private function mapAnimeStatus(array $anime): ?string
    {
        $startDate = $anime['start_date'] ?? 0;
        $endDate = $anime['end_date'] ?? 0;
        $now = time();

        if ($startDate === 0) {
            return 'Upcoming';
        }

        if ($endDate > 0) {
            return $endDate <= $now ? 'Finished' : 'Currently Airing';
        }

        return $startDate <= $now ? 'Currently Airing' : 'Upcoming';
    }
}
