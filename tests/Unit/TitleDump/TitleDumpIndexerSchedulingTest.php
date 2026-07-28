<?php

/**
 * SM-0.3a — scheduling / re-entry tests for TitleDumpIndexer.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit\TitleDump;

use Phlix\Anidb\TitleDump\TitleDumpIndexer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Workerman\Timer;

/**
 * CONSEQUENCE tests for the 2026-07-28 production incident.
 *
 * `TitleDumpIndexer::downloadAndIndex()` deferred its download with
 * `Workerman\Timer::add(0, $fn)` and omitted the 4th argument, which defaults
 * to `$persistent = true`. That is `repeat()`, not `delay()` — an unbounded
 * timer. Measured against the server's own vendored Workerman, one such call
 * re-ran the download 115,302 times in a 0.50 s window under Select and 615
 * times in the same window under the Swoole loop production runs (Swoole
 * clamps the interval to 1 ms and wraps each firing in `Coroutine::create()`,
 * so its firing count is lower while its cost is higher — production showed
 * ~4,990 coroutine creations/second for ten hours). Both are 1 after the fix,
 * and still 1 when the window is widened to 3 s.
 *
 * These tests go RED if the 4th argument is dropped again, if the timer is
 * armed more than once, or if a second downloader can start while one is in
 * flight. They are firing-count tests, not argument-shape assertions: the
 * {@see \Workerman\Timer} double re-invokes a *persistent* callback
 * {@see \Workerman\Timer::REPEAT_FIRINGS} times, exactly as the real
 * `repeat()` does, so a regression shows up as "expected 1 fetch, got 5".
 *
 * The Workerman-facing tests run in a separate process: the double declares the
 * real FQCN `Workerman\Timer`, and leaking that declaration into the shared
 * PHPUnit process would flip `class_exists()` for every other test.
 */
final class TitleDumpIndexerSchedulingTest extends TestCase
{
    private const FIXTURE_DAT = "1|main|x-jat|Bokura no Leader\n2|main|x-jat|Utena\n";

    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->recursiveDelete($dir);
        }
        $this->tmpDirs = [];
    }

    // -------------------------------------------------------------------------
    // Deferral: EXACTLY ONE firing
    // -------------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_deferred_download_fires_exactly_once_not_repeatedly(): void
    {
        $this->loadTimerDouble();

        $dir = $this->makeTmpDir();
        $fetches = 0;

        // The fetch FAILS on purpose. A successful one writes title_index.json,
        // after which the freshness re-check in the callback absorbs any repeat
        // firings — real defence-in-depth, but it would hide the very defect
        // this test exists to catch. Failing keeps every firing visible.
        $indexer = new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', static function (
            string $url,
            callable $onResult,
        ) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        });

        $indexer->downloadAndIndex();

        $this->assertSame(
            1,
            $fetches,
            'the deferred download fired more than once — Timer::add() was armed as a repeating timer',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_deferred_path_still_builds_the_index_on_a_successful_fetch(): void
    {
        $this->loadTimerDouble();

        $dir = $this->makeTmpDir();

        $indexer = new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', static function (
            string $url,
            callable $onResult,
        ): void {
            $onResult(gzencode(self::FIXTURE_DAT, 6));
        });

        $indexer->downloadAndIndex();

        $this->assertFileExists($dir . '/title_index.json');
        /** @var list<array{aid: int, titles: list<array<string, string>>}> $loaded */
        $loaded = json_decode((string) file_get_contents($dir . '/title_index.json'), true);
        $this->assertCount(2, $loaded);
        $this->assertFileDoesNotExist($dir . '/title_index.download.lock');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_download_timer_is_armed_non_persistently_on_the_next_tick(): void
    {
        $this->loadTimerDouble();

        $dir = $this->makeTmpDir();

        $indexer = new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', static function (
            string $url,
            callable $onResult,
        ): void {
            $onResult(null);
        });

        $indexer->downloadAndIndex();

        $this->assertCount(1, Timer::$registrations, 'expected exactly one Timer::add() registration');
        $this->assertFalse(
            Timer::$registrations[0]['persistent'],
            'Timer::add() was called without $persistent = false — it repeats forever',
        );
        $this->assertSame(
            TitleDumpIndexer::DEFER_SECONDS,
            Timer::$registrations[0]['interval'],
            'the deferral interval changed',
        );
    }

    // -------------------------------------------------------------------------
    // Re-entry: repeated scheduling must not stack
    // -------------------------------------------------------------------------

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_repeated_scheduling_on_one_indexer_arms_a_single_timer(): void
    {
        $this->loadTimerDouble();

        $dir = $this->makeTmpDir();
        $fetches = 0;

        // Fetch FAILS, so no index file is ever written and the mtime freshness
        // guard can never mask the stacking we are measuring.
        $indexer = new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', static function (
            string $url,
            callable $onResult,
        ) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        });

        for ($i = 0; $i < 50; ++$i) {
            $indexer->downloadAndIndex();
        }

        $this->assertCount(1, Timer::$registrations, '50 scheduling attempts stacked 50 timers');
        $this->assertSame(1, $fetches, '50 scheduling attempts started more than one download');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_second_indexer_over_the_same_cache_dir_does_not_start_a_second_download(): void
    {
        $this->loadTimerDouble();

        // TitleDumpManager::ensureCacheDir() builds a FRESH TitleDumpIndexer on
        // every call, and the host re-runs the plugin entry-class constructor
        // on every construction — so an instance-level flag alone is bypassed
        // by exactly the vector we must defend against.
        $dir = $this->makeTmpDir();
        $fetches = 0;
        $client = static function (string $url, callable $onResult) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        };

        Timer::$autoRun = false; // keep the first attempt "in flight"
        (new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', $client))->downloadAndIndex();
        (new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', $client))->downloadAndIndex();
        (new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', $client))->downloadAndIndex();

        $this->assertCount(1, Timer::$registrations, 'a fresh indexer armed a second downloader');

        Timer::runQueued();
        $this->assertSame(1, $fetches, 'more than one download ran for the same cache dir');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_an_unusable_cache_dir_refuses_the_download_instead_of_failing_open(): void
    {
        $this->loadTimerDouble();

        // SM-0.3a review, finding 1. acquireInFlightMarker() used to fail OPEN
        // when it could not create the marker, which removed the ONLY guard
        // covering the vector this class exists to defend: TitleDumpManager::
        // ensureCacheDir() builds a FRESH indexer on every call, so the
        // in-process $downloadInFlight flag catches nothing here. Measured
        // against the real Workerman loops: 50 fresh indexers over one 0555
        // cache dir -> 50 downloads (Select AND Swoole). It now fails CLOSED —
        // a directory that cannot hold a zero-byte marker cannot hold
        // title_index.json either, so the download is useless work.
        // The refusal notice would otherwise land on this isolated child's
        // stderr and corrupt PHPUnit's IPC stream. It gets its own assertions
        // in test_the_refusal_is_logged_once_per_process_not_once_per_attempt.
        ini_set('error_log', $this->makeTmpDir() . '/error.log');

        $dir = $this->makeUnusableCacheDir();
        $fetches = 0;
        $client = static function (string $url, callable $onResult) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        };

        for ($i = 0; $i < 50; ++$i) {
            $this->assertFalse(
                (new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', $client))->downloadAndIndex(),
                'a refused download reported success',
            );
        }

        $this->assertCount(
            0,
            Timer::$registrations,
            'a download was armed over a cache dir that can never receive title_index.json',
        );
        $this->assertSame(
            0,
            $fetches,
            'the guard FAILED OPEN: 50 fresh indexers each started a download over an unusable cache dir',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_refusal_is_logged_once_per_process_not_once_per_attempt(): void
    {
        // Failing closed silently would turn a loud incident into a silent one:
        // the dump would simply stop updating. One line per process is enough
        // for an operator to see why; one line per attempt would trade a
        // download storm for a log storm.
        $dir = $this->makeUnusableCacheDir();
        $log = $this->makeTmpDir() . '/error.log';
        ini_set('error_log', $log);

        $client = static function (string $url, callable $onResult): void {
            $onResult(null);
        };

        for ($i = 0; $i < 50; ++$i) {
            (new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', $client))->downloadAndIndex();
        }

        $this->assertFileExists($log, 'the guard refused silently — an operator cannot see why');

        $lines = array_values(array_filter(
            (array) file($log),
            static fn (string $line): bool => str_contains($line, 'TitleDumpIndexer: REFUSING'),
        ));

        $this->assertCount(1, $lines, '50 refusals produced more than one log line');
        $this->assertStringContainsString($dir, $lines[0], 'the notice does not name the unusable directory');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_losing_a_race_to_a_released_marker_is_not_reported_as_an_unusable_cache_dir(): void
    {
        // SM-0.3a review (fix round), finding 1. `fopen($marker, 'x')` fails for
        // TWO different reasons and the follow-up stat cannot tell them apart:
        // the cache directory is unusable, OR it was ordinary EEXIST contention
        // and the owner released its marker (releaseInFlightMarker(), which only
        // ever runs after a SUCCESSFUL download) in the microseconds since. The
        // second was reported as the first — an alarming line naming the wrong
        // cause AND the wrong remedy, emitted on the SUCCESS path, i.e. every
        // time the dump actually refreshed across a fleet. Measured: 35
        // processes, hard start barrier, one shared cache dir, succeeding fetch
        // — 11 spurious refusals over 8 trials before, 0 after, with the
        // concurrency guard itself pinned at 1 fetch per trial throughout.
        //
        // That race is microseconds wide, so it cannot be expressed inside one
        // PHP process. What this test pins is the STATE the race produces, held
        // open deterministically: the atomic create fails, the marker is not a
        // regular file, and the directory itself is perfectly healthy. It uses
        // no permission bits, so it reproduces identically for every uid
        // including root (the trap F4 removed from these tests).
        $log = $this->makeTmpDir() . '/error.log';
        ini_set('error_log', $log);

        $dir = $this->makeTmpDir();
        mkdir($dir . '/title_index.download.lock', 0755);

        $fetches = 0;
        $client = static function (string $url, callable $onResult) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        };

        $this->assertTrue(
            (new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', $client))->downloadAndIndex(),
            'losing the marker race was reported as an unusable cache directory',
        );
        $this->assertSame(0, $fetches, 'a second download started while another attempt owned the claim');
        $this->assertSame(
            [],
            $this->refusalLines($log),
            'losing a race to a SUCCESSFUL release was logged as an unusable cache directory',
        );

        // …and the once-per-process notice is still there for the case that
        // genuinely needs it. This is the half the false positive used to eat:
        // $refusalLogged latches, so ONE spurious refusal silenced every later
        // real one in that worker for the rest of its life.
        $unusable = $this->makeUnusableCacheDir();

        $this->assertFalse(
            (new TitleDumpIndexer($unusable, 'http://example.invalid/dump.gz', $client))->downloadAndIndex(),
            'a genuinely unusable cache dir stopped refusing',
        );
        $this->assertSame(0, $fetches, 'a download ran over a cache dir that can never hold the index');

        $lines = $this->refusalLines($log);
        $this->assertCount(1, $lines, 'the genuine refusal was swallowed by the earlier false positive');
        $this->assertStringContainsString($unusable, $lines[0], 'the notice does not name the unusable directory');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_an_in_flight_attempt_is_not_restarted_when_its_marker_disappears(): void
    {
        $this->loadTimerDouble();

        // The cross-process marker is a FILE, and a file can be removed under a
        // live attempt: a cache sweep, an `rm -rf` of the cache dir, an
        // operator following the incident runbook. That is the one state where
        // the in-process $downloadInFlight flag is load bearing — and it needs
        // no filesystem permissions to reach, so it proves the guard for every
        // user including root.
        $dir = $this->makeTmpDir();
        $fetches = 0;
        $indexer = new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', static function (
            string $url,
            callable $onResult,
        ) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        });

        Timer::$autoRun = false; // the first attempt is armed and still queued
        $indexer->downloadAndIndex();
        $this->assertCount(1, Timer::$registrations);
        $this->assertFileExists($dir . '/title_index.download.lock');

        unlink($dir . '/title_index.download.lock');

        for ($i = 0; $i < 50; ++$i) {
            $indexer->downloadAndIndex();
        }

        $this->assertCount(
            1,
            Timer::$registrations,
            'a second downloader was armed after the in-flight marker was deleted underneath it',
        );

        Timer::runQueued();
        $this->assertSame(1, $fetches, 'more than one download ran for the same cache dir');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_deferred_download_is_skipped_when_the_index_turned_fresh_while_queued(): void
    {
        $this->loadTimerDouble();

        $dir = $this->makeTmpDir();
        $fetches = 0;

        $indexer = new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', static function (
            string $url,
            callable $onResult,
        ) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        });

        Timer::$autoRun = false;
        $indexer->downloadAndIndex();
        $this->assertCount(1, Timer::$registrations);

        // Another worker sharing the cache dir wins the race and writes a fresh
        // index between "armed" and "fired".
        file_put_contents($dir . '/title_index.json', '[]');

        Timer::runQueued();

        $this->assertSame(0, $fetches, 'the queued download re-fetched an index that was already fresh');
    }

    // -------------------------------------------------------------------------
    // Cross-process in-flight marker (no Workerman needed — synchronous path)
    // -------------------------------------------------------------------------

    public function test_a_fresh_in_flight_marker_blocks_a_second_downloader(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/title_index.download.lock', '');

        $fetches = 0;
        $indexer = new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', static function (
            string $url,
            callable $onResult,
        ) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        });

        $this->assertTrue($indexer->downloadAndIndex());
        $this->assertSame(0, $fetches, 'a download started while another one was in flight');
        $this->assertFileExists(
            $dir . '/title_index.download.lock',
            'the blocked caller deleted the owner\'s in-flight marker',
        );
    }

    public function test_a_stale_in_flight_marker_is_reclaimed(): void
    {
        $dir = $this->makeTmpDir();
        $marker = $dir . '/title_index.download.lock';
        file_put_contents($marker, '');
        // Older than the TTL: the owner died mid-download and must not wedge
        // the title dump forever.
        touch($marker, time() - (TitleDumpIndexer::IN_FLIGHT_TTL_SECONDS + 60));

        $fetches = 0;
        $indexer = new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', static function (
            string $url,
            callable $onResult,
        ) use (&$fetches): void {
            ++$fetches;
            $onResult(gzencode(self::FIXTURE_DAT, 6));
        });

        $this->assertTrue($indexer->downloadAndIndex());
        $this->assertSame(1, $fetches, 'a stale in-flight marker permanently blocked the download');
        $this->assertFileExists($dir . '/title_index.json');
    }

    public function test_a_stale_marker_is_not_reclaimed_while_another_racer_holds_the_claim_lock(): void
    {
        // SM-0.3a review, finding 2. Reclaiming a stale marker is a
        // read-modify-write (read mtime -> decide stale -> take ownership) and
        // the old code did it with a bare @touch(), which is not a claim: every
        // racer that read the stale mtime touched it and proceeded. Measured
        // with a hard start barrier: worst 8 concurrent downloads out of 8
        // processes, and worst 14 out of 35 — production's fleet size. With the
        // lock: exactly 1, in every trial, at both sizes.
        //
        // Concurrency cannot be expressed inside one PHP process, so this test
        // pins the MECHANISM that makes the reclaim exclusive: the racer that
        // won holds flock(LOCK_EX) on the marker while it decides. Standing in
        // for that racer, this test takes the same lock. Under the @touch()
        // reclaim the lock means nothing and the download starts anyway.
        $dir = $this->makeTmpDir();
        $marker = $dir . '/title_index.download.lock';
        file_put_contents($marker, '');
        touch($marker, time() - (TitleDumpIndexer::IN_FLIGHT_TTL_SECONDS + 60));

        $lock = fopen($marker, 'r+');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'could not take the reclaim lock');

        $fetches = 0;
        $client = static function (string $url, callable $onResult) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        };

        $this->assertTrue((new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', $client))->downloadAndIndex());
        $this->assertSame(
            0,
            $fetches,
            'a second racer reclaimed a stale marker while the reclaim was still being decided',
        );

        // …and the lock is NOT a wedge: once the racer is gone the marker is
        // reclaimable again on the very next attempt.
        flock($lock, LOCK_UN);
        fclose($lock);

        $this->assertFalse((new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', $client))->downloadAndIndex());
        $this->assertSame(1, $fetches, 'the stale marker stayed unreclaimable after the racer released it');
    }

    public function test_a_future_dated_marker_is_treated_as_stale_and_reclaimed_exactly_once(): void
    {
        // SM-0.3a review, finding 3. `time() - $mtime` goes NEGATIVE for a
        // future-dated marker, and a negative number is < IN_FLIGHT_TTL_SECONDS,
        // so the TTL never expired it: measured 5 attempts, 0 fetches with the
        // mtime 7 days ahead. Reachable via a backwards NTP step, a restored VM
        // snapshot, a backup restored from a skewed box, or a stray `touch -d`
        // — the incident runbook hands operators exactly that command for
        // title_index.json, the neighbouring file in this same directory.
        $dir = $this->makeTmpDir();
        $marker = $dir . '/title_index.download.lock';
        file_put_contents($marker, '');
        touch($marker, time() + (7 * 86400));

        $fetches = 0;
        $client = static function (string $url, callable $onResult) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        };

        // Five FRESH indexers, i.e. the ensureCacheDir() re-construction vector.
        for ($i = 0; $i < 5; ++$i) {
            (new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', $client))->downloadAndIndex();
        }

        $this->assertSame(
            1,
            $fetches,
            $fetches === 0
                ? 'a future-dated marker wedged the downloader for the whole clock skew'
                : 'treating a future-dated marker as stale must not also drop the TTL retry floor',
        );

        clearstatcache();
        $this->assertLessThanOrEqual(
            time(),
            (int) filemtime($marker),
            'the reclaim left the marker dated in the future, so the next attempt is wedged again',
        );
    }

    public function test_the_in_flight_marker_is_released_after_a_successful_attempt(): void
    {
        $dir = $this->makeTmpDir();

        $indexer = new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', static function (
            string $url,
            callable $onResult,
        ): void {
            $onResult(gzencode(self::FIXTURE_DAT, 6));
        });

        $indexer->downloadAndIndex();

        $this->assertFileDoesNotExist($dir . '/title_index.download.lock');
    }

    public function test_a_failed_attempt_keeps_its_marker_as_a_retry_floor(): void
    {
        $dir = $this->makeTmpDir();
        $fetches = 0;

        // A fast-failing fetch (DNS error, instant 5xx) must NOT let the next
        // scheduling attempt start another download straight away.
        $indexer = new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', static function (
            string $url,
            callable $onResult,
        ) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        });

        $this->assertFalse($indexer->downloadAndIndex());
        $this->assertSame(1, $fetches);
        $this->assertFileExists($dir . '/title_index.download.lock');

        // A brand-new indexer (the "provider re-constructed" vector) is held off
        // until the marker ages past IN_FLIGHT_TTL_SECONDS.
        $retry = new TitleDumpIndexer($dir, 'http://example.invalid/dump.gz', static function (
            string $url,
            callable $onResult,
        ) use (&$fetches): void {
            ++$fetches;
            $onResult(null);
        });

        $this->assertTrue($retry->downloadAndIndex());
        $this->assertSame(1, $fetches, 'a failed attempt was retried immediately instead of backing off');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Install the {@see \Workerman\Timer} double for this (isolated) process.
     */
    private function loadTimerDouble(): void
    {
        if (class_exists(Timer::class, false)) {
            self::markTestSkipped('a real Workerman\Timer is already loaded in this process');
        }

        require_once dirname(__DIR__, 3) . '/tests/Fixture/WorkermanTimerSpy.php';
        Timer::reset();
    }

    /**
     * The "REFUSING …" notices written to a given error log so far.
     *
     * @return list<string>
     */
    private function refusalLines(string $log): array
    {
        if (!is_file($log)) {
            return [];
        }

        return array_values(array_filter(
            (array) file($log),
            static fn (string $line): bool => str_contains($line, 'TitleDumpIndexer: REFUSING'),
        ));
    }

    private function makeTmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/anidb_sched_' . bin2hex(random_bytes(8));
        mkdir($dir, 0755, true);
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    /**
     * A cache-dir path that can never be created or written — for ANY user.
     *
     * `chmod 0555` would be the obvious way to build this, but it is not a
     * property of the directory, it is a property of the caller: root's
     * `is_writable()` returns true on a `0555` directory and its `fopen(…,'x')`
     * succeeds, so a permission-based test quietly self-skips (or worse, passes
     * for the wrong reason) under root — SM-0.3a review, finding 4.
     *
     * Nesting the cache dir under a regular FILE makes every mkdir/open there
     * fail with ENOTDIR, which the kernel enforces against root too. The state
     * under test is therefore reproduced identically for every user, with no
     * skip and no permission juggling.
     */
    private function makeUnusableCacheDir(): string
    {
        $base = $this->makeTmpDir();
        $blocker = $base . '/this-is-a-regular-file';
        file_put_contents($blocker, 'not a directory');

        return $blocker . '/anidb-cache';
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
