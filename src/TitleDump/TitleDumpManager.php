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
 */
final class TitleDumpManager
{
    /**
     * Title dump index for fast offline search.
     *
     * Grouped by AID: list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}>
     *
     * @var list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}>|null
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
     * @param string $cacheDir     Directory for cached index file.
     * @param string $titleDumpUrl URL to anime-titles.dat.gz.
     */
    public function __construct(string $cacheDir, string $titleDumpUrl)
    {
        $this->cacheDir = $cacheDir;
        $this->titleDumpUrl = $titleDumpUrl;
    }

    /**
     * Search the title dump index for a matching anime.
     *
     * The index is grouped by AID. Each entry contains:
     *   ["aid" => 12345, "titles" => [["title" => "...", "type" => "main", "lang" => "en"], ...]]
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
                $titleLower = $titleEntry['lower_title'] ?? mb_strtolower($titleEntry['title']);
                $titleLen = mb_strlen($titleEntry['title']);

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
     * @param list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}> $index
     *
     * @return array<string, int>
     */
    private function buildExactIndex(array $index): array
    {
        $map = [];

        foreach ($index as $entry) {
            foreach ($entry['titles'] as $titleEntry) {
                $lower = mb_strtolower($titleEntry['title']);
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

        if (file_exists($indexFile) && is_readable($indexFile)) {
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

        // If no cached index, we'll rely on API lookups
        $this->titleIndex = [];
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
     * @return list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}>
     */
    private function validateSchema(array $entries): array
    {
        /** @var list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}> $validEntries */
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

            /** @var list<array{title: string, type: string, lang: string}> $validatedTitles */
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

                $validatedTitles[] = [
                    'title' => $title['title'],
                    'type' => $title['type'],
                    'lang' => $title['lang'],
                ];
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

        // Build and persist the title index if not present or stale.
        // This runs once on plugin enable; subsequent lookups reuse the cached index.
        $indexer = new TitleDumpIndexer(
            $this->cacheDir,
            $this->titleDumpUrl,
        );
        $indexer->downloadAndIndex();
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
     * @param list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}> $index
     *
     * @return void
     */
    public function injectIndex(array $index): void
    {
        $this->titleIndex = $index;
        // The exact-match memo is derived state: a fresh index must rebuild it
        // on the next search() (otherwise stale titles would keep resolving).
        $this->exactIndex = null;
    }
}
