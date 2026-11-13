<?php

/**
 * Seam tests for the async-native default waiter and transport (ANI-S22).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use Phlix\Anidb\Udp\CooperativeWaiter;
use Phlix\Anidb\Udp\CooperativeWaiterInterface;
use Phlix\Anidb\Udp\SocketUdpClient;
use Phlix\Anidb\Udp\UdpClient;
use PHPUnit\Framework\TestCase;

/**
 * Proves the default path yields to the event loop and never busy-waits.
 */
final class CooperativeWaiterTest extends TestCase
{
    public function test_wait_inside_fiber_suspends_via_the_loop_and_does_not_usleep(): void
    {
        $calls = [];
        $waiter = new CooperativeWaiter(function (float $s) use (&$calls): void {
            $calls[] = $s;
        });

        $elapsed = null;
        $fiber = new \Fiber(function () use ($waiter, &$elapsed): void {
            $this->assertTrue($waiter->canYield());
            $t = microtime(true);
            $waiter->wait(2.0);
            $elapsed = microtime(true) - $t;
        });
        $fiber->start();

        $this->assertSame([2.0], $calls);
        $this->assertLessThan(0.5, $elapsed, 'a 2 s wait must be delegated to the loop, not slept');
    }

    public function test_outside_a_fiber_it_reports_blocking_and_falls_back_to_bounded_sleep(): void
    {
        $called = false;
        $waiter = new CooperativeWaiter(function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($waiter->canYield());
        $t = microtime(true);
        $waiter->wait(0.01);
        $this->assertFalse($called);
        $this->assertGreaterThanOrEqual(0.005, microtime(true) - $t);
    }

    public function test_non_positive_wait_is_a_no_op(): void
    {
        $called = false;
        $waiter = new CooperativeWaiter(function () use (&$called): void {
            $called = true;
        });
        $fiber = new \Fiber(function () use ($waiter): void {
            $waiter->wait(0);
            $waiter->wait(-1);
        });
        $fiber->start();
        $this->assertFalse($called);
    }

    public function test_defaults_are_cooperative(): void
    {
        $provider = new AnidbMetadataProvider(['username' => 'u', 'api_key' => 'k'], null, null, null);
        $w = (new \ReflectionProperty($provider, 'waiter'))->getValue($provider);
        $this->assertInstanceOf(CooperativeWaiter::class, $w);

        $sock = new SocketUdpClient();
        $sw = (new \ReflectionProperty($sock, 'waiter'))->getValue($sock);
        $this->assertInstanceOf(CooperativeWaiterInterface::class, $sw);

        $udp = new UdpClient(['username' => 'u', 'api_key' => 'k'], $sock);
        $uw = (new \ReflectionProperty($udp, 'waiter'))->getValue($udp);
        $this->assertInstanceOf(CooperativeWaiter::class, $uw);
    }

    public function test_silent_peer_polls_non_blockingly_and_yields_every_empty_poll(): void
    {
        // A local "AniDB" that never answers: the transport must yield to the
        // waiter between polls instead of parking in one blocking select.
        $peer = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($peer, '127.0.0.1', 0);
        socket_getsockname($peer, $addr, $port);

        $yields = 0;
        $coop = new CooperativeWaiter(function (float $s) use (&$yields): void {
            $yields++;
            \Fiber::suspend(); // control returns to the "event loop" (this test)
        });

        $client = new SocketUdpClient('127.0.0.1', $port, 39001, $coop);
        $client->open();

        $result = 'unset';
        $fiber = new \Fiber(function () use ($client, &$result): void {
            $result = $client->send('PING');
        });

        // Drive the fiber a few polls while the peer stays silent.
        $fiber->start();
        $guard = 0;
        while (!$fiber->isTerminated() && $guard++ < 3) {
            $fiber->resume();
        }
        $this->assertGreaterThanOrEqual(3, $yields, 'empty polls must yield to the loop');
        $this->assertFalse($fiber->isTerminated(), 'still waiting, not blocked inside one 10 s select');

        // Now answer so the exchange completes.
        $buf = '';
        socket_recvfrom($peer, $buf, 64, MSG_DONTWAIT, $from, $fromPort);
        socket_sendto($peer, "300 PONG\n", 9, 0, $from, $fromPort);
        $guard = 0;
        while (!$fiber->isTerminated() && $guard++ < 50) {
            $fiber->resume();
        }
        $client->close();
        socket_close($peer);

        $this->assertTrue($fiber->isTerminated());
        $this->assertIsString($result);
        $this->assertStringContainsString('300', $result);
    }
}
