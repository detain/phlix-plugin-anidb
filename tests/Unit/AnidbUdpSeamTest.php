<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use Phlix\Anidb\Udp\UdpClientInterface;
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
     * Build a provider wired to the supplied fake transport.
     *
     * @param UdpClientInterface $udp Fake transport returning canned responses.
     */
    private function makeProvider(UdpClientInterface $udp): AnidbMetadataProvider
    {
        return new AnidbMetadataProvider(
            [
                'username'       => 'testuser',
                'api_key'        => 'testkey',
                'use_title_dump' => false,
                'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
            ],
            $udp,
        );
    }

    public function test_authenticate_succeeds_against_fake_transport_and_sets_session_key(): void
    {
        $udp = new FakeUdpClient(['200 sEsSiOnKeY LOGIN ACCEPTED']);

        $provider = $this->makeProvider($udp);

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
        $provider = $this->makeProvider($udp);

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
        $provider = $this->makeProvider($udp);

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
        // sendCommand() without a session key sends the bare command and returns
        // the trimmed transport reply verbatim. One send => no flood delay.
        $udp = new FakeUdpClient(["230 ANIME\n1|data"]);
        $provider = $this->makeProvider($udp);

        $send = new \ReflectionMethod($provider, 'sendCommand');
        $send->setAccessible(true);

        $result = $send->invoke($provider, 'PING');

        $this->assertSame("230 ANIME\n1|data", $result);
        $this->assertSame(['PING'], $udp->sent);
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
