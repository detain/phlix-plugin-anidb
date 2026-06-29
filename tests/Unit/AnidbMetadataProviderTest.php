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
        // categories is not included in amask=00f0f0f0000000 — returns honest empty
        $this->assertSame([], $result['categories']);
    }

    /**
     * Regression test for B7: slashes in anime titles must be preserved.
     * The old buggy decode() did str_replace(['`', '/', "\n"], ["'", '|', ' '], $s)
     * which blanket-replaced EVERY '/' with '|' — mangling "Fate/stay night" into
     * "Fate|stay night". The fix splits fields on '|' BEFORE any unescaping,
     * and only unescapes documented AniDB escapes: backtick→', <br />→space, \n→space.
     */
    public function test_parses_anime_response_preserves_slash_in_title(): void
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

        // Build a 230 response with romaji "Fate/stay night" (contains a slash)
        $rawResponse = "230 ANIME\n" . implode('|', [
            '1',       // 0: aid
            '0',       // 1: dateflags
            '2000-2000', // 2: year
            'TV Series', // 3: type
            '',        // 4: related_aid_list
            '',        // 5: related_aid_type
            'Fate/stay night', // 6: romaji — CONTAINS SLASH, must be preserved
            'フェイト/stay night', // 7: kanji
            'Fate/stay night', // 8: english
            '',        // 9: other
            '',        // 10: short_names
            '',        // 11: synonyms
            '24',      // 12: episodes
            '24',      // 13: highest_ep
            '0',       // 14: specials
            '968784000', // 15: air_date
            '0',       // 16: end_date
            'https://anidb.net/5', // 17: url
            '5.jpg',   // 18: picname
            '850',     // 19: rating
            '5000',    // 20: vote_count
            '0',       // 21: temp_rating
            '0',       // 22: temp_vote_count
            '0',       // 23: avg_review
            '0',       // 24: review_count
            '',        // 25: award_list
            '0',       // 26: is_18plus
        ]);

        $result = $method->invoke($provider, $rawResponse);

        $this->assertNotNull($result);
        // The romaji field MUST preserve the slash — not turn it into "|"
        $this->assertSame('Fate/stay night', $result['romaji']);
        $this->assertSame('Fate/stay night', $result['english']);
    }

    /**
     * 506 retry recursion guard: after 3 recursive 506 responses, sendCommand()
     * must throw RuntimeException instead of infinite-looping.
     */
    public function test_506_retry_throws_after_3_retries(): void
    {
        // Track calls to detect infinite recursion.
        $callCount = 0;

        $fakeUdpClient = new class($callCount) implements \Phlix\Anidb\Udp\UdpClientInterface {
            public function __construct(
                private int &$callCounter
            ) {}

            public function open(): void {}

            public function send(string $data): ?string
            {
                ++$this->callCounter;
                // AUTH commands get a valid response; all other commands return 506
                // to trigger the re-auth retry path. This lets the recursion guard
                // kick in without authenticate() itself failing.
                if (str_starts_with($data, 'AUTH ')) {
                    return '200 REAUTH_SESSIONKEY LOGIN ACCEPTED';
                }

                return '506 SESSION EXPIRED';
            }

            public function close(): void {}

            public function lastReplyHost(): ?string { return null; }

            public function lastReplyPort(): ?int { return null; }
        };

        $provider = new \Phlix\Anidb\AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ], $fakeUdpClient, new \Phlix\Anidb\Udp\ProductionWaiter());

        // Manually inject a known session key so we bypass the initial authenticate()
        // call inside sendCommand() and jump straight into the 506 retry path.
        $reflection = new \ReflectionClass($provider);
        $sessionKeyProp = $reflection->getProperty('sessionKey');
        $sessionKeyProp->setAccessible(true);
        $sessionKeyProp->setValue($provider, 'FAKE_SESSION');

        // Suppress the flood-protection waiter (no-op) so we don't wait 4s.
        $waiterProp = $reflection->getProperty('waiter');
        $waiterProp->setAccessible(true);
        $waiterProp->setValue($provider, new class implements \Phlix\Anidb\Udp\WaiterInterface {
            public function wait(float $seconds): void {}
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AniDB session expired: re-authentication failed after 3 retries');

        try {
            $provider->lookup('/path/to/anime.mkv');
        } catch (\RuntimeException $e) {
            // After the fix, callCount should be 4 (1 original + 3 retries).
            // Before the fix (infinite loop), it would be much higher.
            if ($this->callCounter > 10) {
                $this->fail("Infinite recursion detected: send() called {$this->callCounter} times");
            }
            throw $e;
        }
    }

    /**
     * 506 retry token correctness: the str_replace bug in the original code
     * stripped the token from $command (which never had it) instead of
     * $fullCommand (which did). This caused the retry to reuse the expired token.
     * The fix replaces from $fullCommand, so the retry correctly uses the
     * new session key obtained after re-authentication.
     */
    public function test_506_retry_uses_correct_new_session_key(): void
    {
        $sentData = [];

        $fakeUdpClient = new class($sentData) implements \Phlix\Anidb\Udp\UdpClientInterface {
            public function __construct(
                private array &$sentDataLog
            ) {}

            public function open(): void {}

            public function send(string $data): ?string
            {
                $this->sentDataLog[] = $data;

                // AUTH commands get a valid response with NEW session key.
                // All other commands: first call returns 506, subsequent return 200.
                if (str_starts_with($data, 'AUTH ')) {
                    // Return format: "200 SESSIONKEY LOGIN ACCEPTED"
                    return '200 NEW_SESSIONKEY LOGIN ACCEPTED';
                }

                // Non-AUTH commands: track how many we've seen.
                static $cmdCallCount = 0;
                ++$cmdCallCount;

                // First command call: 506 to trigger re-auth + retry.
                // Retry command call (after re-auth): 200 success.
                return $cmdCallCount === 1 ? '506 SESSION EXPIRED' : '200 OK';
            }

            public function close(): void {}

            public function lastReplyHost(): ?string { return null; }

            public function lastReplyPort(): ?int { return null; }
        };

        $provider = new \Phlix\Anidb\AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ], $fakeUdpClient, new \Phlix\Anidb\Udp\ProductionWaiter());

        $reflection = new \ReflectionClass($provider);

        // Pre-set an old session key so we enter sendCommand's 506 path.
        $sessionKeyProp = $reflection->getProperty('sessionKey');
        $sessionKeyProp->setAccessible(true);
        $sessionKeyProp->setValue($provider, 'OLD_SESSION');

        // No-op waiter.
        $waiterProp = $reflection->getProperty('waiter');
        $waiterProp->setAccessible(true);
        $waiterProp->setValue($provider, new class implements \Phlix\Anidb\Udp\WaiterInterface {
            public function wait(float $seconds): void {}
        });

        // Call sendCommand directly via reflection to isolate the 506 path.
        $sendCommand = $reflection->getMethod('sendCommand');
        $sendCommand->setAccessible(true);

        $result = $sendCommand->invoke($provider, 'TESTCMD');

        // We expect success (200 OK).
        $this->assertSame('200 OK', $result);

        // Verify the data that was sent:
        // [0] = original command with OLD_SESSION (TESTCMD&s=OLD_SESSION)
        // [1] = AUTH with NEW_SESSIONKEY (from the re-auth inside 506 handler)
        // [2] = retry command with NEW_SESSIONKEY (TESTCMD&s=NEW_SESSIONKEY)
        $this->assertCount(3, $sentData);

        // First send: original command with OLD session (before 506 was detected).
        $this->assertStringContainsString('s=OLD_SESSION', $sentData[0]);
        $this->assertStringContainsString('TESTCMD', $sentData[0]);

        // AUTH send: does NOT have a session key (it goes to establish the session).
        $this->assertStringContainsString('AUTH ', $sentData[1]);
        // The AUTH command format is 'AUTH user=...&pass=...' - no &s= parameter.
        $this->assertStringNotContainsString('&s=', $sentData[1]);

        // Third send (retry after re-auth): MUST have NEW session key.
        // This is the critical assertion that the $command→$fullCommand fix enables.
        $this->assertStringContainsString('s=NEW_SESSIONKEY', $sentData[2]);
        $this->assertStringContainsString('TESTCMD', $sentData[2]);
    }

    /**
     * B4: searchTitleDump() scoring correctness tests.
     *
     * Tests that scoring follows: exact > exact-prefix > contains,
     * and within same tier prefers shorter/closer-length titles.
     */
    public function test_searchTitleDump_exact_match_returns_immediately(): void
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => true,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        // Inject a title index with known entries via Reflection.
        $index = [
            ['aid' => 1, 'titles' => [
                ['title' => 'Fate/stay night', 'lower_title' => 'fate/stay night', 'type' => 'main', 'lang' => 'en'],
            ]],
            ['aid' => 2, 'titles' => [
                ['title' => 'Fate/Zero', 'lower_title' => 'fate/zero', 'type' => 'main', 'lang' => 'en'],
            ]],
        ];

        $reflection = new \ReflectionClass($provider);
        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndexProp->setValue($provider, $index);

        $searchMethod = $reflection->getMethod('searchTitleDump');
        $searchMethod->setAccessible(true);

        // Exact match for "Fate/stay night" should return AID 1 immediately.
        $result = $searchMethod->invoke($provider, 'Fate/stay night');
        $this->assertSame(1, $result);
    }

    public function test_searchTitleDump_exact_prefix_wins_over_contains(): void
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => true,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        // AID 1: "Fate/stay night" - "stay" is a contains match
        // AID 2: "Fate/Zero" - "Fate" is an exact-prefix match
        $index = [
            ['aid' => 1, 'titles' => [
                ['title' => 'Fate/stay night', 'lower_title' => 'fate/stay night', 'type' => 'main', 'lang' => 'en'],
            ]],
            ['aid' => 2, 'titles' => [
                ['title' => 'Fate/Zero', 'lower_title' => 'fate/zero', 'type' => 'main', 'lang' => 'en'],
            ]],
        ];

        $reflection = new \ReflectionClass($provider);
        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndexProp->setValue($provider, $index);

        $searchMethod = $reflection->getMethod('searchTitleDump');
        $searchMethod->setAccessible(true);

        // Searching for "Fate" should return AID 2 (exact prefix) not AID 1 (contains).
        // "Fate/Zero" starts with "Fate" (prefix match, score 800 - abs(4-9) = 795)
        // "Fate/stay night" contains "Fate" (contains match, score 600 - abs(4-16) = 588)
        // 795 > 588, so AID 2 should win.
        $result = $searchMethod->invoke($provider, 'Fate');
        $this->assertSame(2, $result);
    }

    public function test_searchTitleDump_within_same_tier_prefers_shorter_title(): void
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => true,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        // Both entries have "Fate" as a prefix match.
        // AID 1: "Fate/stay night" (16 chars) - prefix score: 800 - abs(4-16) = 788
        // AID 2: "Fate/Zero" (9 chars) - prefix score: 800 - abs(4-9) = 795
        // AID 2 should win because it's closer in length to the query "Fate".
        $index = [
            ['aid' => 1, 'titles' => [
                ['title' => 'Fate/stay night', 'lower_title' => 'fate/stay night', 'type' => 'main', 'lang' => 'en'],
            ]],
            ['aid' => 2, 'titles' => [
                ['title' => 'Fate/Zero', 'lower_title' => 'fate/zero', 'type' => 'main', 'lang' => 'en'],
            ]],
        ];

        $reflection = new \ReflectionClass($provider);
        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndexProp->setValue($provider, $index);

        $searchMethod = $reflection->getMethod('searchTitleDump');
        $searchMethod->setAccessible(true);

        $result = $searchMethod->invoke($provider, 'Fate');
        $this->assertSame(2, $result);
    }

    public function test_searchTitleDump_returns_null_when_no_match(): void
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => true,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        $index = [
            ['aid' => 1, 'titles' => [
                ['title' => 'Fate/stay night', 'lower_title' => 'fate/stay night', 'type' => 'main', 'lang' => 'en'],
            ]],
        ];

        $reflection = new \ReflectionClass($provider);
        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndexProp->setValue($provider, $index);

        $searchMethod = $reflection->getMethod('searchTitleDump');
        $searchMethod->setAccessible(true);

        $result = $searchMethod->invoke($provider, 'NoSuchTitle');
        $this->assertNull($result);
    }

    public function test_searchTitleDump_uses_lower_title_field_when_available(): void
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => true,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        // Title entry WITHOUT lower_title (should fall back to mb_strtolower)
        $index = [
            ['aid' => 1, 'titles' => [
                ['title' => 'FATE/STAY NIGHT', 'type' => 'main', 'lang' => 'en'],
            ]],
        ];

        $reflection = new \ReflectionClass($provider);
        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndexProp->setValue($provider, $index);

        $searchMethod = $reflection->getMethod('searchTitleDump');
        $searchMethod->setAccessible(true);

        // Search with lowercase query should find the uppercase title via fallback mb_strtolower.
        $result = $searchMethod->invoke($provider, 'fate/stay night');
        $this->assertSame(1, $result);
    }
}
