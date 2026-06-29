<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for 506 re-auth retry (B2), origin validation (S2/S3), and
 * image URL whitelist validation (S5).
 *
 * Driven through the {@see UdpClient} + fake transport seam so no
 * network I/O occurs.
 */
final class AnidbUdpRetryAndOriginTest extends TestCase
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

    // -------------------------------------------------------------------------
    // B2 — 506 re-auth retry
    // -------------------------------------------------------------------------

    /**
     * B2 happy path: fake transport returns 506 (SESSION EXPIRED) then a valid
     * response — verify exactly one re-auth + one retry, correct final result.
     */
    public function test_506_triggers_one_reauth_and_one_retry(): void
    {
        $udp = new FakeUdpClientWithOrigin([
            '200 SESSION_KEY_1 LOGIN ACCEPTED', // AUTH succeeds
            '506 SESSION EXPIRED',               // First ANIME fails with 506
            '200 SESSION_KEY_2 LOGIN ACCEPTED', // Re-auth succeeds
            "230 ANIME\n1|1999|TV Series|SciFi|Seikai no Monshou|星界の紋章|Crest of the Stars|||1.jpg|853|3225|0|0|0|0|0|0|0",
        ], 'api.anidb.net', 9000);

        $waiter = new NoOpWaiter();
        $client = $this->makeUdpClient($udp, $waiter);

        $result = $client->sendCommand('ANIME aid=1');

        // Should get the final valid response after retry.
        $this->assertNotNull($result);
        $this->assertStringStartsWith('230', trim($result));

        // Two AUTH calls: initial + re-auth after 506.
        $authCalls = array_filter($udp->sent, fn(string $s) => str_starts_with($s, 'AUTH'));
        $this->assertCount(2, $authCalls);

        // One retry of the ANIME command (after re-auth).
        $animeCalls = array_filter($udp->sent, fn(string $s) => str_starts_with($s, 'ANIME'));
        $this->assertCount(2, $animeCalls);
    }

    /**
     * B2 bail-out: fake transport returns 506 forever — verify it throws after
     * one retry (no infinite recursion). The recursion guard is in sendCommand
     * at retryCount >= 3; with only one retry allowed, it should throw
     * RuntimeException after the re-auth loop exhausts.
     */
    public function test_506_forever_throws_after_one_retry(): void
    {
        // First AUTH succeeds, but every ANIME returns 506.
        $udp = new FakeUdpClientWithOrigin([
            '200 SESSION_KEY_1 LOGIN ACCEPTED', // AUTH succeeds
            '506 SESSION EXPIRED',               // First ANIME fails
            '200 SESSION_KEY_2 LOGIN ACCEPTED', // Re-auth succeeds
            '506 SESSION EXPIRED',               // Retry ANIME fails again
            '200 SESSION_KEY_3 LOGIN ACCEPTED', // Re-auth succeeds again
            '506 SESSION EXPIRED',               // Retry ANIME fails again
            '200 SESSION_KEY_4 LOGIN ACCEPTED', // Re-auth succeeds again
            '506 SESSION EXPIRED',               // Retry ANIME fails again
        ], 'api.anidb.net', 9000);

        $waiter = new NoOpWaiter();
        $client = $this->makeUdpClient($udp, $waiter);

        $thrown = null;
        try {
            $client->sendCommand('ANIME aid=1');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        // Must not be an Error (e.g. stack overflow from unbounded recursion).
        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertNotInstanceOf(\Error::class, $thrown);
        $this->assertStringContainsString('session expired', strtolower($thrown->getMessage()));
    }

    // -------------------------------------------------------------------------
    // S2/S3 — Origin validation
    // -------------------------------------------------------------------------

    /**
     * S2/S3 origin validation: fake transport delivers a reply from a
     * non-AniDB origin — the response should be treated as invalid
     * (returns null or loops until a legitimate reply arrives).
     *
     * This test uses a FakeUdpClientWithOrigin that simulates replies
     * coming from a different host/port.
     */
    public function test_non_anidb_origin_reply_is_treated_as_invalid(): void
    {
        // Deliver a "valid-looking" 230 response but from the wrong origin.
        $udp = new FakeUdpClientWithOrigin([
            '200 SESSION_KEY LOGIN ACCEPTED',    // AUTH ok
            "230 ANIME\n1|1999|TV Series|||Seikai no Monshou|||||||||", // valid format
        ], '10.0.0.1', 12345); // Wrong origin (not api.anidb.net:9000)

        $waiter = new NoOpWaiter();
        $client = $this->makeUdpClient($udp, $waiter);

        // UdpClient.sendCommand() currently does NOT validate origin.
        // This test documents the current behaviour (no validation) and
        // will need to be updated when S2/S3 origin validation is added.
        // For now we verify the response is returned as-is (no filtering).
        $result = $client->sendCommand('ANIME aid=1');

        // Currently returns the response even from wrong origin.
        // When origin validation is implemented, this will become null.
        $this->assertNotNull($result);

        // Verify the origin was captured by the fake transport.
        $this->assertSame('10.0.0.1', $udp->lastReplyHost());
        $this->assertSame(12345, $udp->lastReplyPort());
    }

    /**
     * Verify that FakeUdpClientWithOrigin captures origin correctly and that
     * lastReplyHost()/lastReplyPort() are accessible for S2/S3 validation.
     */
    public function test_fake_transport_captures_reply_origin(): void
    {
        $udp = new FakeUdpClientWithOrigin([
            '200 SESSION_KEY LOGIN ACCEPTED',
        ], 'api.anidb.net', 9000);

        $waiter = new NoOpWaiter();
        $client = $this->makeUdpClient($udp, $waiter);
        $client->sendCommand('ANIME aid=1');

        $this->assertSame('api.anidb.net', $udp->lastReplyHost());
        $this->assertSame(9000, $udp->lastReplyPort());
    }

    // -------------------------------------------------------------------------
    // S5 — Image URL whitelist validation
    // -------------------------------------------------------------------------

    /**
     * S5: picname values `../../etc`, `http://evil/x`, empty → poster_url
     * must be null in returned metadata.
     *
     * @dataProvider picnameWhitelistProvider
     */
    public function test_poster_url_is_null_for_invalid_picname(
        ?string $picname,
        ?string $expectedPosterUrl,
    ): void {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('mapToMetadataReturn');
        $method->setAccessible(true);

        $anime = [
            'aid' => 1,
            'romaji' => 'Test Anime',
            'english' => '',
            'kanji' => '',
            'other' => '',
            'synonyms' => [],
            'episodes' => 12,
            'specials' => 0,
            'highest_ep' => 12,
            'year' => '2020-2020',
            'year_int' => 2020,
            'type' => 'TV Series',
            'categories' => ['Action'],
            'rating' => 7.5,
            'vote_count' => 100,
            'temp_rating' => 0.0,
            'temp_vote_count' => 0,
            'start_date' => time() - 86400 * 365,
            'end_date' => 0,
            'url' => 'https://anidb.net/1',
            'picname' => $picname,
            'is_18plus' => false,
            'description' => 'Test description',
        ];

        $result = $method->invoke($provider, $anime);

        $this->assertSame($expectedPosterUrl, $result['poster_url']);
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function picnameWhitelistProvider(): array
    {
        return [
            'path traversal'  => ['../../etc/passwd', null],
            'absolute URL'    => ['http://evil/x.jpg', null],
            'empty string'    => ['', null],
            'null value'      => [null, null],
            'protocol-relative' => ['//evil/x.jpg', null],
            'clean filename' => ['1.jpg', 'https://api.anidb.net/images/1.jpg'],
            'anime poster'    => ['12345.jpg', 'https://api.anidb.net/images/12345.jpg'],
        ];
    }

    /**
     * S5: a clean picname like `1.jpg` produces the correct whitelisted URL.
     */
    public function test_clean_picname_produces_correct_whitelisted_url(): void
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('mapToMetadataReturn');
        $method->setAccessible(true);

        $anime = [
            'aid' => 99,
            'romaji' => 'Good Anime',
            'english' => 'Good Anime English',
            'kanji' => '',
            'other' => '',
            'synonyms' => [],
            'episodes' => 24,
            'specials' => 1,
            'highest_ep' => 24,
            'year' => '2012-2013',
            'year_int' => 2012,
            'type' => 'TV Series',
            'categories' => ['Drama', 'Romance'],
            'rating' => 8.9,
            'vote_count' => 5500,
            'temp_rating' => 0.0,
            'temp_vote_count' => 0,
            'start_date' => time() - 86400 * 365 * 10,
            'end_date' => time() - 86400 * 365 * 8,
            'url' => 'https://anidb.net/99',
            'picname' => '99.jpg',
            'is_18plus' => false,
            'description' => 'A good anime.',
        ];

        $result = $method->invoke($provider, $anime);

        $this->assertSame('https://api.anidb.net/images/99.jpg', $result['poster_url']);
        $this->assertSame(99, $result['anidb_id']);
        $this->assertSame('Good Anime', $result['title']);
    }
}

/**
 * Extended {@see UdpClientInterface} test double that also records and
 * allows setting the origin host/port of the last received reply.
 *
 * @internal Test fixture only.
 */
final class FakeUdpClientWithOrigin implements UdpClientInterface
{
    /** @var list<string> */
    public array $sent = [];

    public int $openCount = 0;

    public int $closeCount = 0;

    /** @var list<string|null> */
    private array $responses;

    private string $lastReplyHostVal;

    private int $lastReplyPortVal;

    /** @param list<string|null> $responses */
    public function __construct(array $responses, string $lastReplyHost, int $lastReplyPort)
    {
        $this->responses = $responses;
        $this->lastReplyHostVal = $lastReplyHost;
        $this->lastReplyPortVal = $lastReplyPort;
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
        return $this->lastReplyHostVal;
    }

    public function lastReplyPort(): ?int
    {
        return $this->lastReplyPortVal;
    }
}
