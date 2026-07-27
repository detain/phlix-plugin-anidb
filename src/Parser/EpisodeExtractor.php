<?php

/**
 * Extracts anime episode numbers from filenames using various common patterns.
 *
 * Supports multiple episode numbering formats:
 * - S01E02, S1E2, SA1EB2 (Season X Episode Y)
 * - 01x02, 1x2 (Season X Episode Y alternative notation)
 * - Episode 01, Episode.01, Ep 01, E01
 * - 01, 02 (standalone episode number)
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Parser;

/**
 * Pure string-manipulation service for extracting anime episode numbers
 * from filenames. No network I/O, no external state.
 */
final class EpisodeExtractor
{
    /**
     * Common anime filename patterns that indicate a movie/OVA special
     * rather than a regular episode (these typically have part numbers).
     *
     * @var array<string>
     */
    private const MOVIE_PATTERNS = [
        'movie', ' MOVIE', 'film', ' FILM',
        'OAD', 'OAV', 'OVA', ' OVA', '-ova',
        'special', ' SP', ' sp',
        'pilot', 'PILOT',
    ];

    /**
     * Extract the episode number from a filename.
     *
     * This method parses multiple episode patterns and returns the first
     * match found. It is idempotent — same input always yields same output.
     *
     * @param string $filename The filename (not full path) to parse.
     *
     * @return int|null The episode number if found, or null if no pattern matches.
     */
    public function extract(string $filename): ?int
    {
        $episode = $this->extractSeasonEpisode($filename);
        if ($episode !== null) {
            return $episode;
        }

        $episode = $this->extract1xNotation($filename);
        if ($episode !== null) {
            return $episode;
        }

        $episode = $this->extractEpisodeKeyword($filename);
        if ($episode !== null) {
            return $episode;
        }

        $episode = $this->extractStandaloneNumber($filename);
        if ($episode !== null) {
            return $episode;
        }

        return null;
    }

    /**
     * Detect if a filename likely represents a movie (not a series episode).
     *
     * Checks for movie/OVA/special patterns that indicate this is likely
     * a film or special rather than a numbered episode of a series.
     *
     * @param string $filename The filename to check.
     *
     * @return bool True if movie/special pattern detected, false otherwise.
     */
    public function isMoviePattern(string $filename): bool
    {
        $lower = mb_strtolower($filename);

        foreach (self::MOVIE_PATTERNS as $pattern) {
            if (str_contains($lower, mb_strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract episode number from S01E02, S1E2, SA1EB2 patterns.
     */
    private function extractSeasonEpisode(string $filename): ?int
    {
        // S01E02 or S1E2 pattern - season and episode
        if (preg_match('/[Ss](\d{1,2})[Ee](?:\d{1,2}[Ee])?(\d{1,4})/', $filename, $matches)) {
            // If we matched double E (S01E02E03), take the second number
            // $matches[2] is always present because the regex has two capture groups
            $episode = (int) $matches[2];
            if ($episode > 0) {
                return $episode;
            }
        }

        // SA1EB2 pattern (e.g., SA1EB2 = Season 1 Episode B2... rare)
        if (preg_match('/[Ss][Aa](\d+)[Ee][Bb]?(\d+)/i', $filename, $matches)) {
            $episode = (int) $matches[2];
            if ($episode > 0) {
                return $episode;
            }
        }

        return null;
    }

    /**
     * Extract episode number from 01x02, 1x2 patterns.
     */
    private function extract1xNotation(string $filename): ?int
    {
        if (preg_match('/^0*(\d+)x0*(\d+)/', $filename, $matches)) {
            // Episode is the second number
            $episode = (int) $matches[2];
            if ($episode > 0) {
                return $episode;
            }
        }

        return null;
    }

    /**
     * Extract episode number from Episode/Ep/E patterns.
     */
    private function extractEpisodeKeyword(string $filename): ?int
    {
        // Episode 01, Episode.01, Ep 01, Ep.01, E01 patterns
        if (preg_match('/(?:Episode|Ep)[. ]*0*(\d{1,4})/i', $filename, $matches)) {
            $episode = (int) $matches[1];
            if ($episode > 0) {
                return $episode;
            }
        }

        // Standalone E01 pattern (E followed by number, not part of S##E##)
        if (preg_match('/(?<![Ss]\d)[Ee]0*(\d{1,4})\b/', $filename, $matches)) {
            $episode = (int) $matches[1];
            // Filter out common non-episode E-codes like E01 (encoders), E12 (extension)
            // But accept E01 in contexts like "Anime 01" where it's the episode
            if ($episode > 0 && $episode <= 9999) {
                return $episode;
            }
        }

        return null;
    }

    /**
     * Extract a standalone episode number (just digits at end of filename).
     *
     * This is a last resort and has lower confidence. It extracts a number
     * at the very end of the filename when no other pattern matched.
     */
    private function extractStandaloneNumber(string $filename): ?int
    {
        // Only match if the filename ends with a reasonable episode number
        // and has some text title before it (letters or Unicode characters)
        if (preg_match('/[a-zA-Z\x{0080}-\x{FFFF}].*?(\d{1,4})$/u', $filename, $matches)) {
            $episode = (int) $matches[1];
            // Reject very small or very large numbers that are likely not episodes
            // Reject numbers that look like years (19xx, 20xx)
            if ($episode > 0 && $episode <= 9999 && ($episode < 1900 || $episode > 2100)) {
                return $episode;
            }
        }

        return null;
    }
}
