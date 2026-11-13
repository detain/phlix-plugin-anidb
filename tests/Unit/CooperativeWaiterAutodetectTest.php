<?php

/**
 * Coverage for CooperativeWaiter's Revolt / Workerman auto-detect closures (ANI-S22).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\CooperativeWaiter;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures declare the REAL FQCNs (Revolt\EventLoop, Workerman\Timer), so
 * every case runs in its own process: loading them in the shared process would
 * flip class_exists() for the rest of the suite.
 */
final class CooperativeWaiterAutodetectTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Fixture/';

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_revolt_is_detected_and_a_wait_suspends_until_the_loop_delay_fires(): void
    {
        require_once self::FIXTURES . 'RevoltEventLoopSpy.php';
        $waiter = new CooperativeWaiter();

        $done = false;
        $fiber = new \Fiber(function () use ($waiter, &$done): void {
            $this->assertTrue($waiter->canYield());
            $waiter->wait(1.5);
            $done = true;
        });
        $fiber->start();

        $this->assertSame([1.5], \Revolt\EventLoop::$delays);
        $this->assertTrue($fiber->isSuspended(), 'the wait must park the fiber, not sleep');
        $this->assertFalse($done);

        \Revolt\EventLoop::runQueued();
        $this->assertTrue($fiber->isTerminated());
        $this->assertTrue($done);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_workerman_timer_is_detected_and_arms_a_one_shot_timer(): void
    {
        require_once self::FIXTURES . 'WorkermanTimerSpy.php';
        \Workerman\Timer::reset();
        \Workerman\Timer::$autoRun = false;
        $waiter = new CooperativeWaiter();

        $done = false;
        $fiber = new \Fiber(function () use ($waiter, &$done): void {
            $this->assertTrue($waiter->canYield());
            $waiter->wait(2.0);
            $done = true;
        });
        $fiber->start();

        $this->assertCount(1, \Workerman\Timer::$registrations);
        $this->assertSame(2.0, \Workerman\Timer::$registrations[0]['interval']);
        $this->assertFalse(\Workerman\Timer::$registrations[0]['persistent'], 'must be one-shot');
        $this->assertTrue($fiber->isSuspended());
        $this->assertFalse($done);

        \Workerman\Timer::runQueued();
        $this->assertTrue($fiber->isTerminated());
        $this->assertTrue($done);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_revolt_wins_over_workerman_when_both_are_present(): void
    {
        require_once self::FIXTURES . 'RevoltEventLoopSpy.php';
        require_once self::FIXTURES . 'WorkermanTimerSpy.php';
        \Workerman\Timer::reset();
        $waiter = new CooperativeWaiter();

        $fiber = new \Fiber(static function () use ($waiter): void {
            $waiter->wait(1.0);
        });
        $fiber->start();

        $this->assertSame([1.0], \Revolt\EventLoop::$delays);
        $this->assertSame([], \Workerman\Timer::$registrations);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_workerman_closure_outside_a_fiber_is_a_no_op(): void
    {
        require_once self::FIXTURES . 'WorkermanTimerSpy.php';
        \Workerman\Timer::reset();
        $waiter = new CooperativeWaiter();

        $detect = new \ReflectionMethod(CooperativeWaiter::class, 'detectSuspend');
        $closure = $detect->invoke(null);
        $this->assertInstanceOf(\Closure::class, $closure);
        $closure(1.0);

        $this->assertSame([], \Workerman\Timer::$registrations, 'no Fiber means nothing to resume, so no timer');
        $this->assertFalse($waiter->canYield());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_no_loop_present_means_blocking_fallback(): void
    {
        $this->assertFalse(class_exists(\Revolt\EventLoop::class, false));
        $this->assertFalse(class_exists(\Workerman\Timer::class, false));

        $waiter = new CooperativeWaiter();
        $result = null;
        $fiber = new \Fiber(function () use ($waiter, &$result): void {
            $result = $waiter->canYield();
            $waiter->wait(0.01);
        });
        $fiber->start();

        $this->assertFalse($result, 'inside a Fiber but with no loop, still not able to yield');
        $this->assertTrue($fiber->isTerminated(), 'the bounded usleep fallback returns');
    }
}
