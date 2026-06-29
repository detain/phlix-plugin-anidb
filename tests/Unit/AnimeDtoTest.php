<?php

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
}
