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
     * Deferral interval, in seconds, for the ONE-SHOT download timer.
     *
     * 0 = "run on the next event-loop tick", which is the entire intent of the
     * deferral: hand control back to the worker immediately and do the HTTP
     * work outside the current call stack. It is safe on every event loop
     * *because the timer is armed non-persistently* (see
     * {@see downloadAndIndex()}):
     *   - Select/Event/Ev/Fiber `delay(0)` → fires once on the next iteration;
     *   - Swoole `delay(0)`  → `Timer::after(max((int)(0*1000), 1))`, i.e.
     *     clamped to 1 ms, fires once;
     *   - Swow  `delay(0)`   → `msleep(max(0,1))` in one coroutine, fires once;
     *   - the pcntl-ALARM fallback (no event loop) → one task, dropped after it
     *     runs because `$persistent` is false.
     * The clamp only matters for a *repeating* timer, where it sets the storm
     * rate at ~1000 firings/second.
     */
    public const DEFER_SECONDS = 0.0;

    /**
     * How long an in-flight marker is honoured before it is treated as stale
     * and reclaimed, in seconds.
     *
     * MUST exceed the 60 s cooperative-wait budget in
     * {@see defaultHttpClient()}, otherwise a legitimately slow download would
     * be treated as abandoned and a second downloader would start alongside it.
     *
     * The TTL has two jobs:
     *   1. a worker killed mid-download must not wedge the index permanently;
     *   2. a FAILED attempt keeps its marker, so the TTL doubles as a retry
     *      floor. Without it, a fast-failing fetch (DNS error, instant 5xx)
     *      would let every subsequent scheduling attempt start a new download
     *      immediately — a slower storm, but still a storm, and AniDB bans on
     *      request floods.
     */
    public const IN_FLIGHT_TTL_SECONDS = 120;

    /**
     * Name of the cross-process in-flight marker, kept beside the index file.
     */
    private const IN_FLIGHT_MARKER = 'title_index.download.lock';

    /**
     * {@see acquireInFlightMarker()}: this instance owns the attempt.
     */
    private const ACQUIRE_OK = 0;

    /**
     * {@see acquireInFlightMarker()}: another downloader owns the attempt
     * (ordinary contention — try again after the marker ages out).
     */
    private const ACQUIRE_BUSY = 1;

    /**
     * {@see acquireInFlightMarker()}: the cache directory cannot hold a marker
     * at all, so the download is REFUSED (fail closed — see that method).
     */
    private const ACQUIRE_REFUSED = 2;

    /**
     * Whether the fail-closed refusal has already been reported by this process.
     *
     * Process-lifetime state, NOT request state — so it does not belong in
     * `support\Context` — and bounded to a single bool, so it cannot leak in a
     * resident worker. Its only job is to keep the refusal notice from becoming
     * a log storm of its own: an operator needs the reason once per worker, not
     * once per lookup.
     */
    private static bool $refusalLogged = false;

    /**
     * Path to the cache directory.
     */
    private string $cacheDir;

    /**
     * True between arming the deferred download and the attempt completing.
     *
     * Cheap in-process short-circuit so a repeated {@see downloadAndIndex()}
     * call on THIS object never re-arms the timer. It is deliberately NOT the
     * only guard: {@see \Phlix\Anidb\TitleDump\TitleDumpManager::ensureCacheDir()}
     * builds a fresh indexer on every call, so an instance flag alone would be
     * bypassed by the very re-entry vector we are defending against — see the
     * marker file for the cross-instance / cross-worker guard.
     *
     * Conversely, the marker cannot cover everything either: it is a file, and
     * a file can be deleted under a live attempt (a cache sweep, `rm -rf` of
     * the cache dir, an operator following the incident runbook). That is the
     * one state where THIS flag is what stops the same object from starting a
     * second download, and there is a test pinning exactly that.
     */
    private bool $downloadInFlight = false;

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
     * B5: Uses Workerman\Timer to defer the HTTP call to the next event loop
     * tick so the Workerman worker returns to its loop immediately. Falls back
     * to synchronous execution when Workerman\Timer is unavailable (unit tests,
     * CLI).
     *
     * ┌── SM-0.3a — DO NOT DROP THE 4th ARGUMENT ────────────────────────────┐
     * │ `Timer::add(float, callable, ?array $args = [], bool $persistent =   │
     * │ true)` — the 4th parameter defaults to TRUE, so `Timer::add(0, $fn)` │
     * │ is `$event->repeat(0, ...)`, NOT `delay(0, ...)`. That is a timer    │
     * │ that fires forever, and it took down production on 2026-07-28.       │
     * │                                                                      │
     * │ Measured against the server's own vendored Workerman (real class,    │
     * │ real loop, 0.50 s window, one downloadAndIndex() call whose fetch    │
     * │ fails — the production shape). Figures are the SM-0.3a reviewer's    │
     * │ independent reproduction:                                            │
     * │                                                                      │
     * │     loop                     BEFORE (persistent)   AFTER (one-shot)  │
     * │     Select                   115,302 firings       1                 │
     * │     Swoole (the loop prod runs)   615 firings      1                 │
     * │                                                                      │
     * │ Still exactly 1 when the window is widened to 3 s, on both loops.    │
     * │ Swoole clamps the interval to 1 ms and wraps every firing in         │
     * │ `Coroutine::create()`, which is why its raw firing count is lower    │
     * │ while its cost is higher (~5 coroutines per firing; prod showed      │
     * │ ~4,990 coroutine creations/second for ten hours).                    │
     * │ The result was a permanent outbound connection storm at anidb.net    │
     * │ and title_index.json being rewritten thousands of times a second.    │
     * │ Same landmine as the 2026-07-10 Swoole timer incident.               │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * Re-entry is guarded on two levels, because a one-shot timer still
     * re-arms if the *scheduling* path is entered repeatedly:
     *   1. {@see $downloadInFlight} — same object, cheap, no syscall. Load
     *      bearing only when the marker is removed under a live attempt;
     *   2. an exclusive marker file in the cache dir — survives a fresh
     *      indexer/manager/provider instance (the host re-runs the entry-class
     *      constructor on every construction) and covers the whole worker
     *      fleet, which all share one cache dir. It carries a TTL so a worker
     *      killed mid-download cannot wedge the index forever, and it fails
     *      CLOSED if it cannot be created at all — see
     *      {@see acquireInFlightMarker()}.
     *
     * @return bool True when scheduled, already in flight, already fresh, or
     *     completed synchronously in CLI/tests. False when the attempt failed,
     *     or when it was REFUSED because the cache directory cannot hold the
     *     in-flight marker. Note: in production the actual result of a
     *     scheduled download is delivered via callback.
     */
    public function downloadAndIndex(): bool
    {
        if ($this->downloadInFlight) {
            return true;
        }

        $indexFile = $this->indexFilePath();

        if (is_file($indexFile) && !$this->isStale($indexFile)) {
            return true;
        }

        $acquisition = $this->acquireInFlightMarker();

        if ($acquisition === self::ACQUIRE_REFUSED) {
            // FAIL CLOSED. The cache dir cannot hold the marker, so it cannot
            // hold title_index.json either — the download could only burn
            // bandwidth and CPU and never produce a usable index. Logged once.
            return false;
        }

        if ($acquisition === self::ACQUIRE_BUSY) {
            // Another indexer instance — in this worker or another one sharing
            // the cache dir — is already downloading. Never start a second.
            return true;
        }

        $this->downloadInFlight = true;

        // B5: Use Timer to defer the HTTP call to the next event loop tick.
        // This prevents blocking the Workerman event loop during the request.
        if (class_exists(\Workerman\Timer::class)) {
            \Workerman\Timer::add(self::DEFER_SECONDS, function (): void {
                $this->runDeferredDownload();
            }, [], false); // ← $persistent = false: ONE-SHOT. See the box above.

            return true;
        }

        // Synchronous fallback for CLI / unit tests
        return $this->runDeferredDownload();
    }

    /**
     * Body of the deferred download: re-check freshness, run the attempt, then
     * always clear the in-flight state.
     *
     * The freshness re-check matters because time passes between arming the
     * timer and the callback firing — another worker sharing the cache dir may
     * have written a fresh index in the meantime, and re-downloading 1.4 MB
     * from AniDB for nothing is exactly the traffic this class must not
     * generate.
     *
     * The marker is released only on SUCCESS. After a failure (or a throw) it
     * is deliberately left in place so {@see IN_FLIGHT_TTL_SECONDS} acts as a
     * retry floor — see that constant.
     *
     * @return bool True on success (or when the index turned out to be fresh),
     *     false on failure.
     */
    private function runDeferredDownload(): bool
    {
        $succeeded = false;

        try {
            $indexFile = $this->indexFilePath();

            $succeeded = is_file($indexFile) && !$this->isStale($indexFile)
                ? true
                : $this->doDownloadAndIndex();

            return $succeeded;
        } finally {
            $this->downloadInFlight = false;

            if ($succeeded) {
                $this->releaseInFlightMarker();
            }
        }
    }

    /**
     * Claim the right to run a download, atomically and across processes.
     *
     * `fopen(..., 'x')` is an atomic create-if-absent (`O_CREAT|O_EXCL`), so of
     * N workers racing on the shared cache dir exactly one wins. A marker left
     * behind by a worker that died mid-download is reclaimed once it is older
     * than {@see IN_FLIGHT_TTL_SECONDS}.
     *
     * ┌── SM-0.3a review, finding 1 — THIS FAILS CLOSED ON PURPOSE ──────────┐
     * │ An earlier revision returned "go ahead" when the marker could not be │
     * │ created for a reason other than contention (unwritable cache dir,    │
     * │ full disk, the directory removed under us). That removed the ONLY    │
     * │ guard covering the vector this class exists to defend: because       │
     * │ TitleDumpManager::ensureCacheDir() builds a FRESH indexer on every   │
     * │ call, the in-process {@see $downloadInFlight} flag catches nothing   │
     * │ there. Measured: 50 fresh indexers over one `0555` cache dir → 50    │
     * │ downloads, on BOTH the Select and Swoole loops. Failing closed makes │
     * │ that 0.                                                              │
     * │                                                                      │
     * │ The decisive argument is not caution, it is arithmetic: a cache dir  │
     * │ that will not accept a zero-byte marker will not accept              │
     * │ title_index.json either, so the download is USELESS WORK — it can    │
     * │ only burn bandwidth and CPU and can never produce a usable index.    │
     * │ There is no state in which proceeding helps.                         │
     * │                                                                      │
     * │ The /var/artwork sandbox incident is the precedent, and it argues    │
     * │ the SAME way: there, an unwritable directory is what CAUSED a        │
     * │ download flood. The refusal is logged once per process so an         │
     * │ operator can see why the dump stopped updating.                      │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @return self::ACQUIRE_* One of {@see ACQUIRE_OK} (this instance owns the
     *     attempt), {@see ACQUIRE_BUSY} (another downloader owns it) or
     *     {@see ACQUIRE_REFUSED} (the cache dir cannot hold a marker).
     */
    private function acquireInFlightMarker(): int
    {
        $result = $this->claimInFlightMarker();

        if ($result === self::ACQUIRE_REFUSED) {
            $this->reportUnusableCacheDir();
        }

        return $result;
    }

    /**
     * Body of {@see acquireInFlightMarker()}, without the logging.
     *
     * @return self::ACQUIRE_*
     */
    private function claimInFlightMarker(): int
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }

        $marker = $this->inFlightMarkerPath();

        // Uncontended path: atomic create-if-absent, so of N racers on the
        // shared cache dir exactly one wins.
        //
        // An earlier revision claimed here that "O_EXCL refuses to follow a
        // symlink, so a planted link cannot redirect the marker". Measured, and
        // it is only half true: a symlink pointing at an EXISTING file does make
        // the create fail (and leaves that file untouched), but PHP's plain-files
        // wrapper resolves the path first, so a planted DANGLING symlink is
        // followed and the create lands on its target.
        //
        // What the redirect leaves behind was then MIS-stated as "release would
        // unlink the target". It does not: `unlink(2)` does not follow symlinks.
        // Driven through this class on the success path, so releaseInFlightMarker()
        // actually ran: the LINK at the marker path is gone, and the file the
        // redirect created SURVIVES (target exists=true, size=0). Nothing is ever
        // overwritten either, because the marker is zero bytes. So the residual
        // primitive is file CREATION at an attacker-chosen path — not deletion,
        // and not corruption.
        //
        // `touch()` is the opposite, and it is the one that reaches an EXISTING
        // file: it DOES follow a symlink, so a link planted at the marker path
        // lets reclaimIfStale() clobber the target's mtime (measured: mtime
        // moved from -86400 s to now, 16-byte content intact). The fstat/stat
        // inode re-check there cannot stop it — both calls resolve through the
        // link to the SAME target inode (measured: fstat.ino == stat.ino; only
        // lstat sees the link's own inode).
        //
        // No production exposure, verified read-only on the box: /var/cache/phlix
        // is root:root 0755 and /var/cache/phlix/anidb is phlix:phlix 0755, so
        // the only writers are the service's own uid and root; the
        // sys_get_temp_dir() fallback is unit-private under PrivateTmp=yes. It
        // would matter if PHLIX_ANIDB_CACHE_DIR were ever pointed somewhere
        // world-writable, which it must not be.
        $handle = @fopen($marker, 'x');
        if ($handle !== false) {
            fclose($handle);

            return self::ACQUIRE_OK;
        }

        clearstatcache(true, $marker);

        if (!is_file($marker)) {
            // ┌── SM-0.3a review (fix round), finding 1 ─────────────────────┐
            // │ The create failed, yet the marker is not there. TWO causes   │
            // │ land in this state and a stat CANNOT tell them apart:        │
            // │   (a) the directory is genuinely unusable  -> REFUSED;       │
            // │   (b) ordinary EEXIST contention in which the owner          │
            // │       released its marker (releaseInFlightMarker(), which    │
            // │       only ever runs after a SUCCESSFUL download) in the     │
            // │       microseconds since -> not a refusal at all.            │
            // │ An earlier revision asserted (a) outright. Measured on the   │
            // │ SUCCESS path — 35 processes, hard start barrier, one shared  │
            // │ cache dir, succeeding fetch — that produced a stream of      │
            // │ "REFUSING …" lines naming the wrong cause AND the wrong      │
            // │ remedy, every time the dump actually refreshed. Worse,       │
            // │ reportUnusableCacheDir() latches per process, so the false   │
            // │ positive spent the budget a later GENUINE refusal needed.    │
            // │                                                              │
            // │ Retry the atomic create ONCE: a genuinely unusable directory │
            // │ fails again identically, while a lost release simply wins    │
            // │ the claim.                                                   │
            // │                                                              │
            // │ The retry ALONE is not enough, and only a widened race shows │
            // │ that. Deterministic variant — a 200 ms delay inserted in     │
            // │ exactly this window, so every loser straddles the release —  │
            // │ 35 workers, spurious REFUSED (fetches stayed 1 throughout):  │
            // │                                                              │
            // │     no retry              132  (32-34 of 34, EVERY trial)    │
            // │     retry only              5  (still the same false alarm)  │
            // │     retry + probe           0                                │
            // │                                                              │
            // │ The residual is the same inference one window narrower: the  │
            // │ racers that lose the retry re-create and release the marker  │
            // │ in turn, so "absent -> create fails -> absent" is reachable   │
            // │ in a perfectly healthy directory. No number of retries makes │
            // │ that inference sound. So the last step stops guessing and    │
            // │ ASKS the question the refusal actually asserts — "this       │
            // │ directory will not accept the marker" — with a uniquely      │
            // │ named probe no racer can collide with. See                   │
            // │ {@see cacheDirAcceptsAFile()}.                               │
            // │                                                              │
            // │ Control: the SAME widening with a FAILING fetch (marker      │
            // │ never released) gives 0 in every variant — the delay does    │
            // │ not manufacture refusals, the release does.                  │
            // └──────────────────────────────────────────────────────────────┘
            $handle = @fopen($marker, 'x');

            if ($handle !== false) {
                fclose($handle);

                return self::ACQUIRE_OK;
            }

            clearstatcache(true, $marker);

            if (!is_file($marker)) {
                // Absent, and two atomic creates failed. Either the directory
                // cannot hold the marker, or a third racer is churning it.
                if (!$this->cacheDirAcceptsAFile()) {
                    return self::ACQUIRE_REFUSED;
                }

                // The directory is healthy, so ordinary contention is the
                // expected answer — but it is not the only one that lands here,
                // and the other one never resolves itself. See below.
                $this->reportBlockedMarkerPath($marker);

                return self::ACQUIRE_BUSY;
            }
        }

        return $this->reclaimIfStale($marker);
    }

    /**
     * Can this cache directory hold a file at all?
     *
     * SM-0.3a review (fix round), finding 1. The refusal asserts a property of
     * the DIRECTORY, so test the directory, not the marker: the marker is
     * contended by definition and every inference drawn from its presence or
     * absence is a race. The probe name is unique, so no racer can collide with
     * it and the answer is deterministic.
     *
     * Deliberately an actual create rather than `is_writable()`: writability is
     * a property of the CALLER, not of the directory — root's `is_writable()`
     * returns true for a `0555` directory, which is the same uid-dependent trap
     * the SM-0.3a tests removed in F4. A create answers for the running user
     * and also covers ENOSPC/EDQUOT/EROFS/ENOTDIR, which no permission bit
     * reports.
     *
     * Only ever reached when two atomic marker creates have already failed, so
     * this costs nothing on any healthy path.
     */
    private function cacheDirAcceptsAFile(): bool
    {
        $probe = $this->cacheDir . '/.marker-probe.' . bin2hex(random_bytes(8));

        $handle = @fopen($probe, 'x');

        if ($handle === false) {
            return false;
        }

        fclose($handle);
        @unlink($probe);

        return true;
    }

    /**
     * Tell an operator, once per process, that the marker PATH is blocked by
     * something that is not a regular file.
     *
     * ┌── SM-0.3a review (closing round), finding 2 — DO NOT MAKE THIS SILENT ┐
     * │ When the marker PATH holds a directory, a FIFO, a socket or a symlink │
     * │ then `fopen(..., 'x')` fails, `is_file()` is false (none of those is  │
     * │ a regular file) and cacheDirAcceptsAFile() correctly reports the      │
     * │ DIRECTORY healthy — so the claim resolves to ACQUIRE_BUSY. Forever:   │
     * │ reclaimIfStale() is never reached, so nothing ages the blocker out.   │
     * │ Measured, 5 fresh indexers per state (directory / FIFO / symlink to a │
     * │ directory): every call returned true — "scheduled or already fine" —  │
     * │ while 0 downloads ran and NOT ONE line was logged. The revision       │
     * │ before the fail-closed work at least printed a REFUSING line here; it │
     * │ named the wrong cause, but it was loud. Trading a wrong-but-loud      │
     * │ diagnosis for a silent permanent stall is a bad trade in an estate    │
     * │ that has repeatedly been burned by subsystems that fail quietly.      │
     * │                                                                       │
     * │ This reports and does NOT change the return value: it is not a        │
     * │ functional regression (0 downloads either way, and neither version    │
     * │ self-heals), and nothing in Phlix can create the state — it takes an  │
     * │ operator `mkdir`, a restore artifact or a planted symlink.            │
     * │                                                                       │
     * │ WHY lstat IS THE SOUND TEST. This branch is ALSO the ordinary release │
     * │ race (a racer created and released the marker between the two stats), │
     * │ and that must stay silent — by this estate's own rule, a notice that  │
     * │ fires while everything is working gets ignored, and this one shares   │
     * │ the once-per-process budget a genuine refusal needs. The              │
     * │ discriminator is not a guess: in the race the path is genuinely       │
     * │ ABSENT, so lstat fails, whereas a blocked path is one lstat can see.  │
     * │ filetype() is lstat-based, so a symlink reports "link" rather than    │
     * │ its target's type — exactly the distinction wanted here. A racer that │
     * │ re-created the marker as a regular file in the meantime is contention │
     * │ too, hence the 'file' case. This class only ever puts a REGULAR file  │
     * │ at that path, so no Phlix code path can trip the notice.              │
     * └───────────────────────────────────────────────────────────────────────┘
     *
     * Shares {@see $refusalLogged} with {@see reportUnusableCacheDir()} on
     * purpose: both describe a permanently stalled title dump, an operator
     * needs at most one such line per worker, and on a single cache directory
     * the two states are mutually exclusive — a directory that cannot hold the
     * marker cannot hold a blocker at the marker path either. A second latch
     * would only be a second way to fill the log.
     *
     * @param string $marker Absolute path of the marker that could not be created.
     */
    private function reportBlockedMarkerPath(string $marker): void
    {
        clearstatcache(true, $marker);

        $type = @filetype($marker);

        if ($type === false || $type === 'file') {
            // Genuinely absent (the release race this branch exists for), or a
            // racer re-created it as a regular file. Both are contention.
            return;
        }

        if (self::$refusalLogged) {
            return;
        }

        self::$refusalLogged = true;

        error_log(sprintf(
            'TitleDumpIndexer: STALLED — the AniDB title-dump in-flight marker "%s" is %s, not a '
            . 'regular file, so no worker can ever claim it and the title dump will never update '
            . 'again. The cache directory "%s" itself is healthy, and nothing in Phlix creates this '
            . 'state (it takes an operator mkdir, a restore artifact or a planted symlink). Recover '
            . 'with: rm -rf "%s" — plain `rm` refuses a directory. Logged once per process.',
            $marker,
            $this->describeMarkerType($type, $marker),
            $this->cacheDir,
            $marker,
        ));
    }

    /**
     * Human-readable rendering of an lstat file type, for the notice above.
     *
     * @param string $type   A {@see filetype()} result other than 'file'.
     * @param string $marker Absolute path, used to resolve a symlink's target.
     */
    private function describeMarkerType(string $type, string $marker): string
    {
        if ($type === 'link') {
            $target = @readlink($marker);

            return $target === false
                ? 'a symbolic link'
                : sprintf('a symbolic link to "%s"', $target);
        }

        return match ($type) {
            'dir'    => 'a directory',
            'fifo'   => 'a FIFO (named pipe)',
            'socket' => 'a socket',
            'block'  => 'a block device',
            'char'   => 'a character device',
            default  => 'a ' . $type,
        };
    }

    /**
     * Take over a marker whose owner is gone — atomically.
     *
     * ┌── SM-0.3a review, finding 2 — WHY A LOCK, NOT A `touch()` ───────────┐
     * │ Reclaiming a stale marker is a READ-MODIFY-WRITE: read the mtime,    │
     * │ decide it is stale, then take ownership. The previous revision did   │
     * │ that with a bare `@touch()`, which is not a claim — every racer that │
     * │ read the stale mtime touched it and proceeded. Measured with a hard  │
     * │ start barrier onto one stale marker: worst 8 concurrent downloads    │
     * │ out of 8 processes, and worst 14 out of 35 — PRODUCTION'S FLEET      │
     * │ SIZE. The comment that used to sit here ("at most one extra download │
     * │ per TTL window, never a storm") was simply false.                    │
     * │                                                                      │
     * │ KEEP THE 35-WORKER FIGURE. It is not decoration, it is the number    │
     * │ that picked the mechanism. `rename()` of a uniquely-named temp file  │
     * │ was the other candidate; it was BUILT and measured on the same       │
     * │ barrier, and at 8 workers it scored a flawless 1 in all 10 trials.   │
     * │ At 35 it raced: worst 7. Sampling the race narrower than production  │
     * │ would have vindicated the LOSING design. (Three independent runs of  │
     * │ the pre-fix `@touch()` harness recorded worst 8, 9 and 14 — for a    │
     * │ race the worst you have observed is a lower bound, never a ceiling.) │
     * │                                                                      │
     * │ `rename()` is also unsound in principle here: it makes the *move*    │
     * │ exclusive, but the staleness decision still happens outside any      │
     * │ critical section, so a racer whose rename lands after the winner has │
     * │ re-created the marker steals a LIVE one. It also litters the cache   │
     * │ dir when a process dies between the rename and its cleanup.          │
     * │                                                                      │
     * │ `flock(LOCK_EX|LOCK_NB)` makes the whole check-and-act atomic, needs │
     * │ no temp file, and never blocks. The lock is held only for the        │
     * │ decision — a few microseconds of local-ext4 syscalls, never across   │
     * │ the download itself. Ownership is still carried by the marker's      │
     * │ existence + mtime, so nothing depends on the lock surviving.         │
     * │ Same barrier after this change: exactly 1 downloader in every trial, │
     * │ at 8 workers AND at 35. Removing ONLY these six lines from the       │
     * │ shipping file puts it straight back to 3-8 out of 8.                 │
     * │                                                                      │
     * │ LATENT: on a filesystem with no working `flock()` (NFS, some FUSE    │
     * │ mounts) every reclaim attempt fails, so a stale marker is NEVER      │
     * │ taken over and the dump STALLS PERMANENTLY rather than storming —    │
     * │ measured with a mutant whose `flock()` always returns false:         │
     * │ fetches=0, every attempt BUSY. Degradation, not a storm, and inert   │
     * │ today: /var/cache/phlix/anidb is local ext4 and the                  │
     * │ sys_get_temp_dir() fallback is unit-private under PrivateTmp=true.   │
     * │ It matters the day PHLIX_ANIDB_CACHE_DIR is pointed at a network or  │
     * │ FUSE mount — recover with                                            │
     * │ `rm -rf /var/cache/phlix/anidb/title_index.download.lock`. The `-rf`  │
     * │ is not decoration: the marker path can also be blocked by a          │
     * │ DIRECTORY or a foreign-uid file, and plain `rm` refuses both — see   │
     * │ {@see reportBlockedMarkerPath()}.                                    │
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * @param string $marker Absolute path to the existing marker file.
     *
     * @return self::ACQUIRE_*
     */
    private function reclaimIfStale(string $marker): int
    {
        // 'r+' never creates and never truncates: if the marker vanished
        // between the caller's is_file() and here, this fails rather than
        // silently minting a new one.
        $handle = @fopen($marker, 'r+');

        if ($handle === false) {
            // The marker exists but this process cannot open it (foreign
            // owner — the root-owned-plugin-dir landmine — or a read-only
            // mount). A live one is ordinary contention; a stale one we cannot
            // open is a directory we cannot use, so refuse and log.
            return $this->markerIsLive(@filemtime($marker))
                ? self::ACQUIRE_BUSY
                : self::ACQUIRE_REFUSED;
        }

        try {
            if (!@flock($handle, LOCK_EX | LOCK_NB)) {
                // Another racer is inside the critical section right now. It
                // will either take the marker or find it live; either way this
                // instance does not own the attempt.
                return self::ACQUIRE_BUSY;
            }

            $locked = fstat($handle);
            clearstatcache(true, $marker);
            $onDisk = @stat($marker);

            if ($locked === false || $onDisk === false || $locked['ino'] !== $onDisk['ino']) {
                // The inode we locked is no longer the marker on disk: it was
                // released (successful download) or replaced while we waited.
                // Do not claim a ghost.
                return self::ACQUIRE_BUSY;
            }

            if ($this->markerIsLive($locked['mtime'])) {
                return self::ACQUIRE_BUSY;
            }

            // Stale or future-dated: take ownership by refreshing the mtime
            // INSIDE the critical section, so exactly one racer can win.
            return @touch($marker) ? self::ACQUIRE_OK : self::ACQUIRE_REFUSED;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Whether a marker's mtime means "a download is genuinely in flight".
     *
     * SM-0.3a review, finding 3: a marker whose mtime is in the FUTURE (a
     * backwards NTP step, a restored VM snapshot, a backup restored from a
     * skewed box, a stray `touch -d` — the SM-0.3a runbook hands operators
     * exactly that command for a neighbouring file in this very directory)
     * makes `time() - $mtime` negative, which is `< IN_FLIGHT_TTL_SECONDS`,
     * so the marker would never expire and the downloader would stay wedged
     * for the whole skew. Measured: mtime +7 days → 5 attempts, 0 fetches.
     * A future-dated marker is therefore treated as STALE, which is safe now
     * that the reclaim in {@see reclaimIfStale()} is mutually exclusive: the
     * worst case is one download, not one per racer.
     *
     * @param int|false $mtime Marker mtime, or false when it cannot be read.
     */
    private function markerIsLive(int|false $mtime): bool
    {
        if ($mtime === false) {
            return false; // unknown age → reclaimable (exclusively)
        }

        $now = time();

        return $mtime <= $now && ($now - $mtime) < self::IN_FLIGHT_TTL_SECONDS;
    }

    /**
     * Tell an operator, once per process, why the title dump stopped updating.
     *
     * Once — not once per attempt — because the refusal is re-evaluated on
     * every scheduling attempt and a per-attempt line would just trade a
     * download storm for a log storm.
     */
    private function reportUnusableCacheDir(): void
    {
        if (self::$refusalLogged) {
            return;
        }

        self::$refusalLogged = true;

        error_log(sprintf(
            'TitleDumpIndexer: REFUSING to download the AniDB title dump — the cache directory "%s" '
            . 'will not accept the in-flight marker "%s". A directory that cannot hold the marker '
            . 'cannot hold title_index.json either, so the download would be useless work. '
            . 'Fix the directory (ownership/permissions, free space, PHLIX_ANIDB_CACHE_DIR, or the '
            . 'systemd ReadWritePaths sandbox) to resume title-dump updates. Logged once per process.',
            $this->cacheDir,
            self::IN_FLIGHT_MARKER,
        ));
    }

    /**
     * Drop the in-flight marker so the next legitimately-stale refresh can run.
     *
     * Only ever called after a SUCCESSFUL attempt; a failed one keeps its
     * marker until the TTL expires.
     */
    private function releaseInFlightMarker(): void
    {
        @unlink($this->inFlightMarkerPath());
    }

    /**
     * Path to the cross-process in-flight marker file.
     */
    private function inFlightMarkerPath(): string
    {
        return $this->cacheDir . '/' . self::IN_FLIGHT_MARKER;
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
