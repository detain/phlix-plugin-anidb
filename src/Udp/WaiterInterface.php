<?php

declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * Abstraction for sleep/wait operations.
 *
 * Allows {@see AnidbMetadataProvider} and {@see SocketUdpClient} to enforce
 * flood-protection delays without blocking the request worker when a non-blocking
 * implementation (e.g. Workerman Timer, background task) is available in the host.
 *
 * The production implementation ({@see ProductionWaiter}) uses blocking `usleep`
 * and is suitable for contexts where blocking is acceptable (e.g. CLI, worker
 * processes that are dedicated to UDP I/O). The seam lets tests use a no-op
 * waiter so flood-interval logic can be exercised without real wall-clock delays.
 *
 * @package Phlix\Anidb\Udp
 * @since 0.4.0
 */
interface WaiterInterface
{
    /**
     * Wait/sleep for the given number of seconds.
     *
     * @param float $seconds Duration to wait (can be fractional for sub-second precision).
     *
     * @return void
     */
    public function wait(float $seconds): void;
}
