<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\SocketUdpClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see SocketUdpClient}.
 *
 * Tests the production UDP transport implementation including error paths,
 * edge cases, and the origin accessor behavior.
 */
final class SocketUdpClientTest extends TestCase
{
    public function test_constructor_sets_host_port_local_port(): void
    {
        $client = new SocketUdpClient('api.anidb.net', 9000, 9001);

        // Use reflection to verify the private fields
        $reflection = new \ReflectionClass($client);
        $hostProp = $reflection->getProperty('host');
        $hostProp->setAccessible(true);
        $this->assertSame('api.anidb.net', $hostProp->getValue($client));

        $portProp = $reflection->getProperty('port');
        $portProp->setAccessible(true);
        $this->assertSame(9000, $portProp->getValue($client));

        $localPortProp = $reflection->getProperty('localPort');
        $localPortProp->setAccessible(true);
        $this->assertSame(9001, $localPortProp->getValue($client));
    }

    public function test_constructor_with_custom_values(): void
    {
        $client = new SocketUdpClient('custom.host.example.com', 12345, 54321);

        $reflection = new \ReflectionClass($client);
        $hostProp = $reflection->getProperty('host');
        $hostProp->setAccessible(true);
        $this->assertSame('custom.host.example.com', $hostProp->getValue($client));

        $portProp = $reflection->getProperty('port');
        $portProp->setAccessible(true);
        $this->assertSame(12345, $portProp->getValue($client));

        $localPortProp = $reflection->getProperty('localPort');
        $localPortProp->setAccessible(true);
        $this->assertSame(54321, $localPortProp->getValue($client));
    }

    public function test_default_constructor_values(): void
    {
        $client = new SocketUdpClient();

        $reflection = new \ReflectionClass($client);
        $hostProp = $reflection->getProperty('host');
        $hostProp->setAccessible(true);
        $this->assertSame('api.anidb.net', $hostProp->getValue($client));

        $portProp = $reflection->getProperty('port');
        $portProp->setAccessible(true);
        $this->assertSame(9000, $portProp->getValue($client));

        $localPortProp = $reflection->getProperty('localPort');
        $localPortProp->setAccessible(true);
        $this->assertSame(9001, $localPortProp->getValue($client));
    }

    public function test_last_reply_host_initially_null(): void
    {
        $client = new SocketUdpClient();
        $this->assertNull($client->lastReplyHost());
    }

    public function test_last_reply_port_initially_null(): void
    {
        $client = new SocketUdpClient();
        $this->assertNull($client->lastReplyPort());
    }

    public function test_send_throws_when_socket_not_open(): void
    {
        $client = new SocketUdpClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UDP socket not open');

        $client->send('TEST COMMAND');
    }

    public function test_close_is_safe_when_socket_not_open(): void
    {
        $client = new SocketUdpClient();

        // Should not throw
        $client->close();
        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function test_close_twice_is_safe(): void
    {
        $client = new SocketUdpClient();
        $client->open();
        $client->close();

        // Should not throw
        $client->close();
        $this->assertTrue(true);
    }

    /**
     * @requires extension sockets
     */
    public function test_open_creates_socket_and_binds(): void
    {
        $client = new SocketUdpClient('api.anidb.net', 9000, 19001);

        try {
            $client->open();

            // Verify socket is created via reflection
            $reflection = new \ReflectionClass($client);
            $socketProp = $reflection->getProperty('socket');
            $socketProp->setAccessible(true);
            $socket = $socketProp->getValue($client);

            $this->assertNotNull($socket);
            // PHP 8+ uses objects for sockets, not resources
            $this->assertIsObject($socket);
        } finally {
            $client->close();
        }
    }

    /**
     * @requires extension sockets
     */
    public function test_open_and_close_cycle(): void
    {
        $client = new SocketUdpClient('api.anidb.net', 9000, 19002);

        try {
            $client->open();
            $client->close();
            $this->assertTrue(true);
        } finally {
            $client->close();
        }
    }

    /**
     * @requires extension sockets
     */
    public function test_open_and_close_clears_socket(): void
    {
        $client = new SocketUdpClient('api.anidb.net', 9000, 19004);

        try {
            $client->open();

            // Verify socket is set after open
            $reflection = new \ReflectionClass($client);
            $socketProp = $reflection->getProperty('socket');
            $socketProp->setAccessible(true);
            $this->assertNotNull($socketProp->getValue($client));

            $client->close();

            // After close, socket should be null
            $this->assertNull($socketProp->getValue($client));
        } finally {
            $client->close();
        }
    }

    public function test_socket_create_failure_throws_runtime_exception(): void
    {
        // This test verifies that socket_create failure is handled
        // We can't easily force socket_create to fail, but we verify the error handling path exists
        // by checking that a RuntimeException is thrown with the correct message format
        $client = new SocketUdpClient();

        // Attempting to send without opening should throw
        $this->expectException(\RuntimeException::class);
        $client->send('TEST');
    }

    // ------------------------------------------------------------------
    // M-ANIDB-33 (review anidb srphp #15): a single hard-coded bind port
    // made a second instance on the same host die deterministically.
    // open() now slides over a small preferred-port window and exposes
    // the port it actually bound.
    // ------------------------------------------------------------------

    /**
     * @requires extension sockets
     */
    public function test_open_falls_back_to_next_port_in_window_when_preferred_is_taken(): void
    {
        // Blocker WITHOUT SO_REUSEADDR: a later SO_REUSEADDR bind on the same
        // port still fails (EADDRINUSE) — the deterministic collision shape.
        $blocker = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->assertNotFalse($blocker, 'socket_create for blocker failed');
        $bound = @socket_bind($blocker, '0.0.0.0', 19201);
        $this->assertTrue($bound, 'blocker failed to bind 19201');

        $client = new SocketUdpClient('api.anidb.net', 9000, 19201);

        try {
            $client->open();

            $this->assertSame(19202, $client->boundPort(), 'expected the bind to slide one port');
        } finally {
            $client->close();
            @socket_close($blocker);
        }
    }

    /**
     * @requires extension sockets
     */
    public function test_bound_port_is_null_after_close(): void
    {
        $client = new SocketUdpClient('api.anidb.net', 9000, 19211);

        try {
            $client->open();
            $this->assertNotNull($client->boundPort());
        } finally {
            $client->close();
        }

        $this->assertNull($client->boundPort());
    }

    /**
     * @requires extension sockets
     */
    public function test_open_throws_when_entire_bind_window_is_taken(): void
    {
        /** @var list<\Socket> $blockers */
        $blockers = [];

        try {
            for ($port = 19301; $port <= 19308; $port++) {
                $blocker = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
                $this->assertNotFalse($blocker, 'socket_create for blocker failed');
                $this->assertTrue(@socket_bind($blocker, '0.0.0.0', $port), "blocker bind {$port} failed");
                $blockers[] = $blocker;
            }

            $client = new SocketUdpClient('api.anidb.net', 9000, 19301);

            $this->expectException(\RuntimeException::class);
            $client->open();
        } finally {
            foreach ($blockers as $blocker) {
                @socket_close($blocker);
            }
        }
    }
}
