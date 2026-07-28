<?php

/**
 * Test double for Workerman\Timer.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Workerman;

/**
 * Stand-in for the real {@see \Workerman\Timer} so the deferral in
 * {@see \Phlix\Anidb\TitleDump\TitleDumpIndexer::downloadAndIndex()} can be
 * observed without a Workerman runtime (this plugin does not, and must not,
 * declare workerman/workerman as a dependency — it couples to the host's copy
 * via `class_exists()`).
 *
 * ⚠ This file declares the REAL FQCN `Workerman\Timer`, so it is deliberately
 * NOT autoloadable (tests/ is PSR-4 mapped to `Phlix\Anidb\Tests\`) and must be
 * `require_once`d only from a process-isolated test. Loading it in the shared
 * PHPUnit process would flip `class_exists(\Workerman\Timer::class)` for every
 * other test in the suite.
 *
 * It models the ONE semantic that matters for SM-0.3a, taken from
 * `workerman/workerman/src/Timer.php`:
 *
 *     public static function add(
 *         float $timeInterval,
 *         callable $func,
 *         ?array $args = [],
 *         bool $persistent = true,      // ← defaults to TRUE
 *     ): int {
 *         return $persistent
 *             ? self::$event->repeat($timeInterval, $func, $args)   // forever
 *             : self::$event->delay($timeInterval, $func, $args);   // once
 *     }
 *
 * so a persistent registration re-invokes its callback ({@see REPEAT_FIRINGS}
 * times here, unbounded in production) while a non-persistent one runs once.
 *
 * @internal Test fixture only.
 */
final class Timer
{
    /**
     * How many times this double re-invokes a PERSISTENT callback.
     *
     * The real repeat() is unbounded; any value > 1 is enough to make a
     * firing-count assertion fail loudly when the 4th argument is dropped.
     */
    public const REPEAT_FIRINGS = 5;

    /**
     * Every add() registration, in order.
     *
     * @var list<array{interval: float, args: array<mixed>, persistent: bool}>
     */
    public static array $registrations = [];

    /**
     * When false, callbacks are queued instead of run, so a test can change the
     * world between "timer armed" and "timer fired".
     */
    public static bool $autoRun = true;

    /**
     * Pending callbacks when {@see $autoRun} is false.
     *
     * @var list<array{callback: callable, args: array<mixed>, persistent: bool}>
     */
    public static array $queued = [];

    private static int $nextId = 0;

    /**
     * Reset all recorded state.
     */
    public static function reset(): void
    {
        self::$registrations = [];
        self::$queued = [];
        self::$autoRun = true;
        self::$nextId = 0;
    }

    /**
     * @param array<mixed>|null $args
     */
    public static function add(
        float $timeInterval,
        callable $func,
        ?array $args = [],
        bool $persistent = true,
    ): int {
        $args ??= [];

        self::$registrations[] = [
            'interval' => $timeInterval,
            'args' => $args,
            'persistent' => $persistent,
        ];

        if (self::$autoRun) {
            self::invoke($func, $args, $persistent);
        } else {
            self::$queued[] = [
                'callback' => $func,
                'args' => $args,
                'persistent' => $persistent,
            ];
        }

        return ++self::$nextId;
    }

    /**
     * Fire everything queued while {@see $autoRun} was false.
     */
    public static function runQueued(): void
    {
        $queued = self::$queued;
        self::$queued = [];

        foreach ($queued as $entry) {
            self::invoke($entry['callback'], $entry['args'], $entry['persistent']);
        }
    }

    /**
     * @param array<mixed> $args
     */
    private static function invoke(callable $func, array $args, bool $persistent): void
    {
        $times = $persistent ? self::REPEAT_FIRINGS : 1;

        for ($i = 0; $i < $times; ++$i) {
            $func(...$args);
        }
    }
}
