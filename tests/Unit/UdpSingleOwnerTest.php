<?php

/**
 * ANI-S23: single-UDP-owner election + joint pacing seam tests (no network).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\FileUdpCoordinator;
use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
use PHPUnit\Framework\TestCase;

final class UdpSingleOwnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/anidb-owner-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_exactly_one_of_n_contenders_becomes_owner(): void
    {
        $contenders = [];
        for ($i = 0; $i < 5; $i++) {
            $contenders[] = new FileUdpCoordinator($this->dir);
        }
        $owners = array_filter($contenders, static fn (FileUdpCoordinator $c): bool => $c->tryBecomeOwner());

        $this->assertCount(1, $owners);
    }

    public function test_ownership_hands_over_after_release(): void
    {
        $a = new FileUdpCoordinator($this->dir);
        $b = new FileUdpCoordinator($this->dir);
        $this->assertTrue($a->tryBecomeOwner());
        $this->assertFalse($b->tryBecomeOwner());
        $a->release();
        $this->assertTrue($b->tryBecomeOwner());
        $this->assertFalse($a->isOwner());
    }

    public function test_joint_pacing_spaces_reservations_across_coordinators(): void
    {
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $a = new FileUdpCoordinator($this->dir, $clock);
        $b = new FileUdpCoordinator($this->dir, $clock);

        $this->assertSame(0.0, $a->reserveSendSlot(4.0));
        $this->assertEqualsWithDelta(4.0, $b->reserveSendSlot(4.0), 0.0001);
        $this->assertEqualsWithDelta(8.0, $a->reserveSendSlot(4.0), 0.0001);

        $now = 1100.0; // long idle: the ledger no longer delays anyone
        $this->assertSame(0.0, $b->reserveSendSlot(4.0));
    }

    public function test_non_owner_client_never_opens_or_sends(): void
    {
        $owner = new FileUdpCoordinator($this->dir);
        $this->assertTrue($owner->tryBecomeOwner());

        $transport = new CountingTransport();
        $client = new UdpClient(
            ['username' => 'u', 'api_key' => 'k'],
            $transport,
            new NoWaitWaiter(),
            null,
            null,
            new FileUdpCoordinator($this->dir),
        );

        $client->open();
        $this->assertNull($client->sendCommand('ANIME aid=1'));
        $this->assertSame(0, $transport->opens);
        $this->assertSame(0, $transport->sends);
    }

    public function test_client_takes_over_when_owner_releases(): void
    {
        $owner = new FileUdpCoordinator($this->dir);
        $owner->tryBecomeOwner();

        $transport = new CountingTransport();
        $client = new UdpClient(
            ['username' => 'u', 'api_key' => 'k'],
            $transport,
            new NoWaitWaiter(),
            null,
            null,
            new FileUdpCoordinator($this->dir),
        );
        $this->assertFalse($client->ensureOwnership());

        $owner->release();
        $this->assertTrue($client->ensureOwnership());
        $this->assertTrue($client->ensureOwnership());
        $this->assertSame(1, $transport->opens, 'transport opens once per ownership');
    }

    public function test_unwritable_dir_degrades_to_standalone(): void
    {
        $file = $this->dir . '/not-a-dir';
        file_put_contents($file, 'x');
        $c = new FileUdpCoordinator($file);

        $prev = ini_set('error_log', '/dev/null');
        try {
            $this->assertTrue($c->tryBecomeOwner());
        } finally {
            ini_set('error_log', (string) $prev);
        }
    }
}

final class NoWaitWaiter implements WaiterInterface
{
    public function wait(float $seconds): void
    {
    }
}

final class CountingTransport implements UdpClientInterface
{
    public int $opens = 0;
    public int $sends = 0;

    public function open(): void
    {
        $this->opens++;
    }

    public function send(string $data): ?string
    {
        $this->sends++;

        return null;
    }

    public function close(): void
    {
    }

    public function lastReplyHost(): ?string
    {
        return null;
    }

    public function lastReplyPort(): ?int
    {
        return null;
    }
}
