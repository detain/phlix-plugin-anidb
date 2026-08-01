<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\ProductionWaiter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProductionWaiter.
 *
 * Tests the blocking usleep-based implementation of WaiterInterface.
 */
final class ProductionWaiterTest extends TestCase
{
    private ProductionWaiter $waiter;

    protected function setUp(): void
    {
        $this->waiter = new ProductionWaiter();
    }

    public function test_wait_does_not_throw_for_valid_duration(): void
    {
        // A short wait should complete without error
        $start = microtime(true);
        $this->waiter->wait(0.001); // 1ms
        $elapsed = microtime(true) - $start;

        // Should have waited at least close to the requested time
        $this->assertGreaterThanOrEqual(0.0001, $elapsed);
    }

    public function test_wait_returns_early_for_zero(): void
    {
        // Zero duration should return immediately
        $start = microtime(true);
        $this->waiter->wait(0);
        $elapsed = microtime(true) - $start;

        // Should be essentially instantaneous (less than 1ms)
        $this->assertLessThan(0.001, $elapsed);
    }

    public function test_wait_returns_early_for_negative(): void
    {
        // Negative duration should return immediately
        $start = microtime(true);
        $this->waiter->wait(-1.0);
        $elapsed = microtime(true) - $start;

        // Should be essentially instantaneous
        $this->assertLessThan(0.001, $elapsed);
    }

    public function test_wait_caps_at_five_seconds(): void
    {
        // A very large duration should be capped
        $start = microtime(true);
        $this->waiter->wait(100.0); // 100 seconds requested
        $elapsed = microtime(true) - $start;

        // Should not have waited 100 seconds - should be capped at ~5 seconds
        $this->assertLessThan(10, $elapsed, 'wait() did not cap at 5 seconds');
    }

    public function test_wait_handles_fractional_seconds(): void
    {
        // Test with 0.5 seconds
        $start = microtime(true);
        $this->waiter->wait(0.05); // 50ms for faster test
        $elapsed = microtime(true) - $start;

        // Should have waited at least close to requested time
        $this->assertGreaterThanOrEqual(0.01, $elapsed);
        // But less than 1 second for a 50ms wait
        $this->assertLessThan(1, $elapsed);
    }
}
