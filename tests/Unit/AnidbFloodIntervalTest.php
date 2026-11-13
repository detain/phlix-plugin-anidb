<?php

/**
 * Flood-interval invariant tests (review plugin-anidb__srphp #1).
 *
 * AniDB's UDP API forbids more than one packet every four seconds, and the
 * server counts EVERY datagram toward the rate limit — PING keep-alives and
 * AUTH retries included. These tests drive {@see UdpClient} through a
 * transport fake that records a send/wait event timeline, pinning that no
 * sender path (command, PING, 506 re-auth AUTH) bypasses the interval gate.
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

final class AnidbFloodIntervalTest extends TestCase
{
    use SessionKeyProbe;

    /**
     * The class-under-test's flood interval, read from the private constant
     * so the expectations below never drift from the production value.
     */
    private static function floodInterval(): float
    {
        $interval = (new \ReflectionClass(UdpClient::class))->getConstant('FLOOD_PROTECTION_INTERVAL_SEC');

        self::assertIsFloat($interval);

        return $interval;
    }

    private static function setPrivateProperty(UdpClient $client, string $name, mixed $value): void
    {
        $property = (new \ReflectionClass($client))->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($client, $value);
    }

    /**
     * Keep-alive leg: with a due ping and a clean pre-window, the PING and
     * the command must not leave back-to-back — the command pays the full
     * interval behind the PING.
     */
    public function test_command_after_keepalive_ping_pays_the_flood_interval(): void
    {
        $timeline = new PacketTimeline();
        $waiter = null;
        $client = $this->makeClientWithResponses(
            $timeline,
            ['200 K1 LOGIN ACCEPTED', '300 PONG', '230 ANIME ok'],
            $waiter,
        );
        $client->authenticate();

        // Force the keep-alive branch; make the window before the ping clean
        // so the only legitimate wait left is the one behind the PING.
        self::setPrivateProperty($client, 'lastActivityTime', time() - (30 * 60) - 1);
        self::setPrivateProperty($client, 'lastSendTimestamp', microtime(true) - 10.0);
        $timeline->events = [];

        $result = $client->sendCommand('ANIME aid=1');

        $this->assertSame('230 ANIME ok', $result);
        $pingIndex = $timeline->indexOfSend('PING');
        $commandIndex = $timeline->indexOfSend('ANIME aid=1', $pingIndex);

        $this->assertTrue(
            $timeline->hasWaitBetween($pingIndex, $commandIndex),
            'the command followed its PING back-to-back — AniDB counts both packets toward the rate limit',
        );
        $this->assertNotNull($waiter);
        $this->assertCount(1, $waiter->durations);
        $this->assertEqualsWithDelta(self::floodInterval(), $waiter->durations[0], 0.5);
    }

    /**
     * 506 re-auth leg: the AUTH that replaces an expired session must pay the
     * interval behind the command that just received the 506 — and the retried
     * command must pay again behind that AUTH.
     */
    public function test_session_expired_reauth_pays_the_flood_interval_before_and_after_auth(): void
    {
        $timeline = new PacketTimeline();
        $waiter = null;
        $client = $this->makeClientWithResponses(
            $timeline,
            [
                '200 K1 LOGIN ACCEPTED', // bootstrap AUTH
                '506 INVALID SESSION',   // first command answer
                '200 K2 LOGIN ACCEPTED', // re-auth AUTH
                '230 ANIME ok',          // retried command
            ],
            $waiter,
        );
        $client->authenticate();

        self::setPrivateProperty($client, 'lastSendTimestamp', microtime(true) - 10.0);
        $timeline->events = [];

        $result = $client->sendCommand('ANIME aid=1');

        $this->assertSame('230 ANIME ok', $result);
        $this->assertSame('K2', $this->sessionKeyOf($client));

        $firstCommand = $timeline->indexOfSend('ANIME aid=1');
        $reauthAuth = $timeline->indexOfSend('AUTH', $firstCommand);
        $retriedCommand = $timeline->indexOfSend('ANIME aid=1', $reauthAuth);

        $this->assertTrue(
            $timeline->hasWaitBetween($firstCommand, $reauthAuth),
            'the re-auth AUTH left back-to-back behind the command that expired the session',
        );
        $this->assertTrue(
            $timeline->hasWaitBetween($reauthAuth, $retriedCommand),
            'the retried command left back-to-back behind its AUTH',
        );
    }

    /**
     * Boot characterization (passes before and after the cure): the first
     * AUTH of a fresh client has no previous send to space behind and must
     * leave immediately; the first command pays the full interval behind it.
     */
    public function test_boot_auth_leaves_immediately_and_the_first_command_pays_behind_it(): void
    {
        $timeline = new PacketTimeline();
        $waiter = null;
        $client = $this->makeClientWithResponses(
            $timeline,
            ['200 K1 LOGIN ACCEPTED', '230 ANIME ok'],
            $waiter,
        );

        $result = $client->sendCommand('ANIME aid=1');

        $this->assertSame('230 ANIME ok', $result);

        $firstEvent = $timeline->events[0] ?? null;
        $this->assertIsArray($firstEvent);
        $this->assertSame('send', $firstEvent['kind']);
        $this->assertStringStartsWith('AUTH ', $firstEvent['detail']);

        $commandIndex = $timeline->indexOfSend('ANIME aid=1');
        $this->assertTrue($timeline->hasWaitBetween(0, $commandIndex));
        $this->assertNotNull($waiter);
        $this->assertCount(1, $waiter->durations);
        $this->assertEqualsWithDelta(self::floodInterval(), $waiter->durations[0], 0.5);
    }

    /**
     * Wire a client to the timeline fakes with an ordered response queue on
     * the transport; explicit validOriginHosts keeps origin validation
     * deterministic without DNS. The recording waiter is surfaced by
     * reference for interval-size assertions.
     *
     * @param list<string|null>   $responses
     * @param TimelineWaiter|null $waiterOut
     */
    private function makeClientWithResponses(
        PacketTimeline $timeline,
        array $responses,
        ?TimelineWaiter &$waiterOut,
    ): UdpClient {
        $waiter = new TimelineWaiter($timeline);
        $waiterOut = $waiter;

        return new UdpClient(
            ['username' => 'testuser', 'api_key' => 'testkey'],
            new TimelineUdpClient($timeline, $responses),
            $waiter,
            ['api.anidb.net'],
        );
    }
}

/**
 * Ordered event log shared by the send/wait fakes below. Records the
 * sequence of packets and flood waits so tests can assert that a wait sits
 * BETWEEN two sends — the structural form of the flood-interval invariant.
 *
 * @internal Test fixture only.
 */
final class PacketTimeline
{
    /** @var list<array{kind: string, detail: string}> */
    public array $events = [];

    public function recordSend(string $data): void
    {
        $this->events[] = ['kind' => 'send', 'detail' => $data];
    }

    public function recordWait(float $seconds): void
    {
        $this->events[] = ['kind' => 'wait', 'detail' => (string) $seconds];
    }

    /**
     * Index of the first send at/after $from whose payload starts with $prefix.
     */
    public function indexOfSend(string $prefix, int $from = 0): int
    {
        for ($i = $from; $i < count($this->events); $i++) {
            if ($this->events[$i]['kind'] === 'send' && str_starts_with($this->events[$i]['detail'], $prefix)) {
                return $i;
            }
        }

        $this->fail(sprintf('timeline has no send starting with "%s" at/after index %d', $prefix, $from));
    }

    /**
     * Whether any flood wait is recorded strictly between the two indexes.
     */
    public function hasWaitBetween(int $afterIndex, int $beforeIndex): bool
    {
        for ($i = $afterIndex + 1; $i < $beforeIndex; $i++) {
            if ($this->events[$i]['kind'] === 'wait') {
                return true;
            }
        }

        return false;
    }

    private function fail(string $message): never
    {
        throw new \RuntimeException($message);
    }
}

/**
 * {@see UdpClientInterface} fake that records every payload on the shared
 * timeline and pops canned replies in order. Always reports the legitimate
 * reply origin so origin validation never masks the behavior under test.
 *
 * @internal Test fixture only.
 */
final class TimelineUdpClient implements UdpClientInterface
{
    /** @var list<string|null> */
    private array $responses;

    /** @param list<string|null> $responses */
    public function __construct(private PacketTimeline $timeline, array $responses = [])
    {
        $this->responses = $responses;
    }

    public function open(): void
    {
    }

    public function send(string $data): ?string
    {
        $this->timeline->recordSend($data);

        return array_shift($this->responses);
    }

    public function close(): void
    {
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
 * {@see WaiterInterface} fake that records each flood wait on the shared
 * timeline and mirrors the durations for interval-size assertions.
 *
 * @internal Test fixture only.
 */
final class TimelineWaiter implements WaiterInterface
{
    /** @var list<float> */
    public array $durations = [];

    public function __construct(private PacketTimeline $timeline)
    {
    }

    public function wait(float $seconds): void
    {
        $this->durations[] = $seconds;
        $this->timeline->recordWait($seconds);
    }
}
