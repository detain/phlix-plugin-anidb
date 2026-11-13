<?php

/**
 * flock()-based {@see UdpCoordinatorInterface}.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * File-lock owner election and shared pacing ledger.
 *
 * - Ownership: a non-blocking exclusive flock on `anidb-udp-owner.lock`, held
 *   for the life of the owning process. The kernel releases it when the owner
 *   dies, so a surviving worker takes over on its next attempt.
 * - Ledger: `anidb-udp-ledger` holds the wall-clock time of the last reserved
 *   slot. Reservation runs under an exclusive flock so concurrent processes
 *   serialise: each gets `max(now, last + interval)` and writes it back.
 *
 * The directory must be writable by the plugin and not world-writable (the
 * provider passes its validated cache dir).
 */
final class FileUdpCoordinator implements UdpCoordinatorInterface
{
    private const OWNER_FILE = 'anidb-udp-owner.lock';
    private const LEDGER_FILE = 'anidb-udp-ledger';

    /** @var resource|null */
    private $ownerHandle = null;

    private bool $degradedLogged = false;

    /** True once coordination proved impossible; this process then runs standalone (acts as owner). */
    private bool $degraded = false;

    /** @var callable(): float */
    private $clock;

    /**
     * @param string               $dir   Existing writable directory for the lock + ledger.
     * @param (callable(): float)|null $clock Wall-clock seam (defaults to microtime(true)).
     */
    public function __construct(private readonly string $dir, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function tryBecomeOwner(): bool
    {
        if ($this->ownerHandle !== null) {
            return true;
        }

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }

        $handle = @fopen($this->dir . '/' . self::OWNER_FILE, 'c');
        if ($handle === false) {
            // Coordination is impossible (unwritable dir). Degrade to the
            // pre-ANI-S23 standalone behaviour rather than disable AniDB for
            // every worker; log once per process.
            $this->degraded = true;
            if (!$this->degradedLogged) {
                $this->degradedLogged = true;
                error_log('AniDB UDP: owner lock unavailable in "' . $this->dir
                    . '"; running without cross-process single-owner election.');
            }

            return true;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->ownerHandle = $handle;

        return true;
    }

    public function isOwner(): bool
    {
        return $this->ownerHandle !== null || $this->degraded;
    }

    public function reserveSendSlot(float $intervalSec): float
    {
        $handle = @fopen($this->dir . '/' . self::LEDGER_FILE, 'c+');
        if ($handle === false) {
            // Cannot read the shared ledger: pay the full interval rather
            // than risk a burst.
            return $intervalSec;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return $intervalSec;
            }

            $raw = stream_get_contents($handle);
            $last = is_string($raw) && is_numeric(trim($raw)) ? (float) trim($raw) : 0.0;

            $now = ($this->clock)();
            // A ledger stamped in the future beyond one interval is corrupt
            // (clock step) — never wait longer than one interval for it.
            if ($last > $now + $intervalSec) {
                $last = $now;
            }
            $slot = max($now, $last + $intervalSec);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, sprintf('%.6F', $slot));
            fflush($handle);
            flock($handle, LOCK_UN);

            return max(0.0, $slot - $now);
        } finally {
            fclose($handle);
        }
    }

    public function release(): void
    {
        if ($this->ownerHandle === null) {
            return;
        }
        flock($this->ownerHandle, LOCK_UN);
        fclose($this->ownerHandle);
        $this->ownerHandle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
