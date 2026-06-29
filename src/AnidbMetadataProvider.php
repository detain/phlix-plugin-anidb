<?php

declare(strict_types=1);

namespace Phlix\Anidb;

use Phlix\Anidb\TitleDump\TitleDumpManager;
use Phlix\Anidb\Udp\ProductionWaiter;
use Phlix\Anidb\Udp\SocketUdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
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
     * UDP transport seam — owns the raw socket lifecycle.
     *
     * Injected (defaulting to {@see SocketUdpClient}) so AUTH / command-parse /
     * retry logic can be driven through a fake transport in tests without the
     * network. See {@see UdpClientInterface}.
     */
    private UdpClientInterface $udpClient;

    /**
     * Waiter seam for flood-protection delays.
     *
     * Allows {@see enforceFloodProtection()} to yield to the event loop instead
     * of blocking with `usleep` when a non-blocking implementation (e.g.
     * Workerman\Timer) is available in the host. Defaults to {@see ProductionWaiter}
     * for backward compatibility; inject a no-op/stub in tests.
     */
    private WaiterInterface $waiter;

    /**
     * Title dump manager seam — owns the title index lifecycle.
     *
     * Injected (nullable) so the manager can be initialized lazily in
     * {@see onEnable()} once settings are known. Inject a stub in tests to
     * verify call counts or provide a pre-built index via injectIndex().
     */
    private ?TitleDumpManager $titleDumpManager = null;

    /**
     * @param array{username: string, api_key: string, use_title_dump: bool, title_dump_url: string} $settings
     *     Plugin settings from plugin.json. api_key is the AniDB API password
     *     (NOT the website login password).
     * @param UdpClientInterface|null $udpClient Transport seam. Defaults to a
     *     {@see SocketUdpClient} bound to the AniDB endpoint, preserving the
     *     original inline-socket behavior. Inject a fake in tests to drive
     *     AUTH/command/retry logic without the network.
     * @param WaiterInterface|null $waiter Waiter seam for flood-protection delays.
     *     Defaults to {@see ProductionWaiter}. Inject a no-op/stub in tests to
     *     verify computed wait times without real wall-clock delays.
     */
    public function __construct(
        array $settings,
        ?UdpClientInterface $udpClient = null,
        ?WaiterInterface $waiter = null,
        ?TitleDumpManager $titleDumpManager = null,
    ) {
        $this->settings = $settings;
        $this->udpClient = $udpClient ?? new SocketUdpClient(
            self::API_HOST,
            self::API_PORT,
        );
        $this->waiter = $waiter ?? new ProductionWaiter();
        $this->titleDumpManager = $titleDumpManager;
    }

    /**
     * Called by the loader once when the plugin is enabled.
     *
     * Opens the UDP socket and registers with the host MetadataManager.
     * AUTH is deferred to first-use (lazy) so onEnable() never blocks the
     * resident worker on network I/O. The title dump is also lazy-loaded.
     *
     * @param ContainerInterface $container Host PSR-11 container.
     *
     * @return void
     */
    public function onEnable(ContainerInterface $container): void
    {
        $this->container = $container;

        $this->openSocket();

        // Initialize title dump manager if enabled
        if ($this->settings['use_title_dump'] && $this->titleDumpManager === null) {
            $this->titleDumpManager = new TitleDumpManager(
                dirname(__DIR__) . '/var/plugins/phlix-plugin-anidb',
                $this->settings['title_dump_url'],
            );
            $this->titleDumpManager->ensureCacheDir();
        }

        // Self-register with the host MetadataManager so the server's metadata
        // pipeline can actually consume AniDB results. We wrap ourselves in a
        // thin adapter that satisfies the host's
        // Phlix\Media\Metadata\MetadataProviderInterface (search/getDetails/
        // getImages/getProviders/getSourceName) — the same self-registration
        // pattern the built-in Oidc/Ldap plugins use against AuthProviderRegistry.
        $this->registerWithMetadataManager($container);
    }

    /**
     * Resolve the host MetadataManager from the container and register an
     * adapter of this provider against it for the anime/series media types.
     *
     * The registration is best-effort: if the host does not expose a
     * MetadataManager (e.g. a stripped CLI bootstrap) we log nothing and move
     * on — onEnable() must not abort the whole plugin just because the registry
     * is unavailable.
     *
     * @param ContainerInterface $container Host PSR-11 container.
     *
     * @return void
     */
    private function registerWithMetadataManager(ContainerInterface $container): void
    {
        $managerClass = 'Phlix\\Media\\Metadata\\MetadataManager';

        // The host registry only exists in the full server runtime. Probe the
        // container alone (not class_exists) so plugin-only test/CLI contexts —
        // where the server class may be absent but a compatible registry object
        // is provided — still register, and contexts with no registry at all
        // degrade gracefully to a no-op.
        if (!$container->has($managerClass)) {
            return;
        }

        $manager = $container->get($managerClass);
        if (!is_object($manager) || !method_exists($manager, 'registerProvider')) {
            return;
        }

        $adapter = new AnidbMetadataProviderAdapter($this);

        // type list mirrors how series/anime items flow through MetadataManager's
        // providersByType map; AniDB is anime-first but also a 'series' source.
        $manager->registerProvider(
            AnidbMetadataProviderAdapter::SOURCE_NAME,
            $adapter,
            ['anime', 'series'],
        );
    }

    /**
     * Public bridge: resolve a free-text anime title to an AniDB AID.
     *
     * Thin, host-facing wrapper over the internal title-dump / ANIME-by-name
     * resolution used by {@see lookup()}. Consumed by
     * {@see AnidbMetadataProviderAdapter::search()}.
     *
     * @param string $title Anime title to resolve.
     *
     * @return int|null AniDB AID, or null when no match is found.
     */
    public function resolveAidByTitle(string $title): ?int
    {
        $trimmed = trim($title);
        if ($trimmed === '') {
            return null;
        }

        return $this->findAidByTitle($trimmed);
    }

    /**
     * Public bridge: fetch full, host-shaped metadata for an AniDB AID.
     *
     * Thin, host-facing wrapper over {@see fetchAnimeDetails()} +
     * {@see mapToMetadataReturn()}. Consumed by
     * {@see AnidbMetadataProviderAdapter::getDetails()} /
     * {@see AnidbMetadataProviderAdapter::getImages()}.
     *
     * @param int $aid AniDB anime ID.
     *
     * @return array<string, mixed> Mapped metadata array, or `[]` when not found.
     */
    public function fetchAnimeMetadata(int $aid): array
    {
        if ($aid <= 0) {
            return [];
        }

        $anime = $this->fetchAnimeDetails($aid);
        if ($anime === null) {
            return [];
        }

        return $this->mapToMetadataReturn($anime);
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
     * This plugin is consumed through the host MetadataManager — it registers
     * an {@see AnidbMetadataProviderAdapter} in {@see onEnable()} rather than
     * subscribing to PSR-14 events, so this is empty.
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
     * Open the UDP transport (fixed local port >1024 to avoid multi-port ban).
     *
     * Delegates to the injected {@see UdpClientInterface}; the raw socket
     * create/bind/configure lifecycle now lives in {@see SocketUdpClient}.
     *
     * @return void
     *
     * @throws \RuntimeException If socket creation/binding fails.
     */
    private function openSocket(): void
    {
        $this->udpClient->open();
    }

    /**
     * Close the UDP transport.
     *
     * Delegates to the injected {@see UdpClientInterface}.
     *
     * @return void
     */
    private function closeSocket(): void
    {
        $this->udpClient->close();
    }

    /**
     * Authenticate to AniDB via AUTH command.
     *
     * AUTH is special: it has no session key (the server assigns one in the
     * response), so we bypass sendCommand() which would incorrectly append
     * '&s=' to a null sessionKey. AUTH also bypasses flood protection since
     * it's the first packet sent; the server enforces the rate limit.
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

        // Send AUTH directly — bypass sendCommand() which appends '&s='.
        // The AUTH response contains the session key; subsequent commands
        // will use sendCommand() which appends '&s=' correctly.
        $response = $this->udpSend($cmd);

        if ($response === null) {
            throw new \RuntimeException('AniDB AUTH: no response (timeout or network failure)');
        }

        // Parse: "200 SESSION_KEY LOGIN ACCEPTED" or "201 SESSION_KEY ..."
        if (!preg_match('/^(200|201)\s+(\S+)\s+/', $response, $matches)) {
            throw $this->parseAuthFailure($response);
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
     * AUTH is performed lazily on first command when sessionKey is null,
     * keeping onEnable() non-blocking. The first command after a session
     * expiry (506) will also trigger re-auth.
     *
     * @param string $command Full command string (without session key).
     *
     * @return string|null Raw response string or null on timeout/error.
     */
    /**
     * Send a command to AniDB with automatic session management.
     *
     * @param string $command   The command to send (without session key).
     * @param int    $retryCount Tracks recursion depth for 506 re-auth retries (max 3).
     *
     * @return string|null Response string or null on timeout.
     */
    private function sendCommand(string $command, int $retryCount = 0): ?string
    {
        // Lazy AUTH: authenticate on first command if not yet authenticated.
        if ($this->sessionKey === null) {
            $this->authenticate();
        }

        // Attach session key
        $fullCommand = $command . '&s=' . $this->sessionKey;

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

        // Handle session-expired response: re-authenticate and retry with recursion guard.
        if ($result !== null && str_starts_with(trim($result), '506')) {
            if ($retryCount >= 3) {
                throw new \RuntimeException(
                    'AniDB session expired: re-authentication failed after 3 retries'
                );
            }
            $this->authenticate();
            // Fix: token is added to $fullCommand, not $command — strip from $fullCommand.
            $retryCommand = str_replace('&s=' . $this->sessionKey, '', $fullCommand);
            $result = $this->sendCommand($retryCommand, $retryCount + 1);
        }

        return $result;
    }

    /**
     * Low-level UDP send/receive via the transport seam.
     *
     * The raw socket sendto/recvfrom now lives in {@see SocketUdpClient::send()},
     * which throws `'UDP socket not open'` when the socket is closed (preserving
     * the original behavior) and returns the trimmed reply or null on timeout.
     *
     * @param string $data Command string to send.
     *
     * @return string|null Response string or null on timeout.
     *
     * @throws \RuntimeException If the UDP socket is not open.
     */
    private function udpSend(string $data): ?string
    {
        $this->lastSendTimestamp = microtime(true);

        return $this->udpClient->send($data);
    }

    /**
     * Enforce the 4-second minimum between UDP commands.
     *
     * Uses the injected {@see WaiterInterface} instead of blocking `usleep()`,
     * allowing non-blocking implementations (e.g. Workerman\Timer) to yield
     * to the event loop rather than parking the resident worker.
     *
     * @return void
     */
    private function enforceFloodProtection(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastSendTimestamp;

        $waitTime = self::FLOOD_PROTECTION_INTERVAL_SEC - $elapsed;
        if ($waitTime > 0) {
            $this->waiter->wait($waitTime);
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
        if ($this->settings['use_title_dump'] && $this->titleDumpManager !== null) {
            $aid = $this->titleDumpManager->search($title);
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

        // Decode escaped characters per AniDB spec:
        // ` → '  (backtick to single quote)
        // <br /> → space  (line break in multi-line fields)
        // \n → space  (literal newline)
        // NOTE: / is NOT an AniDB escape sequence — slashes in titles must be preserved.
        //       Fields were already split on | before this decode step, so any / in
        //       a title like "Fate/stay night" is a literal slash, not a field separator.
        $decode = fn(string $s): string => str_replace(["`", "<br />", "\n"], ["'", ' ', ' '], $s);

        // categories/tags are not included in amask=00f0f0f0000000 — set honest empty
        $categories = [];

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
            'type'         => is_string($anime['type']) ? $this->mapType($anime['type']) : null,
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

    /**
     * Map AniDB type strings to normalized lowercase identifiers.
     *
     * @param string|null $type Raw type from AniDB (e.g. 'TV Series', 'Movie', 'OVA').
     *
     * @return string|null Normalized type string or null for empty input.
     */
    private function mapType(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        return match (mb_strtolower($type)) {
            'tv series', 'tv' => 'tv',
            'movie' => 'movie',
            'ova' => 'ova',
            'special' => 'special',
            'ona' => 'ona',
            'music' => 'music',
            default => mb_strtolower($type),
        };
    }
}
