<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Drives the AUTH / command path of {@see AnidbMetadataProvider} through the
 * S1a transport seam ({@see UdpClientInterface}) using a FAKE client that
 * returns canned responses and records what was sent — no network, no real
 * socket.
 *
 * This is the payoff of the S1a refactor: AUTH success, the B1 friendly-throw
 * failure path, and the raw sendCommand() round-trip are now exercisable
 * end-to-end against an injected transport. It also gives B2 (506 re-auth retry)
 * and S2/S3 (origin validation) a ready harness.
 */
final class AnidbUdpSeamTest extends TestCase
{
    /**
     * Build a provider wired to the supplied fake transport and waiter.
     *
     * @param UdpClientInterface  $udp    Fake transport returning canned responses.
     * @param WaiterInterface     $waiter Waiter for flood-protection delays.
     */
    private function makeProvider(UdpClientInterface $udp, WaiterInterface $waiter): AnidbMetadataProvider
    {
        return new AnidbMetadataProvider(
            [
                'username'       => 'testuser',
                'api_key'        => 'testkey',
                'use_title_dump' => false,
                'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
            ],
            $udp,
            $waiter,
        );
    }

    public function test_authenticate_succeeds_against_fake_transport_and_sets_session_key(): void
    {
        $udp = new FakeUdpClient(['200 sEsSiOnKeY LOGIN ACCEPTED']);
        $waiter = new NoOpWaiter();

        $provider = $this->makeProvider($udp, $waiter);

        $auth = new \ReflectionMethod($provider, 'authenticate');
        $auth->setAccessible(true);
        $auth->invoke($provider);

        // The session key parsed from "200 <key> LOGIN ACCEPTED" must be stored.
        $sessionKeyProp = new \ReflectionProperty($provider, 'sessionKey');
        $sessionKeyProp->setAccessible(true);
        $this->assertSame('sEsSiOnKeY', $sessionKeyProp->getValue($provider));

        // The seam was driven with the AUTH command (no session key on first send).
        $this->assertCount(1, $udp->sent);
        $this->assertStringStartsWith('AUTH user=testuser&pass=testkey', $udp->sent[0]);
        // authenticate() drives the transport directly; opening the socket is
        // onEnable()'s job, so AUTH itself does not call open() here.
        $this->assertSame(0, $udp->openCount);
    }

    /**
     * B1 friendly-throw path, now reachable END-TO-END through the seam:
     * a 5xx AUTH reply makes authenticate() throw the friendly, code-specific
     * \RuntimeException returned by parseAuthFailure() — never a fatal \Error.
     *
     * @dataProvider authFailureProvider
     */
    public function test_authenticate_throws_friendly_runtime_exception_on_failure_response(
        string $cannedResponse,
        string $expectedMessageFragment
    ): void {
        $udp = new FakeUdpClient([$cannedResponse]);
        $waiter = new NoOpWaiter();
        $provider = $this->makeProvider($udp, $waiter);

        $auth = new \ReflectionMethod($provider, 'authenticate');
        $auth->setAccessible(true);

        $thrown = null;
        try {
            $auth->invoke($provider);
        } catch (\ReflectionException $e) {
            // Reflection wraps the real exception thrown inside the method.
            $thrown = $e->getPrevious();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertNotInstanceOf(\Error::class, $thrown);
        $this->assertStringContainsString($expectedMessageFragment, $thrown->getMessage());

        // No session key should be set on a failed AUTH.
        $sessionKeyProp = new \ReflectionProperty($provider, 'sessionKey');
        $sessionKeyProp->setAccessible(true);
        $this->assertNull($sessionKeyProp->getValue($provider));
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
        $provider = $this->makeProvider($udp, $waiter);

        $auth = new \ReflectionMethod($provider, 'authenticate');
        $auth->setAccessible(true);

        $thrown = null;
        try {
            $auth->invoke($provider);
        } catch (\ReflectionException $e) {
            $thrown = $e->getPrevious();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertStringContainsString('no response', $thrown->getMessage());
    }

    public function test_send_command_returns_canned_transport_response(): void
    {
        // sendCommand() with null sessionKey does lazy AUTH first, then sends
        // the actual command. Two sends: AUTH response + PING response.
        $udp = new FakeUdpClient([
            '200 SESSION_KEY LOGIN ACCEPTED',  // AUTH response
            "230 ANIME\n1|data",              // PING response
        ]);
        $waiter = new NoOpWaiter();
        $provider = $this->makeProvider($udp, $waiter);

        $send = new \ReflectionMethod($provider, 'sendCommand');
        $send->setAccessible(true);

        $result = $send->invoke($provider, 'PING');

        $this->assertSame("230 ANIME\n1|data", $result);
        // Two commands sent: AUTH (no session key) then PING (with session key)
        $this->assertCount(2, $udp->sent);
        $this->assertStringStartsWith('AUTH user=testuser', $udp->sent[0]);
        $this->assertSame('PING&s=SESSION_KEY', $udp->sent[1]);
    }

    /**
     * S1b success condition: flood-interval logic is honored without real sleeping.
     *
     * Verifies that enforceFloodProtection() calls the waiter with the correct
     * computed wait time based on elapsed time since last send — not wall-clock.
     * The NoOpWaiter records all wait durations so we can assert the exact value.
     *
     * @dataProvider floodWaitTimeProvider
     */
    public function test_enforce_flood_protection_computes_correct_wait_time(
        float $elapsedSinceLastSend,
        ?float $expectedWaitTime,
    ): void {
        $udp = new FakeUdpClient(["230 ANIME\n1|data", '200 SESSION KEY LOGIN ACCEPTED']);
        $waiter = new NoOpWaiter();
        $provider = $this->makeProvider($udp, $waiter);

        // Simulate elapsed time since last send by directly setting lastSendTimestamp
        $reflection = new \ReflectionClass($provider);
        $lastSendProp = $reflection->getProperty('lastSendTimestamp');
        $lastSendProp->setAccessible(true);

        // lastSendTimestamp is "seconds ago" from now, so subtract from current time
        $now = microtime(true);
        $lastSendProp->setValue($provider, $now - $elapsedSinceLastSend);

        // Also set session key so sendCommand doesn't try to AUTH first
        $sessionKeyProp = $reflection->getProperty('sessionKey');
        $sessionKeyProp->setAccessible(true);
        $sessionKeyProp->setValue($provider, 'fake-session-key');

        // Call sendCommand — enforceFloodProtection runs before the UDP send
        $send = $reflection->getMethod('sendCommand');
        $send->setAccessible(true);
        $send->invoke($provider, 'ANIME aid=1');

        // Assert the computed wait time was passed to the waiter (not wall-clock)
        if ($expectedWaitTime === null) {
            // No wait should have been called
            $this->assertCount(0, $waiter->waits);
        } else {
            $this->assertCount(1, $waiter->waits);
            $this->assertEqualsWithDelta($expectedWaitTime, $waiter->waits[0], 0.001);
        }
    }

    /**
     * @return array<string, array{float, float|null}>
     */
    public static function floodWaitTimeProvider(): array
    {
        // Format: [elapsedSinceLastSend, expectedWaitTime|null]
        // null means no wait should be called (waitTime <= 0)
        // FLOOD_PROTECTION_INTERVAL_SEC = 4.0
        return [
            // No wait needed if enough time has passed
            'elapsed >= interval: no wait' => [5.0, null],
            // Exactly at interval: no wait
            'elapsed == interval: no wait' => [4.0, null],
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
 * records each sent payload. Drives provider logic without a real socket.
 *
 * @internal Test fixture only.
 */
final class FakeUdpClient implements UdpClientInterface
{
    /**
     * Payloads passed to {@see send()}, in order.
     *
     * @var list<string>
     */
    public array $sent = [];

    /**
     * Number of times {@see open()} was called.
     */
    public int $openCount = 0;

    /**
     * Number of times {@see close()} was called.
     */
    public int $closeCount = 0;

    /**
     * Queue of canned responses returned by successive {@see send()} calls.
     *
     * @var list<string|null>
     */
    private array $responses;

    /**
     * @param list<string|null> $responses Canned replies, returned FIFO. When
     *     exhausted, {@see send()} returns null (timeout-equivalent).
     */
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
 * Used in tests to verify that {@see AnidbMetadataProvider::enforceFloodProtection()}
 * computes the correct wait time without actually sleeping. The recorded durations
 * can then be asserted against expected values — verifying the math, not wall-clock.
 *
 * @internal Test fixture only.
 */
final class NoOpWaiter implements WaiterInterface
{
    /**
     * Wait durations passed to {@see wait()}, in call order.
     *
     * @var list<float>
     */
    public array $waits = [];

    public function wait(float $seconds): void
    {
        $this->waits[] = $seconds;
    }
}
