<?php

/**
 * Waiter seam that can report whether a wait will yield to the event loop.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * A {@see WaiterInterface} that can tell its caller whether `wait()` will
 * genuinely yield to the host event loop (suspend the current Fiber) or fall
 * back to parking the process.
 *
 * Transports use this to pick their I/O strategy: when {@see canYield()} is
 * true they poll non-blockingly and yield between polls (the worker keeps
 * serving other requests); when false they use the cheaper blocking
 * `socket_select()` wait, because polling without yielding would only
 * busy-spin.
 *
 * @package Phlix\Anidb\Udp
 * @since 0.5.0
 */
interface CooperativeWaiterInterface extends WaiterInterface
{
    /**
     * Whether a call to {@see wait()} right now suspends the current Fiber and
     * returns control to the event loop (true), or blocks the process (false).
     */
    public function canYield(): bool;
}
