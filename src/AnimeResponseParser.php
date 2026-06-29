<?php

declare(strict_types=1);

namespace Phlix\Anidb;

/**
 * Parses raw AniDB UDP anime response data into structured arrays.
 *
 * Handles the 230 ANIME response format with mask 00f0f0f0000000:
 * - Byte 1: aid, dateflags, year, type, related_aid_list, related_aid_type
 * - Byte 2: romaji, kanji, english, other, short_names, synonyms
 * - Byte 3: episodes, highest_ep, specials, air_date, end_date, url, picname
 * - Byte 4: rating, vote_count, temp_rating, temp_vote_count, avg_review, review_count, award_list, is_18+
 *
 * Escapes documented AniDB sequences:
 * - ` → ' (backtick to single quote)
 * - <br /> → space (line break in multi-line fields)
 * - \n → space (literal newline)
 *
 * @package Phlix\Anidb
 */
final class AnimeResponseParser
{
    /**
     * Decode escaped characters per AniDB spec.
     *
     * @param string $s Raw field string.
     *
     * @return string Decoded string.
     */
    private static function decode(string $s): string
    {
        return str_replace(["`", "<br />", "\n"], ["'", ' ', ' '], $s);
    }

    /**
     * Parse a 230 ANIME response into a structured array.
     *
     * @param string $raw Raw response string.
     *
     * @return array<string, mixed>|null Parsed fields or null on parse failure.
     */
    public function parseAnimeResponse(string $raw): ?array
    {
        $lines = explode("\n", trim($raw), 2);
        if (count($lines) < 2) {
            return null;
        }

        $fields = explode('|', $lines[1]);

        // Based on amask=00f0f0f0000000 (bytes 1-4):
        // Byte 1: aid(8)|dateflags(8)|year(8)|type(8)|related_aid_list(8)|related_aid_type(8)
        // Byte 2: romaji(8)|kanji(8)|english(8)|other(8)|short_names(8)|synonyms(8)
        // Byte 3: episodes(8)|highest_ep(8)|specials(8)|air_date(8)|end_date(8)|url(8)|picname(8)
        // Byte 4: rating(8)|vote_count(8)|temp_rating(8)|temp_vote_count(8)|avg_review(8)|review_count(8)|award_list(8)|is_18+(8)

        // Field order with amask=00f0f0f0000000:
        // 0: aid, 1: dateflags, 2: year, 3: type, 4: related_aid_list, 5: related_aid_type,
        // 6: romaji, 7: kanji, 8: english, 9: other, 10: short_names, 11: synonyms,
        // 12: episodes, 13: highest_ep, 14: specials, 15: air_date, 16: end_date, 17: url, 18: picname,
        // 19: rating, 20: vote_count, 21: temp_rating, 22: temp_vote_count, 23: avg_review, 24: review_count, 25: award_list, 26: is_18+

        if (count($fields) < 27) {
            return null;
        }

        return $this->buildAnimeFromFields($fields);
    }

    /**
     * Build the anime array from parsed fields.
     *
     * @param list<string> $fields Parsed field values.
     *
     * @return array<string, mixed> Anime data array.
     */
    private function buildAnimeFromFields(array $fields): array
    {
        // categories/tags are not included in amask=00f0f0f0000000 — set honest empty
        $categories = [];

        // Parse year: "1999-2000" or "1999"
        $yearStr = self::decode($fields[2]);
        $year = null;
        if ($yearStr !== '' && $yearStr !== '0000') {
            $year = (int)explode('-', $yearStr)[0];
            if ($year === 0) {
                $year = null;
            }
        }

        // AniDB rating is stored as e.g. "825" meaning 8.25
        $rating = (int)$fields[19];
        $ratingFloat = $rating > 0 ? $rating / 100 : null;

        $anime = [
            'aid'            => (int)$fields[0],
            'romaji'         => self::decode($fields[6]),
            'english'        => self::decode($fields[8]),
            'kanji'          => self::decode($fields[7]),
            'other'          => self::decode($fields[9]),
            'synonyms'       => array_filter(array_map('trim', explode(',', self::decode($fields[10])))),
            'episodes'       => (int)$fields[12],
            'specials'       => (int)$fields[14],
            'highest_ep'     => (int)$fields[13],
            'year'           => $yearStr,
            'year_int'       => $year,
            'type'           => self::decode($fields[3]),
            'categories'     => $categories,
            'rating'         => $ratingFloat,
            'vote_count'     => (int)$fields[20],
            'temp_rating'    => ((int)$fields[21]) / 100,
            'temp_vote_count'=> (int)$fields[22],
            'start_date'     => (int)$fields[15] ?: null,
            'end_date'       => (int)$fields[16] ?: null,
            'url'            => 'https://anidb.net/' . $fields[0],
            'picname'        => self::decode($fields[18]),
            'is_18plus'      => (int)$fields[26] === 1,
        ];

        return $anime;
    }
}
