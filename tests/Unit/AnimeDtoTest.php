<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Dto\AnimeDto;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AnimeDto.
 */
final class AnimeDtoTest extends TestCase
{
    public function test_from_parsed_array_constructs_all_fields(): void
    {
        $data = [
            'aid' => 12345,
            'romaji' => 'Cowboy Bebop',
            'english' => 'Cowboy Bebop',
            'kanji' => 'カウボーイビバップ',
            'other' => ' Bebop',
            'synonyms' => ['Bebop', 'Cowboy'],
            'episodes' => 26,
            'specials' => 5,
            'highest_ep' => 26,
            'year' => '1998-1999',
            'year_int' => 1998,
            'type' => 'TV Series',
            'categories' => [],
            'rating' => 8.75,
            'vote_count' => 45000,
            'temp_rating' => 8.5,
            'temp_vote_count' => 200,
            'start_date' => 889926000,
            'end_date' => 915402000,
            'url' => 'https://anidb.net/12345',
            'picname' => '12345.jpg',
            'is_18plus' => false,
        ];

        $dto = AnimeDto::fromParsedArray($data);

        $this->assertSame(12345, $dto->aid);
        $this->assertSame('Cowboy Bebop', $dto->romaji);
        $this->assertSame('Cowboy Bebop', $dto->english);
        $this->assertSame('カウボーイビバップ', $dto->kanji);
        $this->assertSame(' Bebop', $dto->other);
        $this->assertSame(['Bebop', 'Cowboy'], $dto->synonyms);
        $this->assertSame(26, $dto->episodes);
        $this->assertSame(5, $dto->specials);
        $this->assertSame(26, $dto->highest_ep);
        $this->assertSame('1998-1999', $dto->year);
        $this->assertSame(1998, $dto->year_int);
        $this->assertSame('TV Series', $dto->type);
        $this->assertSame([], $dto->categories);
        $this->assertSame(8.75, $dto->rating);
        $this->assertSame(45000, $dto->vote_count);
        $this->assertSame(8.5, $dto->temp_rating);
        $this->assertSame(200, $dto->temp_vote_count);
        $this->assertSame(889926000, $dto->start_date);
        $this->assertSame(915402000, $dto->end_date);
        $this->assertSame('https://anidb.net/12345', $dto->url);
        $this->assertSame('12345.jpg', $dto->picname);
        $this->assertFalse($dto->is_18plus);
    }

    public function test_from_parsed_array_handles_nullable_fields(): void
    {
        $data = [
            'aid' => 1,
            'romaji' => '',
            'english' => '',
            'kanji' => '',
            'other' => '',
            'synonyms' => [],
            'episodes' => 0,
            'specials' => 0,
            'highest_ep' => 0,
            'year' => '0000',
            'year_int' => null,
            'type' => '',
            'categories' => [],
            'rating' => null,
            'vote_count' => 0,
            'temp_rating' => null,
            'temp_vote_count' => 0,
            'start_date' => null,
            'end_date' => null,
            'url' => '',
            'picname' => '',
            'is_18plus' => false,
        ];

        $dto = AnimeDto::fromParsedArray($data);

        $this->assertSame(1, $dto->aid);
        $this->assertSame('', $dto->romaji);
        $this->assertNull($dto->year_int);
        $this->assertNull($dto->rating);
        $this->assertNull($dto->temp_rating);
        $this->assertNull($dto->start_date);
        $this->assertNull($dto->end_date);
    }

    public function test_from_parsed_array_uses_defaults_for_missing_fields(): void
    {
        $dto = AnimeDto::fromParsedArray([]);

        $this->assertSame(0, $dto->aid);
        $this->assertSame('', $dto->romaji);
        $this->assertSame('', $dto->english);
        $this->assertSame('', $dto->kanji);
        $this->assertSame('', $dto->other);
        $this->assertSame([], $dto->synonyms);
        $this->assertSame(0, $dto->episodes);
        $this->assertSame(0, $dto->specials);
        $this->assertSame(0, $dto->highest_ep);
        $this->assertSame('', $dto->year);
        $this->assertNull($dto->year_int);
        $this->assertSame('', $dto->type);
        $this->assertSame([], $dto->categories);
        $this->assertNull($dto->rating);
        $this->assertSame(0, $dto->vote_count);
        $this->assertNull($dto->temp_rating);
        $this->assertSame(0, $dto->temp_vote_count);
        $this->assertNull($dto->start_date);
        $this->assertNull($dto->end_date);
        $this->assertSame('', $dto->url);
        $this->assertSame('', $dto->picname);
        $this->assertFalse($dto->is_18plus);
    }

    public function test_from_parsed_array_casts_types_correctly(): void
    {
        $data = [
            'aid' => '12345',
            'episodes' => '26',
            'rating' => '875',
            'is_18plus' => 1,
            'synonyms' => ['Bebop', 'Cowboy'],
            'categories' => ['Action', 'Sci-Fi'],
            'year_int' => '1998',
            'temp_rating' => '850',
        ];

        $dto = AnimeDto::fromParsedArray($data);

        $this->assertSame(12345, $dto->aid);
        $this->assertSame(26, $dto->episodes);
        $this->assertSame(875.0, $dto->rating);
        $this->assertTrue($dto->is_18plus);
        $this->assertSame(['Bebop', 'Cowboy'], $dto->synonyms);
        $this->assertSame(['Action', 'Sci-Fi'], $dto->categories);
        $this->assertSame(1998, $dto->year_int);
        $this->assertSame(850.0, $dto->temp_rating);
    }

    public function test_readonly_property_access(): void
    {
        $data = [
            'aid' => 100,
            'romaji' => 'Test Anime',
            'english' => '',
            'kanji' => '',
            'other' => '',
            'synonyms' => [],
            'episodes' => 12,
            'specials' => 1,
            'highest_ep' => 12,
            'year' => '2020',
            'year_int' => 2020,
            'type' => 'TV',
            'categories' => [],
            'rating' => 7.5,
            'vote_count' => 1000,
            'temp_rating' => 7.0,
            'temp_vote_count' => 50,
            'start_date' => null,
            'end_date' => null,
            'url' => 'https://anidb.net/100',
            'picname' => '100.jpg',
            'is_18plus' => false,
        ];

        $dto = AnimeDto::fromParsedArray($data);

        $this->assertSame(100, $dto->aid);
        $this->assertSame('Test Anime', $dto->romaji);
        $this->assertSame(12, $dto->episodes);
        $this->assertSame(7.5, $dto->rating);
    }

    /**
     * Test toBool edge cases via is_18plus field.
     */
    public function test_from_parsed_array_handles_is_18plus_variations(): void
    {
        // Test integer 0 -> false
        $dto = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => 0,
        ]);
        $this->assertFalse($dto->is_18plus);

        // Test integer 1 -> true
        $dto2 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => 1,
        ]);
        $this->assertTrue($dto2->is_18plus);

        // Test string '0' -> false
        $dto3 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => '0',
        ]);
        $this->assertFalse($dto3->is_18plus);

        // Test string '1' -> true
        $dto4 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => '1',
        ]);
        $this->assertTrue($dto4->is_18plus);

        // Test string 'false' (case insensitive) -> false
        $dto5 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => 'false',
        ]);
        $this->assertFalse($dto5->is_18plus);

        // Test empty string -> false
        $dto6 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => '',
        ]);
        $this->assertFalse($dto6->is_18plus);

        // Test bool false
        $dto7 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertFalse($dto7->is_18plus);

        // Test bool true
        $dto8 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => true,
        ]);
        $this->assertTrue($dto8->is_18plus);
    }

    /**
     * Test toListOfStrings edge cases via synonyms and categories fields.
     */
    public function test_from_parsed_array_handles_list_of_strings_variations(): void
    {
        // Test non-array input -> empty array
        $dto = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => 'not an array', 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => 'also not array',
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertSame([], $dto->synonyms);
        $this->assertSame([], $dto->categories);

        // Test array with mixed types (int, float, bool should be cast to string)
        $dto2 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [123, 45.67, true, 'text'],
            'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false, 'studios' => [1, 'two', 3.0],
        ]);
        $this->assertSame(['123', '45.67', '1', 'text'], $dto2->synonyms);
        $this->assertSame(['1', 'two', '3'], $dto2->studios);

        // Test empty array
        $dto3 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertSame([], $dto3->synonyms);
        $this->assertSame([], $dto3->categories);
    }

    /**
     * Test toNullableString edge cases via source field.
     */
    public function test_from_parsed_array_handles_source_nullable_string_variations(): void
    {
        // Test null -> null
        $dto = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false, 'source' => null,
        ]);
        $this->assertNull($dto->source);

        // Test empty string -> null
        $dto2 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false, 'source' => '',
        ]);
        $this->assertNull($dto2->source);

        // Test non-empty string -> the string
        $dto3 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false, 'source' => 'manga',
        ]);
        $this->assertSame('manga', $dto3->source);

        // Test non-string input -> null
        $dto4 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false, 'source' => 12345,
        ]);
        $this->assertNull($dto4->source);
    }

    /**
     * Test toNullableFloat edge cases via rating field.
     */
    public function test_from_parsed_array_handles_nullable_float_variations(): void
    {
        // Test null -> null
        $dto = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertNull($dto->rating);

        // Test int -> float
        $dto2 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => 8, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertSame(8.0, $dto2->rating);

        // Test non-empty string -> float
        $dto3 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => '875', 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertSame(875.0, $dto3->rating);

        // Test empty string -> null
        $dto4 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => '', 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertNull($dto4->rating);
    }

    /**
     * Test toNullableInt edge cases via year_int and start_date fields.
     */
    public function test_from_parsed_array_handles_nullable_int_variations(): void
    {
        // Test null -> null
        $dto = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertNull($dto->year_int);
        $this->assertNull($dto->start_date);

        // Test non-empty string -> int
        $dto2 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => '2024', 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => '1704067200', 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertSame(2024, $dto2->year_int);
        $this->assertSame(1704067200, $dto2->start_date);

        // Test float -> int
        $dto3 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => 2024.9, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => 1704067200.5, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertSame(2024, $dto3->year_int);
        $this->assertSame(1704067200, $dto3->start_date);
    }

    /**
     * Test toString edge cases via romaji, english, kanji, other fields.
     */
    public function test_from_parsed_array_handles_to_string_variations(): void
    {
        // Test int to string
        $dto = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => 12345, 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertSame('12345', $dto->romaji);

        // Test float to string
        $dto2 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => 12.34, 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertSame('12.34', $dto2->romaji);

        // Test bool to string
        $dto3 = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => true, 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertSame('1', $dto3->romaji);
    }

    /**
     * Test studios field default value (empty array when not provided).
     */
    public function test_from_parsed_array_studios_defaults_to_empty_array(): void
    {
        $dto = AnimeDto::fromParsedArray([
            'aid' => 1, 'romaji' => '', 'english' => '', 'kanji' => '', 'other' => '',
            'synonyms' => [], 'episodes' => 0, 'specials' => 0, 'highest_ep' => 0,
            'year' => '', 'year_int' => null, 'type' => '', 'categories' => [],
            'rating' => null, 'vote_count' => 0, 'temp_rating' => null, 'temp_vote_count' => 0,
            'start_date' => null, 'end_date' => null, 'url' => '', 'picname' => '',
            'is_18plus' => false,
        ]);
        $this->assertSame([], $dto->studios);
    }
}
