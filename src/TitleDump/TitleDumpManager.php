<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\TitleDump;

/**
 * Manages the AniDB title dump index for fast offline title→AID lookups.
 *
 * Owns the title index lifecycle: loading from cache, downloading via
 * {@see TitleDumpIndexer}, validation, and search.
 *
 * This class replaces duplicate methods in {@see \Phlix\Anidb\AnidbMetadataProvider}:
 * - search() replaces AnidbMetadataProvider::searchTitleDump()
 * - ensureLoaded() replaces AnidbMetadataProvider::ensureTitleIndexLoaded()
 * - validateSchema() (private) replaces AnidbMetadataProvider::validateTitleIndexSchema()
 * - ensureCacheDir() replaces AnidbMetadataProvider::ensureCacheDir()
 * - getTitleCachePath() replaces inline path construction
 * - ensureFresh() re-arms the 24 h download decision on the search hot path
 *   (review anidb__srphp M5) — the in-memory memo is no longer permanent.
 */
final class TitleDumpManager
{
    /**
     * Title dump index for fast offline search.
     *
     * In-memory canonical shape, grouped by AID:
     *   list<array{aid: int, titles: list<array{title: string, type: string, lang: string, lower: string, len: int}>>>
     *
     * `lower`/`len` are derived state computed ONCE at load (or inject) so
     * per-query scans never re-run mb_strtolower/mb_strlen over the whole
     * dump (review anidb__srphp M-ANIDB-23). The persisted title_index.json
     * deliberately does not carry them — see {@see TitleDumpIndexer::parse()}.
     *
     * @var list<array{aid: int, titles: list<array{title: string, type: string, lang: string, lower: string, len: int}>}>|null
     */
    private ?array $titleIndex = null;

    /**
     * Lowercase title → AID map backing O(1) exact-match lookups.
     *
     * Built once from the loaded index on the first search() (review
     * anidb__srphp item 5: the exact match, i.e. the common case after the
     * filename extract, must not pay a full scan). First entry wins on title
     * collisions — the same AID the pre-map scan returned first. Invalidated
     * by {@see injectIndex()}; see {@see search()} for the build point.
     *
     * @var array<string, int>|null
     */
    private ?array $exactIndex = null;

    /**
     * Path to the cache directory.
     */
    private string $cacheDir;

    /**
     * URL to the anime-titles.dat.gz file.
     */
    private string $titleDumpUrl;

    /**
     * Minimum wall-clock between freshness passes on the search hot path
     * (seconds). The dump is a 24 h artifact; one-minute granularity is
     * orders finer than anything the cache can produce, and the pass itself
     * is a stat plus — only when stale — a short-circuiting downloadAndIndex().
     */
    private const FRESHNESS_CHECK_INTERVAL_SECONDS = 60;

    /**
     * The ONE scheduler this process owns (lazily built, {@see indexer()}).
     * Retained across searches so the indexer's in-process flag and the
     * cross-process marker guard a single attempt stream (M5).
     */
    private ?TitleDumpIndexer $indexer = null;

    /**
     * Optional transport seam forwarded to the lazily-built indexer; tests
     * inject a fake, production passes null and the indexer picks its own
     * default client.
     *
     * @var (callable(string,callable):void)|null
     */
    private $httpClient;

    /**
     * mtime of the index file the CURRENT memo was loaded from; null when the
     * memo has no disk provenance (injected test index, or a load that saw no
     * readable file). The comparison anchor for reload-on-moved (M5).
     */
    private ?int $loadedIndexMtime = null;

    /**
     * time() of the last freshness pass — the throttle behind ensureFresh().
     * 0 means "never", so the first search after load always checks.
     */
    private int $lastFreshnessCheck = 0;

    /**
     * @param string                           $cacheDir     Directory for cached index file.
     * @param string                           $titleDumpUrl URL to anime-titles.dat.gz.
     * @param (callable(string,callable):void)|null $httpClient Transport seam for the indexer (tests).
     */
    public function __construct(string $cacheDir, string $titleDumpUrl, ?callable $httpClient = null)
    {
        $this->cacheDir = $cacheDir;
        $this->titleDumpUrl = $titleDumpUrl;
        $this->httpClient = $httpClient;
    }

    /**
     * Lazily build — then RETAIN — the indexer this manager schedules through.
     */
    private function indexer(): TitleDumpIndexer
    {
        return $this->indexer ??= new TitleDumpIndexer(
            $this->cacheDir,
            $this->titleDumpUrl,
            $this->httpClient,
        );
    }

    /**
     * Search the title dump index for a matching anime.
     *
     * The index is grouped by AID. Each in-memory entry carries the canonical
     * shape documented on {@see $titleIndex}:
     *   ["aid" => 12345, "titles" => [["title" => "...", "type" => "main", "lang" => "en", "lower" => "...", "len" => 3], ...]]
     *
     * Ranking (not fuzzy matching): an exact, case-insensitive title hit
     * returns immediately, served from the lowercase-title map built once per
     * loaded index ({@see buildExactIndex()}) — O(1) for the common case.
     * Near misses come from the full scan: prefix matches score
     * 800 - |len(query) - len(title)| and contains matches score
     * 600 - |len(query) - len(title)|, so titles CLOSEST IN LENGTH to the
     * query win within their tier, and prefix always outranks contains.
     *
     * @param string $query Title to search for.
     *
     * @return int|null Best-matching AID or null.
     */
    public function search(string $query): ?int
    {
        $this->ensureLoaded();
        // Throttled staleness re-entry (review anidb__srphp M5): may replace
        // the memo via reload-on-moved before this search consults it.
        $this->ensureFresh();

        if ($this->titleIndex === null) {
            return null;
        }

        $queryLower = mb_strtolower($query);

        // Exact match: O(1) from the per-load map (owns every exact hit now —
        // the scan below deliberately no longer re-checks equality).
        if ($this->exactIndex === null) {
            $this->exactIndex = $this->buildExactIndex($this->titleIndex);
        }
        if (isset($this->exactIndex[$queryLower])) {
            return $this->exactIndex[$queryLower];
        }

        $queryLen = mb_strlen($query);
        $bestAID = null;
        $bestScore = 0;

        foreach ($this->titleIndex as $entry) {
            $aid = $entry['aid'];
            $titles = $entry['titles'];

            foreach ($titles as $titleEntry) {
                // Derived fields are precomputed at load (M-ANIDB-23): the
                // per-query scan pays zero mb_* calls per title.
                $titleLower = $titleEntry['lower'];
                $titleLen = $titleEntry['len'];

                // Prefix match: score = 800 - abs(len(query) - len(title))
                // Prefer titles closer in length to the query
                if (str_starts_with($titleLower, $queryLower)) {
                    $score = 800 - abs($queryLen - $titleLen);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestAID = $aid;
                    }
                }

                // Contains match: score = 600 - abs(len(query) - len(title))
                // Use elseif so prefix match always wins over contains when both apply
                elseif (str_contains($titleLower, $queryLower)) {
                    $score = 600 - abs($queryLen - $titleLen);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestAID = $aid;
                    }
                }
            }
        }

        return $bestAID;
    }

    /**
     * Build the lowercase-title → AID map backing O(1) exact-match lookups.
     *
     * First entry wins: a title can repeat across AIDs in the dump, and the
     * pre-map scan returned the first indexed exact match, so insertion order
     * is preserved deliberately. Called lazily from {@see search()} once per
     * loaded index; {@see injectIndex()} resets the memo.
     *
     * @param list<array{aid: int, titles: list<array{title: string, type: string, lang: string, lower: string, len: int}>}> $index
     *
     * @return array<string, int>
     */
    private function buildExactIndex(array $index): array
    {
        $map = [];

        foreach ($index as $entry) {
            foreach ($entry['titles'] as $titleEntry) {
                $lower = $titleEntry['lower'];
                if (!isset($map[$lower])) {
                    $map[$lower] = $entry['aid'];
                }
            }
        }

        return $map;
    }

    /**
     * Load the title dump index from cache if not yet loaded.
     *
     * @return void
     */
    public function ensureLoaded(): void
    {
        if ($this->titleIndex !== null) {
            return;
        }

        $indexFile = $this->getTitleCachePath();
        $readable = file_exists($indexFile) && is_readable($indexFile);

        // Provenance stamp for ensureFresh()'s reload-on-moved: the mtime AT
        // READ TIME, so a concurrent rewrite shows up as "disk moved" instead
        // of blending silently into this memo.
        $this->loadedIndexMtime = $readable ? $this->fileMtime($indexFile) : null;

        if ($readable) {
            $data = file_get_contents($indexFile);
            if ($data !== false) {
                /** @var mixed $decoded */
                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    $validEntries = $this->validateSchema($decoded);
                    if ($validEntries !== []) {
                        $this->titleIndex = $validEntries;
                        return;
                    }

                    error_log(
                        'TitleDumpManager: title_index.json contained no valid entries, falling back to empty index'
                    );
                }
            }
        }

        // No usable cached index — rely on API lookups. This empty memo is no
        // longer terminal: ensureFresh() re-reads the disk when a valid file
        // appears later in the process's life (M5, the 2-byte-incident class).
        $this->titleIndex = [];
    }

    /**
     * Re-arm the daily freshness machinery inside a live process
     * (review anidb__srphp M5).
     *
     * Called from {@see search()}. Cost per window: one stat, plus — only
     * when stale — a downloadAndIndex() that short-circuits on a fresh file,
     * a held in-flight marker, or a non-writable cache dir, so this can never
     * become a per-lookup download storm. Between windows it is a single
     * integer comparison.
     *
     * @return void
     */
    public function ensureFresh(): void
    {
        if (!$this->isLoaded()) {
            return;
        }

        $now = time();
        if (($now - $this->lastFreshnessCheck) < self::FRESHNESS_CHECK_INTERVAL_SECONDS) {
            return;
        }
        $this->lastFreshnessCheck = $now;

        $indexFile = $this->getTitleCachePath();

        if ($this->indexer()->isStale($indexFile)) {
            // The 24 h TTL + the marker's retry floor finally mean something
            // in the only context that ever had them: a resident worker.
            $this->indexer()->downloadAndIndex();
        }

        $this->reloadIfDiskMoved($indexFile);
    }

    /**
     * Drop and re-read the memo when the on-disk index moved under us.
     *
     * "Moved" is one of two shapes (M5): the file is NEWER than the load-time
     * provenance (an async download in THIS process completed, or a sibling
     * rewrote the shared file), or the memo has no provenance at all yet a
     * readable file now exists — but only for the EMPTY memo; an injected
     * non-empty test index has no disk behind it and must never be stomped
     * by whatever stray file a scratch dir may hold.
     */
    private function reloadIfDiskMoved(string $indexFile): void
    {
        $mtime = $this->fileMtime($indexFile);
        if ($mtime === null) {
            return;
        }

        $moved = $this->loadedIndexMtime === null
            ? $this->titleIndex === []
            : $mtime > $this->loadedIndexMtime;

        if (!$moved) {
            return;
        }

        $this->titleIndex = null;
        $this->exactIndex = null;
        $this->ensureLoaded();
    }

    /**
     * Stat-cache-clearing filemtime accessor: int mtime, or null when the
     * file is gone/unstatable.
     */
    private function fileMtime(string $file): ?int
    {
        clearstatcache(false, $file);
        $mtime = is_file($file) ? filemtime($file) : false;

        return is_int($mtime) ? $mtime : null;
    }

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
     * Drop policy (review anidb__srphp item 6): an entry is all-or-nothing —
     * ONE malformed title (non-array element, or a missing/non-string
     * title|type|lang field) rejects the WHOLE AID entry via `continue 2`.
     * Deliberate: a partially-trusted alias set could mask upstream dump
     * corruption that also affects the surviving titles, so losing one
     * anime's aliases beats searching against suspect data. Pinned by
     * TitleIndexSchemaValidationTest (each drop arm has its own test).
     *
     * @param array<mixed> $entries
     *
     * @return list<array{aid: int, titles: list<array{title: string, type: string, lang: string, lower: string, len: int}>}>
     */
    private function validateSchema(array $entries): array
    {
        /** @var list<array{aid: int, titles: list<array{title: string, type: string, lang: string, lower: string, len: int}>}> $validEntries */
        $validEntries = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            // Validate 'aid' is an int
            if (!isset($entry['aid']) || !is_int($entry['aid'])) {
                continue;
            }

            // Validate 'titles' is an array
            if (!isset($entry['titles']) || !is_array($entry['titles'])) {
                continue;
            }

            /** @var list<array{title: string, type: string, lang: string, lower: string, len: int}> $validatedTitles */
            $validatedTitles = [];

            foreach ($entry['titles'] as $title) {
                if (!is_array($title)) {
                    // Drop-policy arm: one scalar where an object belongs
                    // condemns the whole entry (see docblock above).
                    continue 2;
                }

                if (
                    !isset($title['title'], $title['type'], $title['lang'])
                    || !is_string($title['title'])
                    || !is_string($title['type'])
                    || !is_string($title['lang'])
                ) {
                    // Drop-policy arm: malformed title condemns the whole entry.
                    continue 2;
                }

                $validatedTitles[] = self::canonicalTitleEntry([
                    'title' => $title['title'],
                    'type' => $title['type'],
                    'lang' => $title['lang'],
                ]);
            }

            $validEntries[] = [
                'aid' => $entry['aid'],
                'titles' => $validatedTitles,
            ];
        }

        return $validEntries;
    }

    /**
     * Ensure the cache directory exists and the title index is up to date.
     *
     * @return void
     */
    public function ensureCacheDir(): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // Kick the first (deferred) download at the first lookup — boot and
        // onEnable() are wiring-only. This is the SEEDING entry into the same
        // retained scheduler {@see ensureFresh()} re-arms per search window
        // (M5); the 24 h freshness decision itself lives in
        // {@see TitleDumpIndexer::downloadAndIndex()} / isStale().
        $this->indexer()->downloadAndIndex();
    }

    /**
     * Return the path to the cached title index file.
     *
     * @return string Absolute path to title_index.json.
     */
    public function getTitleCachePath(): string
    {
        return $this->cacheDir . '/title_index.json';
    }

    /**
     * Check if the title index has been loaded.
     *
     * @return bool True if loaded (even if empty), false if not yet loaded.
     */
    public function isLoaded(): bool
    {
        return $this->titleIndex !== null;
    }

    /**
     * Inject a pre-built index for testing purposes.
     *
     * Accepts the minimal persisted fixture shape (title/type/lang only);
     * entries are canonicalized exactly like {@see validateSchema()} does on
     * load, so tests never hand-craft derived fields (M-ANIDB-23).
     *
     * @param list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}> $index
     *
     * @return void
     */
    public function injectIndex(array $index): void
    {
        /** @var list<array{aid: int, titles: list<array{title: string, type: string, lang: string, lower: string, len: int}>}> $normalized */
        $normalized = [];

        foreach ($index as $entry) {
            $titles = [];
            foreach ($entry['titles'] as $titleEntry) {
                $titles[] = self::canonicalTitleEntry($titleEntry);
            }

            $normalized[] = ['aid' => $entry['aid'], 'titles' => $titles];
        }

        $this->titleIndex = $normalized;
        // The exact-match memo is derived state: a fresh index must rebuild it
        // on the next search() (otherwise stale titles would keep resolving).
        $this->exactIndex = null;
    }

    /**
     * Expand a raw {title,type,lang} triple into the canonical in-memory
     * shape by computing the derived `lower`/`len` fields once (M-ANIDB-23).
     *
     * Unknown keys are dropped: this is the single choke point where the
     * canonical shape is minted (both load paths route through it).
     *
     * @param array{title: string, type: string, lang: string} $titleEntry
     *
     * @return array{title: string, type: string, lang: string, lower: string, len: int}
     */
    private static function canonicalTitleEntry(array $titleEntry): array
    {
        return [
            'title' => $titleEntry['title'],
            'type'  => $titleEntry['type'],
            'lang'  => $titleEntry['lang'],
            'lower' => mb_strtolower($titleEntry['title']),
            'len'   => mb_strlen($titleEntry['title']),
        ];
    }
}
