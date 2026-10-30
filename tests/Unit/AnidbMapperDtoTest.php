<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
use PHPUnit\Framework\TestCase;

/**
 * W-min6 contract tests: the parser→mapper boundary runs through the typed
 * AnimeDto (review anidb srphp #2 / M-ANIDB-21), and an ANIMEDESC timeout is
 * a fetch FAILURE, not evidence of "no description" (M-ANIDB-26).
 */
final class AnidbMapperDtoTest extends TestCase
{
    /**
     * Recording in-memory transport: pops canned replies, remembers commands.
     */
    private function makeProvider(): array
    {
        $transport = new class implements UdpClientInterface {
            /** @var list<string|null> */
            public array $responses = [];
            /** @var list<string> */
            public array $commands = [];
            public function open(): void
            {
            }
            public function send(string $data): ?string
            {
                $this->commands[] = $data;
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
        };
        $waiter = new class implements WaiterInterface {
            public function wait(float $seconds): void
            {
            }
        };
        $udpSession = new UdpClient(
            ['username' => 'testuser', 'api_key' => 'testkey'],
            $transport,
            $waiter,
        );

        $provider = new AnidbMetadataProvider(
            [
                'username' => 'testuser',
                'api_key' => 'testkey',
                'use_title_dump' => false,
                'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
            ],
            null,
            null,
            null,
            null,
            null,
            null,
            $udpSession,
        );

        return [$provider, $transport];
    }

    /**
     * A wire-shaped 230 ANIME response (27 fields, amask 00f0f0f0000000).
     */
    private static function animeResponse(string $type = 'TV Series'): string
    {
        return "230 ANIME\n" . implode('|', [
            '42',        // 0: aid
            '0',         // 1: dateflags
            '2001',      // 2: year
            $type,       // 3: type
            '', '',      // 4-5: related lists
            'Cowboy Bebop', // 6: romaji
            'カウボーイビバップ', // 7: kanji
            'Cowboy Bebop: The Movie', // 8: english
            '',          // 9: other
            'CB',        // 10: parsed by the parser into `synonyms`
            '',          // 11: synonyms position on the wire (parser ignores it)
            '26',        // 12: episodes
            '26',        // 13: highest_ep
            '0',         // 14: specials
            '978307200', // 15: air_date (2001-01-01)
            '1009843200', // 16: end_date
            '',          // 17: url (parser derives it from aid)
            '42.jpg',    // 18: picname
            '870',       // 19: rating
            '21000',     // 20: vote_count
            '0',         // 21: temp_rating
            '0',         // 22: temp_vote_count
            '0', '0',    // 23-24: avg_review, review_count
            '',          // 25: award_list
            '0',         // 26: is_18plus
        ]);
    }

    private static function invokePrivate(AnidbMetadataProvider $provider, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($provider, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($provider, ...$args);
    }

    /**
     * @param list<string|null> $descResponses
     * @return array<int, string> the ANIMEDESC commands actually sent
     */
    private function fetchDetailsWithDescriptions(string $type, array $descResponses): array
    {
        [$provider, $transport] = $this->makeProvider();
        $transport->responses = array_merge(
            ['200 SESSIONKEY LOGIN ACCEPTED', self::animeResponse($type)],
            $descResponses
        );

        $anime = self::invokePrivate($provider, 'fetchAnimeDetails', 42);
        $this->assertIsArray($anime);

        $descSends = array_values(array_filter(
            $transport->commands,
            static fn (string $cmd): bool => str_starts_with($cmd, 'ANIMEDESC')
        ));

        return [$anime, $descSends];
    }

    public function test_description_timeout_retries_once_and_recovers(): void
    {
        [$anime, $descSends] = $this->fetchDetailsWithDescriptions(
            'TV Series',
            [null, "233 ANIMEDESC\n0|1|Recovered blurb."]
        );

        $this->assertCount(2, $descSends, 'a timed-out ANIMEDESC must be retried exactly once');
        $this->assertSame('Recovered blurb.', $anime['description']);
        $this->assertFalse($anime['description_fetch_failed']);
    }

    public function test_both_description_attempts_failing_is_flagged_not_silent(): void
    {
        [$anime, $descSends] = $this->fetchDetailsWithDescriptions('TV Series', [null, null]);

        $this->assertCount(2, $descSends);
        $this->assertArrayNotHasKey('description', $anime);
        // The point of M-ANIDB-26: "we could not ask" is distinguishable from "no description".
        $this->assertTrue($anime['description_fetch_failed']);
    }

    public function test_server_answered_absent_description_is_not_retried(): void
    {
        [$anime, $descSends] = $this->fetchDetailsWithDescriptions('TV Series', ['503 ANIMEDESC FAILED']);

        $this->assertCount(1, $descSends, 'a definite server answer must NOT trigger a retry');
        $this->assertArrayNotHasKey('description', $anime);
        $this->assertFalse($anime['description_fetch_failed']);
    }

    public function test_mapped_output_carries_fetch_failure_flag(): void
    {
        [$provider, $transport] = $this->makeProvider();
        $transport->responses = [
            '200 SESSIONKEY LOGIN ACCEPTED',
            self::animeResponse('TV Series'),
            null,
            null,
        ];

        $anime = self::invokePrivate($provider, 'fetchAnimeDetails', 42);
        $this->assertIsArray($anime);

        $mapped = self::invokePrivate($provider, 'mapToMetadataReturn', $anime);

        $this->assertNull($mapped['overview']);
        $this->assertTrue($mapped['description_fetch_failed']);
    }

    public function test_mapper_reads_through_typed_dto_boundary(): void
    {
        [$provider, $transport] = $this->makeProvider();
        $transport->responses = [
            '200 SESSIONKEY LOGIN ACCEPTED',
            self::animeResponse('TV Series'),
            "233 ANIMEDESC\n0|1|Warp into the future.",
        ];

        $anime = self::invokePrivate($provider, 'fetchAnimeDetails', 42);
        $this->assertIsArray($anime);

        $mapped = self::invokePrivate($provider, 'mapToMetadataReturn', $anime);

        $this->assertSame('Cowboy Bebop', $mapped['title']);
        $this->assertSame('Cowboy Bebop: The Movie', $mapped['original_name']);
        $this->assertSame('Warp into the future.', $mapped['overview']);
        $this->assertSame(2001, $mapped['year']);
        $this->assertSame(8.7, $mapped['rating']);
        $this->assertSame(21000, $mapped['vote_count']);
        $this->assertSame(42, $mapped['anidb_id']);
        $this->assertSame(26, $mapped['episodes']);
        $this->assertSame('tv', $mapped['type']);
        $this->assertSame('https://cdn-eu.anidb.net/images/main/42.jpg', $mapped['poster_url']);
        $this->assertFalse($mapped['is_movie']);
        $this->assertFalse($mapped['description_fetch_failed']);
        $this->assertContains('CB', $mapped['synonyms']);
        $this->assertSame('Finished', $mapped['status']);
    }

    public function test_mapper_carries_is_movie_from_parser_through_dto(): void
    {
        [$provider, $transport] = $this->makeProvider();
        $transport->responses = ['200 SESSIONKEY LOGIN ACCEPTED', self::animeResponse('Movie')];

        $anime = self::invokePrivate($provider, 'fetchAnimeDetails', 42);
        $this->assertIsArray($anime);

        $mapped = self::invokePrivate($provider, 'mapToMetadataReturn', $anime);

        $this->assertTrue($mapped['is_movie']);
        $this->assertSame('movie', $mapped['type']);
    }
}
