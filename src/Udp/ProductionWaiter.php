<?php

declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * Production {@see WaiterInterface} implementation using blocking `usleep`.
 *
 * This is the default implementation for contexts where blocking is acceptable
 * (e.g. CLI, or a dedicated background worker that owns the UDP I/O path).
 *
 * When the plugin is loaded in a Workerman resident-worker context, callers
 * should use a Workerman\Timer-based implementation instead to avoid blocking
 * the event loop. The {@see WaiterInterface} seam makes this swap-in possible
 * without changing the call-site.
 *
 * @package Phlix\Anidb\Udp
 * @since 0.4.0
 */
final class ProductionWaiter implements WaiterInterface
{
    /**
     * {@inheritDoc}
     *
     * Uses blocking `usleep`. The duration is capped at 5 seconds to prevent
     * an accidentally pathological elapsed-time miscalculation from blocking
     * indefinitely.
     */
    public function wait(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        // Guard against pathological values (e.g. negative due to clock skew)
        $seconds = min($seconds, 5.0);

        usleep((int)($seconds * 1_000_000));
    }
}
