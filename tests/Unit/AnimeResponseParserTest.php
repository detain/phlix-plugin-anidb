<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnimeResponseParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AnimeResponseParser.
 *
 * Tests the AniDB 230 ANIME response parsing logic.
 */
final class AnimeResponseParserTest extends TestCase
{
    private AnimeResponseParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AnimeResponseParser();
    }

    public function test_parse_returns_null_for_invalid_response(): void
    {
        $this->assertNull($this->parser->parseAnimeResponse('230 ANIME'));
        $this->assertNull($this->parser->parseAnimeResponse('not a valid response'));
        $this->assertNull($this->parser->parseAnimeResponse(''));
    }

    public function test_parse_returns_null_for_insufficient_fields(): void
    {
        $response = "230 ANIME\n" . implode('|', array_fill(0, 20, 'value'));
        $this->assertNull($this->parser->parseAnimeResponse($response));
    }

    public function test_parse_extracts_basic_fields(): void
    {
        $response = $this->buildFullResponse([
            'aid' => '12345',
            'romaji' => 'Test Anime',
            'english' => 'Test Anime English',
            'kanji' => 'テストアニメ',
            'type' => 'TV Series',
            'episodes' => '12',
            'rating' => '850', // 8.50
            'year' => '2020-2021',
        ]);

        $result = $this->parser->parseAnimeResponse($response);

        $this->assertNotNull($result);
        $this->assertSame(12345, $result['aid']);
        $this->assertSame('Test Anime', $result['romaji']);
        $this->assertSame('Test Anime English', $result['english']);
        $this->assertSame('テストアニメ', $result['kanji']);
        $this->assertSame('TV Series', $result['type']);
        $this->assertSame(12, $result['episodes']);
        $this->assertSame(8.50, $result['rating']);
    }

    public function test_parse_handles_year_parsing(): void
    {
        // Year with dash: "1999-2000"
        $response = $this->buildFullResponse(['year' => '1999-2000', 'year_str' => '1999-2000']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertSame(1999, $result['year_int']);

        // Single year: "1999"
        $response = $this->buildFullResponse(['year' => '1999', 'year_str' => '1999']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertSame(1999, $result['year_int']);

        // Zero year: "0000"
        $response = $this->buildFullResponse(['year' => '0000', 'year_str' => '0000']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertNull($result['year_int']);

        // Empty year
        $response = $this->buildFullResponse(['year' => '', 'year_str' => '']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertNull($result['year_int']);
    }

    public function test_parse_decodes_escaped_characters(): void
    {
        // AniDB escapes: ` → '  <br /> → space  \n → space
        $response = $this->buildFullResponse([
            'romaji' => "Fate`stay night",
            'english' => "Anime<br />Series",
            'kanji' => "Test\nName",
        ]);

        $result = $this->parser->parseAnimeResponse($response);

        $this->assertSame("Fate'stay night", $result['romaji']);
        $this->assertSame('Anime Series', $result['english']);
        $this->assertSame('Test Name', $result['kanji']);
    }

    public function test_parse_preserves_slash_in_title(): void
    {
        // B7 regression test: slashes must NOT be replaced with |
        $response = $this->buildFullResponse([
            'romaji' => 'Fate/stay night',
            'english' => 'Fate/stay night',
        ]);

        $result = $this->parser->parseAnimeResponse($response);

        $this->assertSame('Fate/stay night', $result['romaji']);
        $this->assertSame('Fate/stay night', $result['english']);
        // Should NOT contain pipe character from incorrect unescape
        $this->assertStringNotContainsString('|', $result['romaji']);
    }

    public function test_parse_parses_synonyms_as_comma_separated(): void
    {
        $response = $this->buildFullResponse([
            'short_names' => 'Short Name 1, Short Name 2,Short Name 3',
            'synonyms' => 'Synonym 1,Synonym 2',
        ]);

        $result = $this->parser->parseAnimeResponse($response);

        $this->assertContains('Short Name 1', $result['synonyms']);
        $this->assertContains('Short Name 2', $result['synonyms']);
        $this->assertContains('Short Name 3', $result['synonyms']);
    }

    public function test_parse_rating_calculation(): void
    {
        // AniDB rating: 825 = 8.25
        $response = $this->buildFullResponse(['rating' => '825']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertEqualsWithDelta(8.25, $result['rating'], 0.001);

        // Zero rating
        $response = $this->buildFullResponse(['rating' => '0']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertNull($result['rating']);

        // Very high rating: 1000 = 10.00
        $response = $this->buildFullResponse(['rating' => '1000']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertEqualsWithDelta(10.00, $result['rating'], 0.001);
    }

    public function test_parse_temp_rating_calculation(): void
    {
        // Temp rating stored as integer e.g. 756 = 7.56
        $response = $this->buildFullResponse(['temp_rating' => '756']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertSame(7.56, $result['temp_rating']);
    }

    public function test_parse_detects_movie_from_type(): void
    {
        // Movie type → is_movie = true
        $response = $this->buildFullResponse(['type' => 'Movie', 'episodes' => '1']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertTrue($result['is_movie']);

        // Music Video type → is_movie = true
        $response = $this->buildFullResponse(['type' => 'Music Video', 'episodes' => '1']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertTrue($result['is_movie']);
    }

    public function test_parse_detects_not_movie_for_series(): void
    {
        // TV Series → is_movie = false
        $response = $this->buildFullResponse(['type' => 'TV Series', 'episodes' => '12']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertFalse($result['is_movie']);

        // OVA → is_movie = false
        $response = $this->buildFullResponse(['type' => 'OVA', 'episodes' => '6']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertFalse($result['is_movie']);

        // ONA → is_movie = false
        $response = $this->buildFullResponse(['type' => 'ONA', 'episodes' => '12']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertFalse($result['is_movie']);

        // Special → is_movie = false
        $response = $this->buildFullResponse(['type' => 'Special', 'episodes' => '1']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertFalse($result['is_movie']);
    }

    public function test_parse_heuristic_movie_for_single_episode_unknown_type(): void
    {
        // When type is empty/unknown and episodes is 1, treat as likely movie
        $response = $this->buildFullResponse(['type' => '', 'episodes' => '1']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertTrue($result['is_movie']);
    }

    public function test_parse_18_plus_flag(): void
    {
        $response = $this->buildFullResponse(['is_18plus' => '1']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertTrue($result['is_18plus']);

        $response = $this->buildFullResponse(['is_18plus' => '0']);
        $result = $this->parser->parseAnimeResponse($response);
        $this->assertFalse($result['is_18plus']);
    }

    public function test_parse_date_fields(): void
    {
        // Unix timestamp for dates
        $response = $this->buildFullResponse([
            'air_date' => '923484000', // 1999
            'end_date' => '956331600', // 2000
        ]);

        $result = $this->parser->parseAnimeResponse($response);

        $this->assertSame(923484000, $result['start_date']);
        $this->assertSame(956331600, $result['end_date']);
    }

    public function test_parse_categories_are_empty_by_default(): void
    {
        // The basic amask doesn't include categories
        $response = $this->buildFullResponse([]);
        $result = $this->parser->parseAnimeResponse($response);

        $this->assertSame([], $result['categories']);
    }

    public function test_parse_studios_are_empty_by_default(): void
    {
        $response = $this->buildFullResponse([]);
        $result = $this->parser->parseAnimeResponse($response);

        $this->assertSame([], $result['studios']);
    }

    public function test_parse_source_is_null_by_default(): void
    {
        $response = $this->buildFullResponse([]);
        $result = $this->parser->parseAnimeResponse($response);

        $this->assertNull($result['source']);
    }

    public function test_parse_generates_correct_url(): void
    {
        $response = $this->buildFullResponse(['aid' => '12345']);
        $result = $this->parser->parseAnimeResponse($response);

        $this->assertSame('https://anidb.net/12345', $result['url']);
    }

    /**
     * Build a full 230 ANIME response with all 27 fields.
     */
    private function buildFullResponse(array $overrides = []): string
    {
        $fields = [
            'aid' => '1',
            'dateflags' => '0',
            'year' => '2020',
            'type' => 'TV Series',
            'related_aid_list' => '',
            'related_aid_type' => '',
            'romaji' => 'Default Romaji',
            'kanji' => 'デフォルト',
            'english' => 'Default English',
            'other' => '',
            'short_names' => '',
            'synonyms' => '',
            'episodes' => '12',
            'highest_ep' => '12',
            'specials' => '0',
            'air_date' => '0',
            'end_date' => '0',
            'url' => 'https://anidb.net/1',
            'picname' => '1.jpg',
            'rating' => '0',
            'vote_count' => '0',
            'temp_rating' => '0',
            'temp_vote_count' => '0',
            'avg_review' => '0',
            'review_count' => '0',
            'award_list' => '',
            'is_18plus' => '0',
        ];

        // Apply field name mappings for override keys
        $fieldMappings = [
            'year_str' => 'year',
        ];

        foreach ($overrides as $key => $value) {
            $fieldKey = $fieldMappings[$key] ?? $key;
            if (isset($fields[$fieldKey])) {
                $fields[$fieldKey] = (string) $value;
            }
        }

        return "230 ANIME\n" . implode('|', array_values($fields));
    }
}
