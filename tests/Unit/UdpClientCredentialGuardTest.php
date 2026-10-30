<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fail-fast credential guard at the entry of {@see UdpClient::authenticate()}.
 *
 * The constructor's array{username, api_key} shape is documentation-time only;
 * plugin settings can deliver a missing key, a non-string value, or an empty
 * string. Previously sprintf()/urlencode() emitted an undefined-key warning (or
 * silently AUTH'd with an empty credential) and put a malformed datagram on the
 * wire. Now authenticate() throws a descriptive RuntimeException BEFORE the
 * transport sees any bytes — proven here with a recording transport that must
 * stay silent.
 */
final class UdpClientCredentialGuardTest extends TestCase
{
    private function makeClient(array $settings, GuardTransport $transport): UdpClient
    {
        return new UdpClient($settings, $transport, new SilentWaiter());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unusableSettingsProvider(): array
    {
        return [
            'both keys absent' => [[]],
            'api_key absent' => [['username' => 'user']],
            'username absent' => [['api_key' => 'key']],
            'username empty' => [['username' => '', 'api_key' => 'key']],
            'api_key empty' => [['username' => 'user', 'api_key' => '']],
            'api_key non-string' => [['username' => 'user', 'api_key' => 42]],
            'username non-string' => [['username' => ['user'], 'api_key' => 'key']],
        ];
    }

    /**
     * @dataProvider unusableSettingsProvider
     */
    public function test_authenticate_throws_before_the_transport_sees_bytes(array $settings): void
    {
        $transport = new GuardTransport();
        $client = $this->makeClient($settings, $transport);

        $thrown = null;
        try {
            $client->authenticate();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertStringContainsString('anidb: username/api_key settings are required', $thrown->getMessage());
        $this->assertSame([], $transport->sent, 'a malformed AUTH datagram reached the transport');
        $this->assertSame(0, $transport->openCount);
        $this->assertNull($client->getSessionKey());
    }

    /**
     * @dataProvider unusableSettingsProvider
     */
    public function test_lazy_auth_via_sendCommand_also_guarded(array $settings): void
    {
        $transport = new GuardTransport();
        $client = $this->makeClient($settings, $transport);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('anidb: username/api_key settings are required');

        $client->sendCommand('ANIME aid=1');
    }

    public function test_valid_credentials_still_reach_the_transport(): void
    {
        $transport = new GuardTransport();
        $transport->responses[] = '200 SESSIONKEY LOGIN ACCEPTED';
        $client = $this->makeClient(['username' => 'user', 'api_key' => 'key'], $transport);

        $client->authenticate();

        $this->assertCount(1, $transport->sent);
        $this->assertStringStartsWith('AUTH user=user&pass=key', $transport->sent[0]);
        $this->assertSame('SESSIONKEY', $client->getSessionKey());
    }
}

/**
 * Transport fake that records every datagram; the guard tests assert it stays
 * silent for unusable settings and sees exactly the AUTH bytes otherwise.
 */
final class GuardTransport implements UdpClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    /** @var list<string|null> */
    public array $responses = [];

    public int $openCount = 0;

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
    }

    public function lastReplyHost(): ?string
    {
        // Legitimate origin (mirrors FakeUdpClient): UdpClient::udpSend() drops
        // replies whose origin fails validation, which would mask the guard's
        // behavior in the positive-control test.
        return 'api.anidb.net';
    }

    public function lastReplyPort(): ?int
    {
        return 9000;
    }
}

/**
 * Waiter fake that never delays (flood protection is irrelevant to this seam).
 */
final class SilentWaiter implements WaiterInterface
{
    public function wait(float $seconds): void
    {
    }
}
