<?php

/**
 * Test double for Revolt\EventLoop.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Revolt\EventLoop;

/**
 * Minimal suspension double: records resume(), suspends the current Fiber
 * exactly like Revolt's Fiber-backed suspension until the loop resumes it.
 *
 * @internal Test fixture only.
 */
final class Suspension
{
    public bool $resumed = false;

    private ?\Fiber $fiber = null;

    public function suspend(): void
    {
        if ($this->resumed) {
            return; // resumed before suspend: returns immediately, like Revolt
        }
        $this->fiber = \Fiber::getCurrent();
        \Fiber::suspend();
    }

    public function resume(): void
    {
        $this->resumed = true;
        if ($this->fiber !== null && $this->fiber->isSuspended()) {
            $this->fiber->resume();
        }
    }
}

namespace Revolt;

/**
 * Stand-in for the real {@see \Revolt\EventLoop}.
 *
 * ⚠ Declares the REAL FQCN, so it is NOT autoloadable and must be
 * `require_once`d only from a process-isolated test (see
 * tests/Fixture/WorkermanTimerSpy.php for the same rule).
 *
 * @internal Test fixture only.
 */
final class EventLoop
{
    /** @var list<float> Every delay() interval, in order. */
    public static array $delays = [];

    /** @var list<callable> Callbacks queued by delay(); fire with {@see runQueued()}. */
    public static array $queued = [];

    public static function getSuspension(): EventLoop\Suspension
    {
        return new EventLoop\Suspension();
    }

    public static function delay(float $delay, callable $callback): string
    {
        self::$delays[] = $delay;
        self::$queued[] = $callback;

        return 'id' . count(self::$delays);
    }

    public static function runQueued(): void
    {
        $queued = self::$queued;
        self::$queued = [];
        foreach ($queued as $cb) {
            $cb();
        }
    }
}
