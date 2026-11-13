<?php

/**
 * Freshness re-entry tests for the resident-process title index.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit\TitleDump;

use Phlix\Anidb\TitleDump\TitleDumpIndexer;
use Phlix\Anidb\TitleDump\TitleDumpManager;
use PHPUnit\Framework\TestCase;

/**
 * Freshness re-entry for the resident-process title index
 * (review anidb__srphp M5).
 *
 * Before the cure, ensureLoaded() memoized $titleIndex permanently — the
 * "daily" dump was in reality loaded EXACTLY ONCE per process. A worker
 * running for weeks served alias lookups frozen at boot-day, and a provider
 * whose cold download failed kept an empty in-memory index forever even
 * after a sibling worker had written a valid file to the shared cache dir.
 * MAX_AGE_SECONDS / the in-flight retry floor were dead logic in the only
 * context where they matter (a resident Workerman worker).
 *
 * Every case drives the real disk path through the public search() entry
 * point — hermetic temp dirs + fake HTTP seams, no network.
 */
final class TitleDumpManagerFreshnessTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/anidb-freshness-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }
        foreach (array_diff((array) scandir($this->tmpDir), ['.', '..']) as $entry) {
            $path = $this->tmpDir . '/' . $entry;
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * A fresh index file must NOT provoke any re-download — the freshness
     * re-entry is staleness-gated, not a per-search hammer.
     */
    public function test_fresh_index_on_disk_is_never_refetched(): void
    {
        $this->writeIndexJson([
            ['aid' => 1, 'titles' => [['title' => 'Fresh Show', 'type' => 'main', 'lang' => 'en']]],
        ]);

        $httpCalls = 0;
        $manager = $this->manager(static function () use (&$httpCalls): void {
            ++$httpCalls;
        });

        $this->assertSame(1, $manager->search('Fresh Show'));
        $this->assertSame(1, $manager->search('Fresh Show'));

        $this->assertSame(0, $httpCalls, 'a fresh index file must short-circuit before any fetch');
    }

    /**
     * The production incident: a >24 h old index must be re-downloaded
     * inside the live process, and the NEW content must become servable.
     * Old code memoized forever and never re-fetched — red until cured.
     */
    public function test_stale_index_triggers_redownload_and_serves_the_new_content(): void
    {
        $indexFile = $this->writeIndexJson([
            ['aid' => 1, 'titles' => [['title' => 'Old Alias', 'type' => 'main', 'lang' => 'en']]],
        ]);
        // Older than TitleDumpIndexer::MAX_AGE_SECONDS — deterministically stale.
        touch($indexFile, time() - TitleDumpIndexer::MAX_AGE_SECONDS - 60);

        $httpCalls = 0;
        $manager = $this->manager(
            /** @param callable(?string):void $onResult */
            static function (string $url, callable $onResult) use (&$httpCalls): void {
                ++$httpCalls;
                $dat = "# aid|type|lang|title\n2|main|en|Renewed Alias\n";
                $onResult(gzencode($dat, 6));
            }
        );

        $this->assertSame(
            2,
            $manager->search('Renewed Alias'),
            'a stale in-process index must be refreshed and the new entries served'
        );
        $this->assertSame(1, $httpCalls);
        // The old alias only exists in the NEW dump's file? No — it existed in v1
        // and not in v2: after the refresh the v1-only title is gone. That is the
        // honest shape (last-writer-wins on the shared index file).
        $this->assertNull($manager->search('Old Alias'));
    }

    /**
     * Cold-failure recovery: when the process's own download failed (empty
     * memo) and a SIBLING worker's file has since landed in the shared cache
     * dir, the next search window must pick it up. Old code: the empty memo
     * was permanent — the 2-byte-incident class.
     */
    public function test_empty_memo_recovers_when_a_sibling_writes_the_index(): void
    {
        // Our own download fails (no body) — no file, empty memo.
        $manager = $this->manager(static function (string $url, callable $onResult): void {
            $onResult(null);
        });

        $this->assertNull($manager->search('Late Arrival'));

        // A sibling worker completes a download into the shared cache dir.
        $this->writeIndexJson([
            ['aid' => 5, 'titles' => [['title' => 'Late Arrival', 'type' => 'main', 'lang' => 'en']]],
        ]);
        $this->resetFreshnessThrottle($manager);

        $this->assertSame(
            5,
            $manager->search('Late Arrival'),
            'an empty in-process memo must re-read the disk when a valid file appears'
        );
    }

    /**
     * Async completion inside THIS process: the timer-fired download rewrites
     * the file; the memo must be invalidated on the next freshness window
     * (mtime moved), not pinned to the boot-day load.
     */
    public function test_file_replaced_under_a_loaded_memo_is_reloaded(): void
    {
        $indexFile = $this->writeIndexJson([
            ['aid' => 1, 'titles' => [['title' => 'First Content', 'type' => 'main', 'lang' => 'en']]],
        ]);
        // Past-dated by an hour: fresh under the TTL (no download expected
        // below), yet leaving headroom for the rewrite to move the mtime
        // FORWARD without going future. A future-dated index is stale by the
        // clock-skew law (isStale/indexMtimeIsFresh, review anidb__srphp #4),
        // so the old `touch(time() + 10)` trick now legitimately provokes a
        // refresh — reload-on-moved must be driven by a REAL forward move.
        touch($indexFile, time() - 3600);

        $manager = $this->manager(function (): void {
            // Never invoked: the file starts fresh, so no download is expected.
            $this->fail('no fetch expected for a fresh file');
        });

        $this->assertSame(1, $manager->search('First Content'));

        // An async completion (or another worker) rewrote the file — write
        // FIRST, then bump the mtime (the write itself would reset it).
        $this->writeIndexJson([
            ['aid' => 7, 'titles' => [['title' => 'Async Arrival', 'type' => 'main', 'lang' => 'en']]],
        ], $indexFile);
        touch($indexFile, time() - 10);
        $this->resetFreshnessThrottle($manager);

        $this->assertSame(7, $manager->search('Async Arrival'));
        $this->assertNull($manager->search('First Content'));
    }

    /**
     * w15L4 add-on (review REVIEW-w14L7anidb.md Minor): a DETERMINISTIC pin
     * for the clearstatcache cure leg in isStale() — the leg M1-strip left
     * unpinned (the old suite only caught it via a historical CI accident).
     *
     * The recipe is the reviewer's own: seed the plain-file stat cache with a
     * pre-mutation stat, mutate the file's mtime out from under the process
     * (exactly what a sibling worker's write or the operator `touch -d` from
     * the SM-0.3a runbook does to a resident worker), then require isStale()
     * to decide from CURRENT disk truth. With the leg stripped, is_file()
     * and filemtime() inside isStale() serve the cached pre-touch stat and
     * the future-dated index reads fresh — this test goes red.
     */
    public function test_is_stale_decides_on_disk_truth_over_a_seeded_stat_cache(): void
    {
        $index = $this->writeIndexJson([
            ['aid' => 1, 'titles' => [['title' => 'Cached Show', 'type' => 'main', 'lang' => 'en']]],
        ]);

        // Seed the stat cache with the pre-mutation mtime.
        $this->assertTrue(is_file($index));

        // Mutate the index behind the process's back: future-dated mtime is
        // the dead-age arm of the freshness law (PG-152) — disk truth says
        // stale, the seeded cache says fresh.
        touch($index, time() + 3600);

        $indexer = new TitleDumpIndexer($this->tmpDir, 'https://anidb.invalid/api/anime-titles.dat.gz');

        $this->assertTrue(
            $indexer->isStale($index),
            'isStale() answered from the seeded stat cache — the clearstatcache(true, path) leg is gone'
        );
    }

    // -------------------------------------------------------------------------

    /**
     * @param callable(string,callable):void $httpClient
     */
    private function manager(callable $httpClient): TitleDumpManager
    {
        return new TitleDumpManager($this->tmpDir, 'https://anidb.invalid/api/anime-titles.dat.gz', $httpClient);
    }

    /**
     * ANI-21: an index file over the byte cap is never decoded — the manager
     * logs and falls back to an empty index instead of slurping it.
     */
    public function test_oversize_index_file_falls_back_to_empty_index(): void
    {
        $file = $this->writeIndexJson([
            ['aid' => 1, 'titles' => [['title' => 'Big Show', 'type' => 'main', 'lang' => 'en']]],
        ]);
        $size = (int) filesize($file);

        $logFile = $this->tmpDir . '/php-error.log';
        $prev = ini_set('error_log', $logFile);
        try {
            // Cap one byte under the file size: oversize.
            $over = new TitleDumpManager($this->tmpDir, 'https://example.test/dump.gz', null, $size - 1);
            $over->ensureLoaded();
            $this->assertNull($over->search('Big Show'));

            // Cap exactly at the file size: accepted.
            $at = new TitleDumpManager($this->tmpDir, 'https://example.test/dump.gz', null, $size);
            $at->ensureLoaded();
            $this->assertSame(1, $at->search('Big Show'));
        } finally {
            ini_set('error_log', (string) $prev);
        }

        $log = (string) file_get_contents($logFile);
        $this->assertStringContainsString('exceeds ' . ($size - 1) . ' bytes', $log);
        $this->assertStringNotContainsString('exceeds ' . $size . ' bytes', $log);
    }

    /**
     * @param list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}> $entries
     */
    private function writeIndexJson(array $entries, ?string $at = null): string
    {
        $file = $at ?? ($this->tmpDir . '/title_index.json');
        file_put_contents($file, json_encode($entries, JSON_PRETTY_PRINT));

        return $file;
    }

    /**
     * Jump the throttle so the next search() performs a freshness pass
     * without the suite waiting 60 real seconds.
     */
    private function resetFreshnessThrottle(TitleDumpManager $manager): void
    {
        $prop = new \ReflectionProperty(TitleDumpManager::class, 'lastFreshnessCheck');
        $prop->setAccessible(true);
        $prop->setValue($manager, 0);
    }
}
