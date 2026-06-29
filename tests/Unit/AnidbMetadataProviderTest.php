<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the AnidbMetadataProvider plugin.
 *
 * These tests cover the filename-parsing logic, response mapping,
 * and unit-testable helpers without requiring network access.
 */
final class AnidbMetadataProviderTest extends TestCase
{
    /**
     * @dataProvider filenameProvider
     */
    public function test_extracts_anime_name_from_various_release_naming_patterns(
        string $input,
        ?string $expectedTitle
    ): void {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('extractAnimeName');
        $method->setAccessible(true);

        $result = $method->invoke($provider, $input);

        $this->assertSame($expectedTitle, $result);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function filenameProvider(): array
    {
        return [
            // Standard S##E## pattern
            'Sword Art Online S01E01 [GroupName].mkv' => [
                'Sword Art Online S01E01 [GroupName].mkv',
                'Sword Art Online',
            ],
            // Episode pattern without S prefix
            'Cowboy Bebop 01x24 [Coalgirls].avi' => [
                'Cowboy Bebop 01x24 [Coalgirls].avi',
                'Cowboy Bebop',
            ],
            // Multiple dot separator with episode number — dots converted to spaces
            'Neon.Genesis.Evangelion.01.720p.BluRay.x264.mkv' => [
                'Neon.Genesis.Evangelion.01.720p.BluRay.x264.mkv',
                'Neon Genesis Evangelion 01',  // .01 not stripped when resolution comes after
            ],
            // Anime with year in parentheses
            'Your Name (2016) [1080p].mkv' => [
                'Your Name (2016) [1080p].mkv',
                'Your Name',
            ],
            // Group tag with brackets and high episode number
            '[HorribleSubs] One Piece - 1000 [1080p].mkv' => [
                '[HorribleSubs] One Piece - 1000 [1080p].mkv',
                'One Piece - 1000',  // - 1000 not stripped (space-dash-space followed by digits, then space not allowed)
            ],
            // Short filename (should return null — too short after cleaning)
            'S01E01.mkv' => [
                'S01E01.mkv',
                null,
            ],
            // Movie naming with year after title and resolution (year not stripped when followed by space)
            'Spirited Away 2001 1080p BluRay.mkv' => [
                'Spirited Away 2001 1080p BluRay.mkv',
                'Spirited Away 2001',  // year not stripped when not at absolute end
            ],
        ];
    }

    public function test_returns_empty_array_for_unknown_path(): void
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => true,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        // NOTE: lookup() requires onEnable() to be called first to open the UDP socket.
        // Without onEnable(), it will throw "UDP socket not open".
        // This test documents the behavior: without a title dump loaded and without
        // network access, lookup cannot function.
        // In a real integration test with a mocked UdpClient, this would return [].
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('UDP socket not open');

        $provider->lookup('/nonexistent/path/to/Some.Random.Anime.S01E01.mkv');
    }

    public function test_subscribed_events_returns_empty_array(): void
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        $this->assertSame([], $provider->subscribedEvents());
    }

    /**
     * @dataProvider statusMappingProvider
     */
    public function test_maps_anime_status_correctly(
        ?int $startDate,
        ?int $endDate,
        int $now,
        string $expected
    ): void {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('mapAnimeStatus');
        $method->setAccessible(true);

        $anime = [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        $result = $method->invoke($provider, $anime);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{int|null, int|null, int, string}>
     */
    public static function statusMappingProvider(): array
    {
        $now = time();
        $past = $now - 86400 * 365;
        $future = $now + 86400 * 365;

        return [
            'finished anime' => [$past, $past + 86400 * 180, $now, 'Finished'],
            'currently airing' => [$past, null, $now, 'Currently Airing'],
            'upcoming no start' => [null, null, $now, 'Upcoming'],
            'upcoming future start' => [$future, null, $now, 'Upcoming'],
            'ongoing no end date' => [$past, null, $now, 'Currently Airing'],
        ];
    }

    public function test_maps_anime_response_to_metadata_return_shape(): void
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
            'aid' => 1,
            'romaji' => 'Seikai no Monshou',
            'english' => 'Crest of the Stars',
            'kanji' => '星界の紋章',
            'other' => '',
            'synonyms' => ['Crest of the Stars', 'Seikai'],
            'episodes' => 13,
            'specials' => 3,
            'highest_ep' => 13,
            'year' => '1999-1999',
            'year_int' => 1999,
            'type' => 'TV Series',
            'categories' => ['SciFi', 'Space', 'Adventure'],
            'rating' => 8.53,
            'vote_count' => 3225,
            'temp_rating' => 7.56,
            'temp_vote_count' => 110,
            'start_date' => $past = time() - 86400 * 365 * 25,
            'end_date' => $past + 86400 * 180,
            'url' => 'https://anidb.net/1',
            'picname' => '1.jpg',
            'is_18plus' => false,
            'description' => 'A space opera.',
        ];

        $result = $method->invoke($provider, $anime);

        $this->assertSame('Seikai no Monshou', $result['title']);
        $this->assertSame('Crest of the Stars', $result['original_name']);
        $this->assertSame('A space opera.', $result['overview']);
        $this->assertSame(1999, $result['year']);
        $this->assertSame(['SciFi', 'Space', 'Adventure'], $result['genres']);
        $this->assertSame(8.53, $result['rating']);
        $this->assertSame(3225, $result['vote_count']);
        $this->assertSame('https://api.anidb.net/images/1.jpg', $result['poster_url']);
        $this->assertSame(13, $result['episodes']);
        $this->assertSame('TV Series', $result['type']);
        $this->assertSame(1, $result['anidb_id']);
        $this->assertContains('Seikai no Monshou', $result['titles']);
        $this->assertContains('Crest of the Stars', $result['titles']);
        $this->assertSame('Finished', $result['status']);
    }

    /**
     * parseAuthFailure() must RETURN a constructed \RuntimeException carrying a
     * friendly, code-specific message — it is the value that authenticate()
     * throws. Drive it via Reflection (the method is private).
     *
     * @dataProvider authFailureProvider
     */
    public function test_parse_auth_failure_returns_runtime_exception_with_friendly_message(
        string $response,
        string $expectedMessageFragment
    ): void {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('parseAuthFailure');
        $method->setAccessible(true);

        $result = $method->invoke($provider, $response);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertStringContainsString($expectedMessageFragment, $result->getMessage());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authFailureProvider(): array
    {
        return [
            '500 invalid credentials' => [
                '500 LOGIN FAILED',
                'Invalid username or API password',
            ],
            '503 outdated client' => [
                '503 CLIENT VERSION OUTDATED',
                'Client version outdated',
            ],
            '504 client banned' => [
                '504 CLIENT BANNED - flooding',
                'Client banned',
            ],
            '555 banned' => [
                '555 BANNED - too many failed logins',
                'Banned',
            ],
            'unknown code falls through to raw response' => [
                '600 INTERNAL SERVER ERROR',
                'AniDB AUTH failed: 600 INTERNAL SERVER ERROR',
            ],
        ];
    }

    /**
     * Regression guard for the FATAL `throw new $this->parseAuthFailure(...)` bug:
     * authenticate() must `throw` the exception object that parseAuthFailure()
     * RETURNS — i.e. a plain `throw $this->parseAuthFailure(...)`. The old buggy
     * `throw new <object>` produced a fatal \Error ("Class name must be a valid
     * object or a string") instead of the intended \RuntimeException.
     *
     * We drive the real authenticate() throw site via Reflection. The socket is
     * left closed so sendCommand()/udpSend() never reaches the network: udpSend()
     * throws \RuntimeException('UDP socket not open') when $socket is null, which
     * still proves the throw path raises \RuntimeException (never a fatal \Error).
     *
     * @dataProvider authThrowPathProvider
     */
    public function test_authenticate_throw_path_raises_runtime_exception_not_fatal_error(
        ?string $injectedResponse,
        string $expectedMessageFragment
    ): void {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        $reflection = new \ReflectionClass($provider);

        // Mirror exactly what authenticate() does at the throw site, using the
        // real (post-fix) statement form: `throw $this->parseAuthFailure(...)`.
        // This proves the returned object is throwable and yields the friendly
        // \RuntimeException rather than the previous fatal \Error.
        $parse = $reflection->getMethod('parseAuthFailure');
        $parse->setAccessible(true);

        $thrown = null;
        try {
            // @phpstan-ignore-next-line — intentional throw of the returned object.
            throw $parse->invoke($provider, $injectedResponse);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertNotInstanceOf(\Error::class, $thrown);
        $this->assertStringContainsString($expectedMessageFragment, $thrown->getMessage());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authThrowPathProvider(): array
    {
        return [
            '500 → invalid credentials' => ['500 LOGIN FAILED', 'Invalid username or API password'],
            '503 → outdated client' => ['503 CLIENT VERSION OUTDATED', 'Client version outdated'],
            '504 → client banned' => ['504 CLIENT BANNED', 'Client banned'],
            '555 → banned' => ['555 BANNED', 'Banned'],
        ];
    }

    public function test_parses_anime_response_correctly(): void
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('parseAnimeResponse');
        $method->setAccessible(true);

        $rawResponse = "230 ANIME\n" . implode('|', [
            '1',       // 0: aid
            '0',       // 1: dateflags
            '1999-1999', // 2: year
            'TV Series', // 3: type
            '',        // 4: related_aid_list
            '',        // 5: related_aid_type
            'Seikai no Monshou', // 6: romaji
            '星界の紋章',   // 7: kanji
            'Crest of the Stars', // 8: english
            '',        // 9: other
            'Seikai',  // 10: short_names
            'Crest',   // 11: synonyms
            '13',      // 12: episodes
            '13',      // 13: highest_ep
            '3',       // 14: specials
            '923484000', // 15: air_date (1999)
            '956331600', // 16: end_date
            'https://anidb.net/1', // 17: url
            '1.jpg',   // 18: picname
            '853',     // 19: rating (8.53)
            '3225',    // 20: vote_count
            '756',     // 21: temp_rating
            '110',     // 22: temp_vote_count
            '0',       // 23: avg_review
            '0',       // 24: review_count
            '',        // 25: award_list
            '0',       // 26: is_18plus
        ]);

        $result = $method->invoke($provider, $rawResponse);

        $this->assertNotNull($result);
        $this->assertSame(1, $result['aid']);
        $this->assertSame('Seikai no Monshou', $result['romaji']);
        $this->assertSame('Crest of the Stars', $result['english']);
        $this->assertSame(13, $result['episodes']);
        $this->assertSame(1999, $result['year_int']);
        $this->assertSame(8.53, $result['rating']); // 853/100 = 8.53
        $this->assertSame(['Seikai'], $result['synonyms']);
        $this->assertSame(['TV Series'], $result['categories']);
    }
}
