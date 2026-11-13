<?php

/**
 * Async-native default WaiterInterface: yields to the host event loop.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * The plugin's DEFAULT waiter (ANI-S22, owner ruling: async-native default).
 *
 * In a resident Fiber-based host (Workerman 5 with the Revolt/Fiber event loop,
 * or any Revolt host) a wait suspends the current Fiber and schedules its
 * resumption on the event loop, so the worker keeps serving other requests
 * during AniDB's flood-protection delay and during UDP reply polling. The
 * event-loop primitive is discovered, never declared as a dependency:
 *
 *  1. `Revolt\EventLoop` (suspension + delay) when present;
 *  2. otherwise `Workerman\Timer::add($s, $resume, [], false)` (one-shot — the
 *     4th argument defaults to persistent=true) with a raw Fiber suspend.
 *
 * Outside a Fiber (CLI, tests, a dedicated blocking worker) there is no loop
 * to yield to; the wait then degrades to a bounded `usleep` and
 * {@see canYield()} reports false so transports pick their blocking-select
 * path rather than polling. A host may inject its own suspend closure.
 *
 * @package Phlix\Anidb\Udp
 * @since 0.5.0
 */
final class CooperativeWaiter implements CooperativeWaiterInterface
{
    /** Cap on one wait, guarding against pathological elapsed-time maths. */
    private const MAX_WAIT_SECONDS = 5.0;

    /** @var \Closure(float): void|null Suspends the current Fiber for N seconds. */
    private ?\Closure $suspend;

    /**
     * @param (\Closure(float): void)|null $suspend Host-supplied "suspend the
     *     current Fiber for N seconds via the event loop" closure. Null
     *     auto-detects Revolt, then Workerman\Timer; none found means blocking
     *     fallback.
     */
    public function __construct(?\Closure $suspend = null)
    {
        $this->suspend = $suspend ?? self::detectSuspend();
    }

    /**
     * {@inheritDoc}
     */
    public function canYield(): bool
    {
        return $this->suspend !== null && \Fiber::getCurrent() !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function wait(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        $seconds = min($seconds, self::MAX_WAIT_SECONDS);

        if ($this->suspend !== null && \Fiber::getCurrent() !== null) {
            ($this->suspend)($seconds);
            return;
        }

        // No event loop reachable from this call stack: bounded blocking wait.
        usleep((int) ($seconds * 1_000_000));
    }

    /**
     * Discover the host's event-loop suspension primitive.
     *
     * @return (\Closure(float): void)|null
     */
    private static function detectSuspend(): ?\Closure
    {
        if (class_exists(\Revolt\EventLoop::class)) {
            return static function (float $seconds): void {
                $suspension = \Revolt\EventLoop::getSuspension();
                \Revolt\EventLoop::delay($seconds, static function () use ($suspension): void {
                    $suspension->resume();
                });
                $suspension->suspend();
            };
        }

        if (class_exists(\Workerman\Timer::class)) {
            return static function (float $seconds): void {
                $fiber = \Fiber::getCurrent();
                if ($fiber === null) {
                    return;
                }
                // persistent=false: one-shot (Timer::add defaults to repeating).
                \Workerman\Timer::add($seconds, static function () use ($fiber): void {
                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                    }
                }, [], false);
                \Fiber::suspend();
            };
        }

        return null;
    }
}
