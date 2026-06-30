<?php

declare(strict_types=1);

namespace Phlix\Anidb;

use Phlix\Anidb\Dto\AnimeDto;
use Phlix\Anidb\Parser\FilenameTitleExtractor;
use Phlix\Anidb\TitleDump\TitleDumpIndexer;
use Phlix\Anidb\TitleDump\TitleDumpManager;
use Phlix\Anidb\Udp\ProductionWaiter;
use Phlix\Anidb\Udp\SocketUdpClient;
use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
use Phlix\Shared\Metadata\MetadataSourceInterface;
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
final class AnidbMetadataProvider implements LifecycleInterface, MetadataSourceInterface
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
     * High-level UDP session client — encapsulates AUTH, session key, flood
     * protection, keep-alive pings, and 506 re-auth retry.
     *
     * Initialized in constructor from $settings + injected seams. The onEnable()
     * hook calls open(); onDisable() calls close(). Tests can inject a
     * fake UdpClient that returns canned responses.
     */
    private UdpClient $udpSession;

    /**
     * Parser seam for AniDB 230 ANIME response parsing.
     *
     * Defaults to {@see AnimeResponseParser}; injected in tests to verify
     * parsing logic independently of the god-class.
     */
    private AnimeResponseParser $animeParser;

    /**
     * Filename title extractor seam — pure string manipulation, no network I/O.
     */
    private FilenameTitleExtractor $filenameExtractor;

    /**
     * Title dump manager seam — owns the title index lifecycle.
     *
     * Nullable so it can be initialized lazily in onEnable() once settings are
     * known. When null, title-dump search is skipped (UDP fallback only).
     */
    private ?TitleDumpManager $titleDumpManager = null;

    /**
     * Path to cached title dump file.
     */
    private string $cacheDir;

    /**
     * Lazily-built host-contract adapter, reused by both the legacy
     * {@see registerWithMetadataManager()} path and the
     * {@see MetadataSourceInterface} triad below so a single object owns the
     * external-id ⇄ AID translation.
     */
    private ?AnidbMetadataProviderAdapter $adapter = null;

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
     * @param AnimeResponseParser|null $animeParser Parser seam for 230 ANIME responses.
     *     Defaults to {@see AnimeResponseParser}. Inject a mock in tests.
     * @param FilenameTitleExtractor|null $filenameExtractor Seam for title extraction.
     *     Defaults to a new instance. Inject a stub in tests to verify call counts.
     * @param TitleDumpManager|null $titleDumpManager Seam for title-dump search.
     *     Defaults to null (initialized lazily in onEnable when use_title_dump is true).
     *     Inject a stub in tests to verify call counts or provide a pre-built index.
     * @param UdpClient|null $udpSession High-level UDP session (AUTH, session key,
     *     flood protection, 506 retry). Defaults to new UdpClient($settings, $udpClient, $waiter).
     *     Inject a test double to verify call counts without network I/O.
     */
    public function __construct(
        array $settings,
        ?UdpClientInterface $udpClient = null,
        ?WaiterInterface $waiter = null,
        ?AnimeResponseParser $animeParser = null,
        ?FilenameTitleExtractor $filenameExtractor = null,
        ?TitleDumpManager $titleDumpManager = null,
        ?UdpClient $udpSession = null,
    ) {
        $this->settings = $settings;
        $this->cacheDir = dirname(__DIR__) . '/var/plugins/phlix-plugin-anidb';
        $this->udpClient = $udpClient ?? new SocketUdpClient(
            self::API_HOST,
            self::API_PORT,
        );
        $this->waiter = $waiter ?? new ProductionWaiter();
        $this->animeParser = $animeParser ?? new AnimeResponseParser();
        $this->filenameExtractor = $filenameExtractor ?? new FilenameTitleExtractor();
        $this->titleDumpManager = $titleDumpManager;
        $this->udpSession = $udpSession ?? new UdpClient($this->settings, $this->udpClient, $this->waiter);
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

        $this->udpSession->open();

        // Lazy-load title index on first lookup if enabled
        if ($this->settings['use_title_dump'] && $this->titleDumpManager === null) {
            $this->titleDumpManager = new TitleDumpManager(
                $this->cacheDir,
                $this->settings['title_dump_url'],
            );
            $this->titleDumpManager->ensureCacheDir();
            $this->titleDumpManager->ensureLoaded();
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

        // type list mirrors how series/anime items flow through MetadataManager's
        // providersByType map; AniDB is anime-first but also a 'series' source.
        $manager->registerProvider(
            AnidbMetadataProviderAdapter::SOURCE_NAME,
            $this->adapter(),
            $this->supportedMediaTypes(),
        );
    }

    /**
     * Lazily build (and cache) the host-contract adapter that bridges this
     * provider's filename/AID lookup to the external-id triad.
     *
     * @return AnidbMetadataProviderAdapter
     */
    private function adapter(): AnidbMetadataProviderAdapter
    {
        return $this->adapter ??= new AnidbMetadataProviderAdapter($this);
    }

    // -------------------------------------------------------------------------
    // MetadataSourceInterface (Phlix\Shared\Metadata) — the first-class typed
    // contract the host SourceRegistry registers on plugin-enable and
    // deregisters on plugin-disable (Step 3.5). The triad delegates to the
    // existing adapter so there is a single source of truth for the
    // external-id ⇄ AID lookup.
    // -------------------------------------------------------------------------

    /**
     * Canonical source name — matches the host anime priority-map entry
     * `['anidb', 'myanimelist', 'tvdb', 'fanart', 'local']`.
     *
     * @return non-empty-string Always `anidb`.
     */
    public function sourceName(): string
    {
        return AnidbMetadataProviderAdapter::SOURCE_NAME;
    }

    /**
     * Media types AniDB answers for — anime-first, also a 'series' source.
     *
     * @return list<non-empty-string> Always `['anime', 'series']`.
     */
    public function supportedMediaTypes(): array
    {
        return ['anime', 'series'];
    }

    /**
     * @param string               $query   Free-text anime title.
     * @param array<string, mixed> $options Optional hints (ignored by AniDB).
     * @return list<array{id: non-empty-string, title: string, overview?: string, poster_path?: string}>
     */
    public function search(string $query, array $options = []): array
    {
        $results = [];
        foreach ($this->adapter()->search($query, $options) as $row) {
            $id = $row['id'] ?? '';
            if (!is_string($id) || $id === '') {
                continue; // a usable external id is mandatory for the host triad
            }
            /** @var array{id: non-empty-string, title: string, overview?: string, poster_path?: string} $entry */
            $entry = ['id' => $id, 'title' => (string) ($row['title'] ?? '')];
            if (isset($row['overview']) && is_string($row['overview'])) {
                $entry['overview'] = $row['overview'];
            }
            if (isset($row['poster_path']) && is_string($row['poster_path'])) {
                $entry['poster_path'] = $row['poster_path'];
            }
            $results[] = $entry;
        }

        return $results;
    }

    /**
     * @param string               $externalId AniDB AID as a decimal string.
     * @param array<string, mixed> $options    Optional hints (ignored).
     * @return array<string, mixed>
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        return $this->adapter()->getDetails($externalId, $options);
    }

    /**
     * @param string $externalId AniDB AID as a decimal string.
     * @return array<string, list<array{url: non-empty-string, width?: int, height?: int}>>
     */
    public function getImages(string $externalId): array
    {
        $images = [];
        foreach ($this->adapter()->getImages($externalId) as $group => $entries) {
            $list = [];
            foreach ($entries as $entry) {
                $url = $entry['url'] ?? '';
                if (!is_string($url) || $url === '') {
                    continue;
                }
                /** @var array{url: non-empty-string, width?: int, height?: int} $image */
                $image = ['url' => $url];
                if (isset($entry['width']) && is_int($entry['width'])) {
                    $image['width'] = $entry['width'];
                }
                if (isset($entry['height']) && is_int($entry['height'])) {
                    $image['height'] = $entry['height'];
                }
                $list[] = $image;
            }
            if ($list !== []) {
                $images[(string) $group] = $list;
            }
        }

        return $images;
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
        $this->udpSession->close();
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

    /**
     * Send a command to AniDB with automatic session management.
     *
     * Delegates to the injected {@see UdpClient} (high-level session client)
     * which handles AUTH, session key, flood protection, keep-alive pings, and
     * 506 re-auth retry internally. This thin wrapper only exists to preserve
     * the original package-private method signature for callers that were
     * already reaching sendCommand() directly within this class.
     *
     * @param string $command   The command to send (without session key).
     * @param int    $retryCount Tracks recursion depth for 506 re-auth retries (max 3).
     *
     * @return string|null Response string or null on timeout.
     */
    private function sendCommand(string $command, int $retryCount = 0): ?string
    {
        return $this->udpSession->sendCommand($command, $retryCount);
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

    /**
     * Load the title dump index from cache if not yet loaded.
     *
     * The cached index uses the grouped format:
     *   [
     *     ["aid" => 12345, "titles" => [["title" => "...", "type" => "main", "lang" => "en"], ...]],
     *     ...
     *   ]
     *
     * @return void
     */

    /**
     * Validate the title index entries against the expected schema.
     *
     * Each entry must have:
     *   - aid (int)
     *   - titles (array)
     *
     * Each title in titles must have:
     *   - title (string)
     *   - type (string)
     *   - lang (string)
     *
     * @param array<mixed> $entries
     *
     * @return list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}>
     */

    // -------------------------------------------------------------------------
    // Private: Anime Details Fetch & Parse
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
        return $this->animeParser->parseAnimeResponse($raw);
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
        return $this->filenameExtractor->extract($filePath);
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
            $whitelisted = $this->validateImageFilename($anime['picname']);
            if ($whitelisted !== null) {
                $posterUrl = 'https://api.anidb.net/images/' . $whitelisted;
            }
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

    /**
     * Validate and whitelist a picname value for safe use in image URLs.
     *
     * Rejects path traversal sequences, absolute URLs, and protocol-relative
     * URLs. Only allows simple filenames ending in common image extensions.
     *
     * @param mixed $picname Raw picname from AniDB response.
     *
     * @return string|null The whitelisted filename, or null if invalid.
     */
    private function validateImageFilename(mixed $picname): ?string
    {
        // Guard: must be a non-empty string.
        if (!is_string($picname) || $picname === '') {
            return null;
        }

        // Reject path traversal attempts.
        if (str_contains($picname, '..') || str_starts_with($picname, '/')) {
            return null;
        }

        // Reject absolute or protocol-relative URLs.
        if (str_contains($picname, '://') || str_starts_with($picname, '//')) {
            return null;
        }

        // Only allow safe filename characters and common image extensions.
        if (!preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|gif|webp)$/', $picname)) {
            return null;
        }

        return $picname;
    }
}
