<?php

/**
 * PG-158 (review anidb__srphp #9[i]) pins: the lazily-resolved reply-origin
 * allowlist must EXPIRE, and a rejected reply origin must be diagnosable.
 *
 * The old shape memoized api.anidb.net's A records for the process lifetime.
 * In a resident Workerman worker that never restarts, an AniDB IP rotation
 * turned every genuine reply into a silent origin-validation failure — null
 * return, no log line naming the mismatch, permanent timeouts. The cure:
 * an hourly TTL on the memo (explicitly-injected lists are the trusted seam
 * and never expire), and one throttled error_log line naming the rejected
 * origin per window so a rotation is visible in the shared log.
 *
 * All legs are deterministic: no sleeps, no live DNS (the resolver is an
 * injected seam), throttle release and TTL expiry are poked through the
 * properties the repo's ReflectionClass convention already sanctions.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UdpClientOriginMemoTtlTest extends TestCase
{
    /** @var string|false The ini error_log value as found, restored by tearDown(). */
    private string|false $originalErrorLog;

    /** @var string Scratch log path, removed by tearDown(). */
    private string $logFile;

    /** @var string Scratch dir for the log, removed by tearDown(). */
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/anidb_origin_' . bin2hex(random_bytes(8));
        mkdir($this->dir, 0700, true);
        $this->logFile = $this->dir . '/error.log';
        $this->originalErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', is_string($this->originalErrorLog) ? $this->originalErrorLog : '');
        @unlink($this->logFile);
        @rmdir($this->dir);
    }

    /**
     * Build a UdpClient with an explicit allow-list so NO resolution runs —
     * the origin under test is rejected purely by list membership.
     *
     * @param list<string> $allowedHosts
     */
    private function clientWithAllowList(UdpClientInterface $transport, array $allowedHosts): UdpClient
    {
        return new UdpClient(
            ['username' => 'testuser', 'api_key' => 'testkey'],
            $transport,
            new NoOpWaiter(),
            $allowedHosts,
        );
    }

    /**
     * @return list<string>
     */
    private function resolveLazily(UdpClient $client): array
    {
        $method = new \ReflectionMethod($client, 'resolveValidOriginHosts');
        $method->setAccessible(true);

        /** @var list<string> $hosts */
        $hosts = $method->invoke($client);

        return $hosts;
    }

    /**
     * @param int<0,max> $seconds
     */
    private function pokeProperty(UdpClient $client, string $property, int $seconds): void
    {
        $ref = new \ReflectionProperty($client, $property);
        $ref->setAccessible(true);
        $ref->setValue($client, $seconds);
    }

    /**
     * The rejected-origin law: a reply from a host outside the allowlist
     * produces exactly one log line naming "host:port" — the diagnosability
     * the register asked for ("no log line naming the mismatch as the cause").
     */
    public function test_rejected_origin_produces_one_log_line_naming_the_origin(): void
    {
        $transport = new FakeUdpClientWithOrigin(
            ['200 SESSION_KEY LOGIN ACCEPTED'],
            ['9.9.9.9'],
            [9000],
        );
        $client = $this->clientWithAllowList($transport, ['1.1.1.1']);

        $this->expectException(RuntimeException::class);
        try {
            $client->authenticate();
        } finally {
            $logged = (string) file_get_contents($this->logFile);
            $this->assertStringContainsString('reply rejected', $logged);
            $this->assertStringContainsString('9.9.9.9:9000', $logged);
        }
    }

    /**
     * Throttle law: every flood interval CAN produce a rejection (the caller
     * retries), so the line must appear at most once per throttle window —
     * a rejected origin under rotation would otherwise log-storm the shared
     * error sink at up to one line per four seconds.
     */
    public function test_rejected_origin_log_is_throttled_to_one_line_per_window(): void
    {
        $transport = new FakeUdpClientWithOrigin(
            ['200 SESSION_KEY LOGIN ACCEPTED', '200 SESSION_KEY LOGIN ACCEPTED'],
            ['9.9.9.9', '9.9.9.9'],
            [9000, 9000],
        );
        $client = $this->clientWithAllowList($transport, ['1.1.1.1']);

        foreach ([1, 2] as $ignored) {
            try {
                $client->authenticate();
            } catch (RuntimeException) {
                // Rejection is the behavior under test; the throw is expected.
            }
        }

        $logged = (string) file_get_contents($this->logFile);
        $this->assertSame(1, substr_count($logged, 'reply rejected'));
    }

    /**
     * Throttle release: once the window has passed (poked via the stamp
     * property — no sleeps), the next rejection logs again. A permanent
     * single line would hide a second, independent rotation.
     */
    public function test_rejected_origin_log_resumes_after_the_throttle_window(): void
    {
        $transport = new FakeUdpClientWithOrigin(
            ['200 SESSION_KEY LOGIN ACCEPTED', '200 SESSION_KEY LOGIN ACCEPTED'],
            ['9.9.9.9', '9.9.9.9'],
            [9000, 9000],
        );
        $client = $this->clientWithAllowList($transport, ['1.1.1.1']);

        try {
            $client->authenticate();
        } catch (RuntimeException) {
        }

        // Expire the throttle stamp deterministically.
        $this->pokeProperty($client, 'lastOriginRejectLogAt', time() - 3600);

        try {
            $client->authenticate();
        } catch (RuntimeException) {
        }

        $logged = (string) file_get_contents($this->logFile);
        $this->assertSame(2, substr_count($logged, 'reply rejected'));
    }

    /**
     * The lazy path resolves through the injected resolver seam and the memo
     * is REUSED within its TTL — one resolution per window, not one per
     * packet (a synchronous gethostbynamel per reply would stall the loop).
     */
    public function test_lazy_origin_memo_is_reused_within_its_ttl(): void
    {
        $calls = 0;
        $resolver = function (string $host) use (&$calls): array {
            $calls++;

            return ['1.1.1.1'];
        };
        $client = new UdpClient(
            ['username' => 'testuser', 'api_key' => 'testkey'],
            new FakeUdpClientWithOrigin([], [], []),
            new NoOpWaiter(),
            null,
            $resolver,
        );

        $first = $this->resolveLazily($client);
        $second = $this->resolveLazily($client);

        $this->assertSame(1, $calls, 'a fresh memo must not re-resolve');
        $this->assertSame(['api.anidb.net', '1.1.1.1'], $first);
        $this->assertSame($first, $second);
    }

    /**
     * The TTL law itself: once the memo is older than the TTL, the next
     * validation re-resolves AND the rotation is picked up — the new A
     * records replace the stale ones, the stale IP leaves the set. This is
     * the cure for the register's permanent-timeout scenario.
     */
    public function test_lazy_origin_memo_re_resolves_after_the_ttl_expires(): void
    {
        $calls = 0;
        $resolver = function (string $host) use (&$calls): array {
            $calls++;

            return $calls === 1 ? ['1.1.1.1'] : ['2.2.2.2'];
        };
        $client = new UdpClient(
            ['username' => 'testuser', 'api_key' => 'testkey'],
            new FakeUdpClientWithOrigin([], [], []),
            new NoOpWaiter(),
            null,
            $resolver,
        );

        $first = $this->resolveLazily($client);
        $this->assertContains('1.1.1.1', $first);

        // Expire the memo deterministically via its resolve stamp.
        $this->pokeProperty($client, 'originHostsResolvedAt', time() - 7200);

        $second = $this->resolveLazily($client);

        $this->assertSame(2, $calls, 'an expired memo must re-resolve');
        $this->assertContains('2.2.2.2', $second, 'rotation must land in the allowlist');
        $this->assertNotContains('1.1.1.1', $second, 'the stale A record must be dropped');
    }

    /**
     * The seam law: a constructor-injected allowlist is the trusted set —
     * it is never DNS-resolved and never TTL-expired. Existing tests inject
     * dotted quads for exactly this reason; TTL-churning them into live DNS
     * would break the "no live DNS" convention this very file upholds.
     */
    public function test_injected_origin_list_is_never_resolved(): void
    {
        $calls = 0;
        $resolver = function (string $host) use (&$calls): array {
            $calls++;

            return ['9.9.9.9'];
        };
        $client = new UdpClient(
            ['username' => 'testuser', 'api_key' => 'testkey'],
            new FakeUdpClientWithOrigin([], [], []),
            new NoOpWaiter(),
            ['5.5.5.5'],
            $resolver,
        );

        $this->resolveLazily($client);
        $this->pokeProperty($client, 'originHostsResolvedAt', time() - 7200);
        $hosts = $this->resolveLazily($client);

        $this->assertSame(0, $calls, 'an injected allowlist must never hit the resolver');
        $this->assertSame(['5.5.5.5'], $hosts);
    }
}
