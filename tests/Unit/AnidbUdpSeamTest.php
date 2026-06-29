<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Drives the AUTH / command path of {@see UdpClient} through a FAKE transport
 * that returns canned responses and records what was sent — no network, no real
 * socket.
 *
 * Tests the UdpClient session/command logic (AUTH, sendCommand with session
 * key append, 506 retry, flood protection) in isolation from the god-class
 * {@see \Phlix\Anidb\AnidbMetadataProvider}.
 */
final class AnidbUdpSeamTest extends TestCase
{
    /**
     * Build a UdpClient wired to the supplied fake transport and waiter.
     */
    private function makeUdpClient(UdpClientInterface $udp, WaiterInterface $waiter): UdpClient
    {
        return new UdpClient(
            [
                'username' => 'testuser',
                'api_key'  => 'testkey',
            ],
            $udp,
            $waiter,
        );
    }

    public function test_authenticate_succeeds_and_sets_session_key(): void
    {
        $udp = new FakeUdpClient(['200 sEsSiOnKeY LOGIN ACCEPTED']);
        $waiter = new NoOpWaiter();

        $client = $this->makeUdpClient($udp, $waiter);
        $client->authenticate();

        $this->assertSame('sEsSiOnKeY', $client->getSessionKey());
        $this->assertTrue($client->isAuthenticated());

        // AUTH command sent (no session key on first send).
        $this->assertCount(1, $udp->sent);
        $this->assertStringStartsWith('AUTH user=testuser&pass=testkey', $udp->sent[0]);
        // authenticate() drives the transport directly; open() is separate.
        $this->assertSame(0, $udp->openCount);
    }

    /**
     * B1 friendly-throw path: a 5xx AUTH reply throws the friendly,
     * code-specific \RuntimeException returned by parseAuthFailure().
     *
     * @dataProvider authFailureProvider
     */
    public function test_authenticate_throws_friendly_runtime_exception_on_failure_response(
        string $cannedResponse,
        string $expectedMessageFragment
    ): void {
        $udp = new FakeUdpClient([$cannedResponse]);
        $waiter = new NoOpWaiter();
        $client = $this->makeUdpClient($udp, $waiter);

        $thrown = null;
        try {
            $client->authenticate();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertNotInstanceOf(\Error::class, $thrown);
        $this->assertStringContainsString($expectedMessageFragment, $thrown->getMessage());

        // No session key should be set on a failed AUTH.
        $this->assertNull($client->getSessionKey());
        $this->assertFalse($client->isAuthenticated());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authFailureProvider(): array
    {
        return [
            '500 invalid credentials' => ['500 LOGIN FAILED', 'Invalid username or API password'],
            '503 outdated client'     => ['503 CLIENT VERSION OUTDATED', 'Client version outdated'],
            '504 client banned'       => ['504 CLIENT BANNED - flooding', 'Client banned'],
            '555 banned'              => ['555 BANNED - too many failed logins', 'Banned'],
            'unknown code raw'        => ['600 INTERNAL SERVER ERROR', 'AniDB AUTH failed: 600 INTERNAL SERVER ERROR'],
        ];
    }

    public function test_authenticate_throws_on_no_response_from_transport(): void
    {
        // A null reply (timeout) must surface the explicit "no response" message.
        $udp = new FakeUdpClient([null]);
        $waiter = new NoOpWaiter();
        $client = $this->makeUdpClient($udp, $waiter);

        $thrown = null;
        try {
            $client->authenticate();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertStringContainsString('no response', $thrown->getMessage());
    }

    public function test_send_command_appends_session_key_to_command(): void
    {
        // sendCommand() with null sessionKey does lazy AUTH first, then sends
        // the actual command.
        $udp = new FakeUdpClient([
            '200 SESSION_KEY LOGIN ACCEPTED',  // AUTH response
            "230 ANIME\n1|data",               // ANIME response
        ]);
        $waiter = new NoOpWaiter();

        $client = $this->makeUdpClient($udp, $waiter);
        $result = $client->sendCommand('ANIME aid=1');

        $this->assertSame("230 ANIME\n1|data", $result);
        // Two sends: AUTH (no session key) then ANIME (with session key).
        $this->assertCount(2, $udp->sent);
        $this->assertStringStartsWith('AUTH user=testuser', $udp->sent[0]);
        $this->assertStringContainsString('&s=SESSION_KEY', $udp->sent[1]);
    }

    public function test_send_command_with_already_authenticated_client(): void
    {
        // Pre-authenticate, then call sendCommand - no AUTH should be sent.
        $udp = new FakeUdpClient([
            '200 SESSION_KEY LOGIN ACCEPTED',  // AUTH response (not consumed)
            "230 ANIME\n1|data",               // ANIME response
        ]);
        $waiter = new NoOpWaiter();

        $client = $this->makeUdpClient($udp, $waiter);
        $client->authenticate(); // sessionKey is now set

        // Clear sent array to isolate sendCommand's sends.
        $udp->sent = [];

        $result = $client->sendCommand('ANIME aid=1');

        $this->assertSame("230 ANIME\n1|data", $result);
        // Only one send: ANIME with session key (no AUTH since already authenticated).
        $this->assertCount(1, $udp->sent);
        $this->assertStringContainsString('&s=SESSION_KEY', $udp->sent[0]);
    }

    public function test_logout_clears_session(): void
    {
        $udp = new FakeUdpClient([
            '200 SESSION_KEY LOGIN ACCEPTED',
            '200 LOGOUT ACCEPTED',
        ]);
        $waiter = new NoOpWaiter();

        $client = $this->makeUdpClient($udp, $waiter);
        $client->authenticate();
        $this->assertTrue($client->isAuthenticated());

        $client->logout();

        $this->assertFalse($client->isAuthenticated());
        $this->assertNull($client->getSessionKey());
    }

    /**
     * S1b flood protection: verify enforceFloodProtection is called and waiter
     * receives the correct computed wait time based on elapsed time since last send.
     *
     * @dataProvider floodWaitTimeProvider
     */
    public function test_flood_protection_calls_waiter_with_correct_time(
        float $elapsedSinceLastSend,
        ?float $expectedWaitTime,
    ): void {
        $udp = new FakeUdpClient([
            '200 SESSION_KEY LOGIN ACCEPTED', // AUTH response
            "230 ANIME\n1|data",              // First ANIME response
            "230 ANIME\n2|data",              // Second ANIME response
        ]);
        $waiter = new NoOpWaiter();

        $client = $this->makeUdpClient($udp, $waiter);

        // First command: establishes baseline and triggers first wait.
        $client->sendCommand('ANIME aid=1');

        // Simulate elapsed time since last send by directly setting lastSendTimestamp.
        $reflection = new \ReflectionClass($client);
        $lastSendProp = $reflection->getProperty('lastSendTimestamp');
        $lastSendProp->setAccessible(true);
        $now = microtime(true);
        $lastSendProp->setValue($client, $now - $elapsedSinceLastSend);

        // Second command triggers enforceFloodProtection() before sending.
        $client->sendCommand('ANIME aid=2');

        // First command always triggers a wait (initial flood protection).
        // Second command's wait depends on elapsed time.
        if ($expectedWaitTime === null) {
            // Only the first command's wait should be recorded.
            $this->assertCount(1, $waiter->waits);
        } else {
            // Two waits: first command + second command's flood protection.
            $this->assertCount(2, $waiter->waits);
            $this->assertEqualsWithDelta($expectedWaitTime, $waiter->waits[1], 0.001);
        }
    }

    /**
     * @return array<string, array{float, float|null}>
     */
    public static function floodWaitTimeProvider(): array
    {
        // Format: [elapsedSinceLastSend, expectedWaitTime|null]
        // null means no additional wait (waitTime <= 0)
        // FLOOD_PROTECTION_INTERVAL_SEC = 4.0
        return [
            // No wait needed if enough time has passed
            'elapsed >= interval: no additional wait' => [5.0, null],
            // Exactly at interval: no wait
            'elapsed == interval: no additional wait' => [4.0, null],
            // Under interval: wait the difference
            'elapsed 2s under: wait 2s' => [2.0, 2.0],
            'elapsed 1s under: wait 3s' => [1.0, 3.0],
            'elapsed 0.5s under: wait 3.5s' => [0.5, 3.5],
            // Fresh send (0 elapsed): wait full interval
            'no elapsed time: wait full 4s' => [0.0, 4.0],
        ];
    }
}

/**
 * In-memory {@see UdpClientInterface} that pops canned responses in order and
 * records each sent payload. Drives client logic without a real socket.
 *
 * @internal Test fixture only.
 */
final class FakeUdpClient implements UdpClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public int $openCount = 0;

    public int $closeCount = 0;

    /** @var list<string|null> */
    private array $responses;

    /** @param list<string|null> $responses */
    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function open(): void
    {
        $this->openCount++;
    }

    public function send(string $data): ?string
    {
        $this->sent[] = $data;

        return array_shift($this->responses);
    }

    public function close(): void
    {
        $this->closeCount++;
    }

    public function lastReplyHost(): ?string
    {
        return 'api.anidb.net';
    }

    public function lastReplyPort(): ?int
    {
        return 9000;
    }
}

/**
 * No-op {@see WaiterInterface} implementation that records wait durations.
 *
 * Used in tests to verify that {@see UdpClient::enforceFloodProtection()}
 * computes the correct wait time without actually sleeping. The recorded durations
 * can then be asserted against expected values — verifying the math, not wall-clock.
 *
 * @internal Test fixture only.
 */
final class NoOpWaiter implements WaiterInterface
{
    /** @var list<float> */
    public array $waits = [];

    public function wait(float $seconds): void
    {
        $this->waits[] = $seconds;
    }
}
