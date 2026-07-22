<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use Phlix\Anidb\TitleDump\TitleDumpManager;
use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * CONSEQUENCE tests for the plan_plugins.md §1 anidb fixes.
 *
 * Each test is written to go RED if the corresponding defect is reintroduced
 * (mutation-verified): boot-time network I/O, the hostname-vs-IP origin bug,
 * the 506-retry key-strip bug, the wrong poster CDN host, and a blocking HTTP
 * fallback in place of the cooperative-wait Workerman\Http\Client.
 */
final class AnidbBootAndOriginConsequenceTest extends TestCase
{
    private const SETTINGS = [
        'username' => 'testuser',
        'api_key' => 'testkey',
        'use_title_dump' => true,
        'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
    ];

    // -------------------------------------------------------------------------
    // BOOT I/O — onEnable() must do ZERO network I/O (item-5c3 landmine)
    // -------------------------------------------------------------------------

    public function test_onEnable_performs_zero_network_io(): void
    {
        $transport = new RecordingOriginTransport(
            ['200 SESSIONKEY LOGIN ACCEPTED', null],
            ['api.anidb.net', 'api.anidb.net'],
            [9000, 9000],
        );
        $udpSession = new UdpClient(self::SETTINGS, $transport, new RecordingWaiter());

        // A title-dump manager whose download/load MUST NOT be triggered at boot.
        $titleDump = new TitleDumpManager(
            sys_get_temp_dir() . '/anidb-boot-io-test',
            self::SETTINGS['title_dump_url'],
        );

        $provider = new AnidbMetadataProvider(
            self::SETTINGS,
            null,
            null,
            null,
            null,
            null,
            $titleDump,
            $udpSession,
        );

        $provider->onEnable(new NullContainer());

        // No socket opened, no datagram sent, no title-dump load — all deferred.
        $this->assertSame(0, $transport->openCount, 'onEnable opened the UDP socket');
        $this->assertSame([], $transport->sent, 'onEnable sent a datagram');
        $this->assertFalse($titleDump->isLoaded(), 'onEnable loaded/downloaded the title dump');

        // Only the first real lookup triggers the connect step.
        $provider->resolveAidByTitle('some anime');
        $this->assertSame(1, $transport->openCount, 'lookup did not lazily open the socket');
        $this->assertTrue($titleDump->isLoaded(), 'lookup did not lazily load the title dump');
    }

    // -------------------------------------------------------------------------
    // ORIGIN CHECK — validation must SUCCEED for a realistic dotted-quad IP
    // (the peer reported by recvfrom() is an IP, never the hostname string).
    // -------------------------------------------------------------------------

    public function test_origin_validation_succeeds_for_realistic_ip_origin(): void
    {
        $realIp = '176.9.55.90'; // a realistic AniDB-style server IP (dotted-quad)

        $transport = new RecordingOriginTransport(
            [
                '200 SESSIONKEY LOGIN ACCEPTED',
                "230 ANIME\n1|data",
            ],
            [$realIp, $realIp],
            [9000, 9000],
        );

        // Inject the resolved origin IP set so validation compares like-for-like
        // without live DNS. Under the old hostname-vs-IP comparison this reply
        // would be rejected (returns null) and AUTH could never succeed.
        $client = new UdpClient(
            self::SETTINGS,
            $transport,
            new RecordingWaiter(),
            [$realIp],
        );

        $result = $client->sendCommand('ANIME aid=1');

        $this->assertNotNull($result, 'a reply from the legitimate IP origin was rejected');
        $this->assertStringStartsWith('230', trim($result));
        $this->assertTrue($client->isAuthenticated());
    }

    public function test_origin_validation_rejects_wrong_ip_origin(): void
    {
        $transport = new RecordingOriginTransport(
            ['200 SESSIONKEY LOGIN ACCEPTED'],
            ['198.51.100.7'],
            [9000],
        );

        $client = new UdpClient(
            self::SETTINGS,
            $transport,
            new RecordingWaiter(),
            ['176.9.55.90'], // legitimate origin differs from the reply origin
        );

        // AUTH reply from a non-trusted IP is dropped → authenticate() sees no
        // response and throws.
        $this->expectException(\RuntimeException::class);
        $client->sendCommand('ANIME aid=1');
    }

    // -------------------------------------------------------------------------
    // 506 RETRY — the retried command must carry exactly the FRESH session key
    // -------------------------------------------------------------------------

    public function test_506_retry_uses_fresh_session_key_only(): void
    {
        $transport = new RecordingOriginTransport(
            [
                '200 SESSION_KEY_1 LOGIN ACCEPTED', // initial AUTH
                '506 SESSION EXPIRED',               // ANIME #1 → expired
                '200 SESSION_KEY_2 LOGIN ACCEPTED', // re-AUTH
                "230 ANIME\n1|data",                 // ANIME #2 (retry) OK
            ],
            array_fill(0, 4, 'api.anidb.net'),
            array_fill(0, 4, 9000),
        );
        $client = new UdpClient(self::SETTINGS, $transport, new RecordingWaiter());

        $result = $client->sendCommand('ANIME aid=1');
        $this->assertNotNull($result);

        $animeSends = array_values(array_filter(
            $transport->sent,
            static fn (string $s): bool => str_starts_with($s, 'ANIME'),
        ));
        $this->assertCount(2, $animeSends);

        $retry = $animeSends[1];

        // Exactly one session-key param, and it is the FRESH key. The bug left a
        // stale &s=SESSION_KEY_1 AND appended &s=SESSION_KEY_2 (two &s= params).
        $this->assertSame(1, substr_count($retry, '&s='), "retry carried >1 session key: {$retry}");
        $this->assertStringContainsString('&s=SESSION_KEY_2', $retry);
        $this->assertStringNotContainsString('SESSION_KEY_1', $retry);
    }

    // -------------------------------------------------------------------------
    // POSTER CDN HOST — must be AniDB's image CDN, not the API host
    // -------------------------------------------------------------------------

    public function test_poster_url_uses_anidb_image_cdn_host(): void
    {
        $provider = new AnidbMetadataProvider(self::SETTINGS);
        $method = (new \ReflectionClass($provider))->getMethod('mapToMetadataReturn');
        $method->setAccessible(true);

        $result = $method->invoke($provider, self::animeFixture('7.jpg'));

        $this->assertSame('https://cdn-eu.anidb.net/images/main/7.jpg', $result['poster_url']);
        $this->assertStringStartsWith('https://cdn-eu.anidb.net/', (string) $result['poster_url']);
        $this->assertStringNotContainsString('api.anidb.net', (string) $result['poster_url']);
    }

    // -------------------------------------------------------------------------
    // HTTP TRANSPORT — cooperative-wait Workerman\Http\Client, no blocking dl
    // -------------------------------------------------------------------------

    public function test_title_dump_indexer_uses_cooperative_wait_client_not_blocking(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/src/TitleDump/TitleDumpIndexer.php');
        $this->assertIsString($src);

        // The canonical async client, imported correctly (not the non-existent
        // Workerman\HttpClient that silently degraded to a blocking fallback).
        $this->assertStringContainsString('use Workerman\Http\Client;', $src);
        $this->assertStringNotContainsString('Workerman\HttpClient', $src);

        // No blocking file_get_contents HTTP fallback — that was the 60s boot hang.
        $this->assertStringNotContainsString('file_get_contents(', $src);

        // Cooperative-wait poll loop that yields to the event loop.
        $this->assertStringContainsString('usleep(1000)', $src);
    }

    // -------------------------------------------------------------------------
    // ANIME DECISION — series/movie, no 'anime' claim
    // -------------------------------------------------------------------------

    public function test_supported_media_types_are_series_and_movie_only(): void
    {
        $provider = new AnidbMetadataProvider(self::SETTINGS);

        $this->assertSame(['series', 'movie'], $provider->supportedMediaTypes());
        $this->assertNotContains('anime', $provider->supportedMediaTypes());
    }

    /**
     * @return array<string, mixed>
     */
    private static function animeFixture(string $picname): array
    {
        return [
            'aid' => 7,
            'romaji' => 'Test',
            'english' => '',
            'kanji' => '',
            'other' => '',
            'synonyms' => [],
            'episodes' => 12,
            'year' => '2020-2020',
            'year_int' => 2020,
            'type' => 'TV Series',
            'categories' => ['Action'],
            'rating' => 7.5,
            'vote_count' => 100,
            'start_date' => time() - 86400,
            'end_date' => 0,
            'picname' => $picname,
            'description' => 'desc',
        ];
    }
}

/**
 * Recording {@see UdpClientInterface} test double with per-response origin.
 *
 * @internal Test fixture only.
 */
final class RecordingOriginTransport implements UdpClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public int $openCount = 0;

    public int $closeCount = 0;

    /** @var list<string|null> */
    private array $responses;

    /** @var list<string> */
    private array $replyHosts;

    /** @var list<int> */
    private array $replyPorts;

    private int $index = 0;

    /**
     * @param list<string|null> $responses
     * @param list<string>      $replyHosts
     * @param list<int>         $replyPorts
     */
    public function __construct(array $responses, array $replyHosts, array $replyPorts)
    {
        $this->responses = $responses;
        $this->replyHosts = $replyHosts;
        $this->replyPorts = $replyPorts;
    }

    public function open(): void
    {
        $this->openCount++;
    }

    public function send(string $data): ?string
    {
        $this->sent[] = $data;
        $response = array_shift($this->responses);
        if ($response !== null) {
            $this->index++;
        }

        return $response;
    }

    public function close(): void
    {
        $this->closeCount++;
    }

    public function lastReplyHost(): ?string
    {
        return $this->replyHosts[$this->index - 1] ?? null;
    }

    public function lastReplyPort(): ?int
    {
        return $this->replyPorts[$this->index - 1] ?? null;
    }
}

/**
 * No-op {@see WaiterInterface} for flood-protection delays.
 *
 * @internal Test fixture only.
 */
final class RecordingWaiter implements WaiterInterface
{
    /** @var list<float> */
    public array $waits = [];

    public function wait(float $seconds): void
    {
        $this->waits[] = $seconds;
    }
}

/**
 * Minimal PSR-11 container that exposes nothing — so
 * registerWithMetadataManager() degrades to a no-op without I/O.
 *
 * @internal Test fixture only.
 */
final class NullContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new class ('not found') extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
        };
    }

    public function has(string $id): bool
    {
        return false;
    }
}
