<?php

/**
 * Cross-process coordination seam for the AniDB UDP socket (ANI-S23).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * Single-UDP-owner election plus a host-wide pacing ledger.
 *
 * AniDB counts flood limits and source ports per client IP, but a resident
 * host runs many workers, each with its own {@see UdpClient}. Exactly one
 * process may own the socket (one stable source port, one session); every
 * packet that owner emits is additionally paced against a ledger shared by
 * all processes, so an owner hand-over cannot burst either.
 */
interface UdpCoordinatorInterface
{
    /**
     * Try to become (or remain) the single socket owner. Never blocks.
     *
     * @return bool True when this process now owns the UDP socket.
     */
    public function tryBecomeOwner(): bool;

    /**
     * Whether this process currently holds ownership.
     */
    public function isOwner(): bool;

    /**
     * Atomically reserve the next send slot in the shared ledger.
     *
     * @param float $intervalSec Minimum spacing between any two packets host-wide.
     *
     * @return float Seconds the caller must wait before sending (0.0 = now).
     */
    public function reserveSendSlot(float $intervalSec): float;

    /**
     * Give up ownership (idempotent).
     */
    public function release(): void;
}
