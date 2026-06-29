<?php

declare(strict_types=1);

namespace Phlix\Anidb\Dto;

/**
 * Data Transfer Object representing parsed anime data from AniDB.
 *
 * Captures the output shape of {@see \Phlix\Anidb\AnimeResponseParser::parseAnimeResponse()}.
 *
 * @package Phlix\Anidb\Dto
 */
readonly class AnimeDto
{
    /**
     * @param int $aid AniDB anime ID.
     * @param string $romaji Romanized title.
     * @param string $english Official English title.
     * @param string $kanji Japanese title.
     * @param string $other Other title variant.
     * @param list<string> $synonyms List of synonym titles.
     * @param int $episodes Total episode count.
     * @param int $specials Total special count.
     * @param int $highest_ep Highest episode number.
     * @param string $year Year string (e.g. "1999" or "1999-2000").
     * @param int|null $year_int Parsed integer year or null.
     * @param string $type Anime type (e.g. "TV Series", "Movie", "OVA").
     * @param list<string> $categories Always empty per amask=00f0f0f0000000.
     * @param float|null $rating Weighted rating (0.0-10.0 scale).
     * @param int $vote_count Number of votes.
     * @param float|null $temp_rating Temporary rating (0.0-10.0 scale).
     * @param int $temp_vote_count Number of temporary votes.
     * @param int|null $start_date Start date as Unix timestamp or null.
     * @param int|null $end_date End date as Unix timestamp or null.
     * @param string $url AniDB URL.
     * @param string $picname Poster image filename.
     * @param bool $is_18plus Whether the anime is 18+ restricted.
     */
    public function __construct(
        public int $aid,
        public string $romaji,
        public string $english,
        public string $kanji,
        public string $other,
        public array $synonyms,
        public int $episodes,
        public int $specials,
        public int $highest_ep,
        public string $year,
        public ?int $year_int,
        public string $type,
        public array $categories,
        public ?float $rating,
        public int $vote_count,
        public ?float $temp_rating,
        public int $temp_vote_count,
        public ?int $start_date,
        public ?int $end_date,
        public string $url,
        public string $picname,
        public bool $is_18plus,
    ) {
    }

    /**
     * Construct an AnimeDto from the array shape returned by AnimeResponseParser.
     *
     * @param array<string, mixed> $data Parsed fields from AnimeResponseParser::parseAnimeResponse().
     *
     * @return self
     */
    public static function fromParsedArray(array $data): self
    {
        return new self(
            aid: self::toInt($data['aid'] ?? null),
            romaji: self::toString($data['romaji'] ?? null),
            english: self::toString($data['english'] ?? null),
            kanji: self::toString($data['kanji'] ?? null),
            other: self::toString($data['other'] ?? null),
            synonyms: self::toListOfStrings($data['synonyms'] ?? null),
            episodes: self::toInt($data['episodes'] ?? null),
            specials: self::toInt($data['specials'] ?? null),
            highest_ep: self::toInt($data['highest_ep'] ?? null),
            year: self::toString($data['year'] ?? null),
            year_int: self::toNullableInt($data['year_int'] ?? null),
            type: self::toString($data['type'] ?? null),
            categories: self::toListOfStrings($data['categories'] ?? null),
            rating: self::toNullableFloat($data['rating'] ?? null),
            vote_count: self::toInt($data['vote_count'] ?? null),
            temp_rating: self::toNullableFloat($data['temp_rating'] ?? null),
            temp_vote_count: self::toInt($data['temp_vote_count'] ?? null),
            start_date: self::toNullableInt($data['start_date'] ?? null),
            end_date: self::toNullableInt($data['end_date'] ?? null),
            url: self::toString($data['url'] ?? null),
            picname: self::toString($data['picname'] ?? null),
            is_18plus: self::toBool($data['is_18plus'] ?? null),
        );
    }

    /**
     * @param mixed $value
     */
    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        return 0;
    }

    /**
     * @param mixed $value
     */
    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        return '';
    }

    /**
     * @param mixed $value
     */
    private static function toNullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        return null;
    }

    /**
     * @param mixed $value
     */
    private static function toNullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_string($value) && $value !== '') {
            return (float) $value;
        }
        return null;
    }

    /**
     * @param mixed $value
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return $value !== '' && $value !== '0' && mb_strtolower($value) !== 'false';
        }
        return false;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function toListOfStrings(mixed $value): array
    {
        /** @var list<string> */
        $result = [];
        if (!is_array($value)) {
            return $result;
        }
        foreach ($value as $item) {
            if (is_string($item)) {
                $result[] = $item;
            } elseif (is_int($item) || is_float($item) || is_bool($item)) {
                $result[] = (string) $item;
            }
        }
        return $result;
    }
}
