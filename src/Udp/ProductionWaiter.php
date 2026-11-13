<?php

/**
 * Production WaiterInterface implementation using blocking usleep.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * Production {@see WaiterInterface} implementation using blocking `usleep`.
 *
 * NOT the default (ANI-S22): the plugin defaults to {@see CooperativeWaiter},
 * which yields to the host event loop. Inject this explicitly only where
 * blocking is acceptable (CLI, or a dedicated background worker that owns the
 * UDP I/O path).
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
