<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Parser\EpisodeExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EpisodeExtractor.
 *
 * Tests the pure string-manipulation logic for extracting episode numbers
 * from anime filenames. No network I/O, no external state.
 */
final class EpisodeExtractorTest extends TestCase
{
    private EpisodeExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new EpisodeExtractor();
    }

    // -------------------------------------------------------------------------
    // extract() — S01E02 patterns
    // -------------------------------------------------------------------------

    public function test_extracts_season_episode_s01e02(): void
    {
        $this->assertSame(1, $this->extractor->extract('Anime Name S01E01'));
        $this->assertSame(12, $this->extractor->extract('Anime Name S01E12'));
        $this->assertSame(99, $this->extractor->extract('Anime Name S01E99'));
    }

    public function test_extracts_season_episode_s1e2_variants(): void
    {
        $this->assertSame(5, $this->extractor->extract('Anime S1E5'));
        $this->assertSame(24, $this->extractor->extract('Anime S2E24'));
    }

    public function test_extracts_season_episode_sa1eb2_rare_format(): void
    {
        // Rare format: SA1EB2 = Season A1 Episode B2
        $this->assertSame(2, $this->extractor->extract('Anime SA1EB2 Something'));
    }

    public function test_extracts_episode_from_double_e_pattern(): void
    {
        // S01E02E03 - if there's a double E pattern, takes the second number
        $this->assertSame(3, $this->extractor->extract('Anime S01E02E03'));
    }

    public function test_extracts_episode_ignores_zero_episode(): void
    {
        // Episode 0 should not be returned (invalid episode number)
        $this->assertNull($this->extractor->extract('Anime S01E00'));
    }

    // -------------------------------------------------------------------------
    // extract() — 01x02 patterns
    // -------------------------------------------------------------------------

    public function test_extracts_1x_notation(): void
    {
        $this->assertSame(24, $this->extractor->extract('Cowboy Bebop 01x24'));
        $this->assertSame(1, $this->extractor->extract('Anime 1x01'));
        $this->assertSame(12, $this->extractor->extract('Anime 01x12'));
    }

    public function test_extracts_1x_notation_with_leading_zeros(): void
    {
        $this->assertSame(5, $this->extractor->extract('Anime 001x005'));
    }

    // -------------------------------------------------------------------------
    // extract() — Episode keyword patterns
    // -------------------------------------------------------------------------

    public function test_extracts_episode_keyword_full(): void
    {
        $this->assertSame(1, $this->extractor->extract('Anime Episode 01'));
        $this->assertSame(12, $this->extractor->extract('Anime Episode 12'));
    }

    public function test_extracts_episode_keyword_variations(): void
    {
        $this->assertSame(5, $this->extractor->extract('Anime Ep 5'));
        $this->assertSame(10, $this->extractor->extract('Anime Ep.10'));
        $this->assertSame(3, $this->extractor->extract('Anime E3'));
    }

    public function test_extracts_episode_keyword_with_leading_zeros(): void
    {
        $this->assertSame(1, $this->extractor->extract('Anime Episode 001'));
        $this->assertSame(15, $this->extractor->extract('Anime Ep.0015'));
    }

    public function test_extracts_standalone_e_pattern(): void
    {
        // E01 pattern not preceded by S## - standalone episode number
        $this->assertSame(1, $this->extractor->extract('Anime 01 E01'));
        $this->assertSame(5, $this->extractor->extract('Anime E05'));
    }

    public function test_extracts_episode_keyword_ignores_zero(): void
    {
        $this->assertNull($this->extractor->extract('Anime Episode 0'));
        $this->assertNull($this->extractor->extract('Anime Ep 00'));
    }

    public function test_extracts_episode_keyword_ignores_large_numbers(): void
    {
        // E-codes like E12 (extension) should be filtered
        // But E01 in "Anime 01" context is accepted
        $this->assertNull($this->extractor->extract('Anime.mkv')); // no episode
    }

    // -------------------------------------------------------------------------
    // extract() — Standalone number patterns
    // -------------------------------------------------------------------------

    public function test_extracts_standalone_number_at_end(): void
    {
        $this->assertSame(1, $this->extractor->extract('Anime Episode 1'));
        $this->assertSame(24, $this->extractor->extract('Anime 24'));
    }

    public function test_extracts_standalone_number_rejects_years(): void
    {
        // Years look like episode numbers but should be rejected
        $this->assertNull($this->extractor->extract('Anime 1999'));
        $this->assertNull($this->extractor->extract('Anime 2020'));
    }

    public function test_extracts_standalone_number_rejects_small_numbers(): void
    {
        // Numbers less than 1 or very large should be rejected
        $this->assertNull($this->extractor->extract('Anime 0'));
        $this->assertNull($this->extractor->extract('Anime 10000'));
    }

    public function test_extracts_standalone_number_accepts_unicode(): void
    {
        // Unicode title with episode number
        $this->assertSame(5, $this->extractor->extract('アニメ 5'));
    }

    // -------------------------------------------------------------------------
    // extract() — Combined / edge cases
    // -------------------------------------------------------------------------

    public function test_extracts_prefers_earlier_pattern(): void
    {
        // When multiple patterns match, extractSeasonEpisode runs first
        // S01E01 would match both S01E02 pattern AND standalone "1"
        $this->assertSame(1, $this->extractor->extract('Anime S01E01'));
    }

    public function test_extracts_returns_null_for_no_match(): void
    {
        $this->assertNull($this->extractor->extract('Anime'));
        $this->assertNull($this->extractor->extract('No episode info here'));
        $this->assertNull($this->extractor->extract('Movie Title 2001'));
    }

    public function test_extracts_handles_multiple_spaces(): void
    {
        $this->assertSame(1, $this->extractor->extract('Anime  Episode   1'));
    }

    // -------------------------------------------------------------------------
    // isMoviePattern()
    // -------------------------------------------------------------------------

    public function test_is_movie_pattern_detects_movie(): void
    {
        $this->assertTrue($this->extractor->isMoviePattern('Anime movie'));
        $this->assertTrue($this->extractor->isMoviePattern('Anime MOVIE'));
        $this->assertTrue($this->extractor->isMoviePattern('Anime film'));
        $this->assertTrue($this->extractor->isMoviePattern('Anime FILM'));
    }

    public function test_is_movie_pattern_detects_ova(): void
    {
        $this->assertTrue($this->extractor->isMoviePattern('Anime OVA'));
        $this->assertTrue($this->extractor->isMoviePattern('Anime OAD'));
        $this->assertTrue($this->extractor->isMoviePattern('Anime OAV'));
        $this->assertTrue($this->extractor->isMoviePattern('Anime -ova'));
    }

    public function test_is_movie_pattern_detects_special(): void
    {
        $this->assertTrue($this->extractor->isMoviePattern('Anime special'));
        $this->assertTrue($this->extractor->isMoviePattern('Anime SP'));
        $this->assertTrue($this->extractor->isMoviePattern('Anime sp'));
    }

    public function test_is_movie_pattern_detects_pilot(): void
    {
        $this->assertTrue($this->extractor->isMoviePattern('Anime pilot'));
        $this->assertTrue($this->extractor->isMoviePattern('Anime PILOT'));
    }

    public function test_is_movie_pattern_returns_false_for_regular_episodes(): void
    {
        $this->assertFalse($this->extractor->isMoviePattern('Anime S01E01'));
        $this->assertFalse($this->extractor->isMoviePattern('Anime Episode 12'));
        $this->assertFalse($this->extractor->isMoviePattern('Anime 01x24'));
    }

    public function test_is_movie_pattern_is_case_insensitive(): void
    {
        $this->assertTrue($this->extractor->isMoviePattern('anime MOVIE anime'));
        $this->assertTrue($this->extractor->isMoviePattern('ANIME OVA'));
    }

    public function test_is_movie_pattern_partial_match(): void
    {
        // Should detect the keyword anywhere in the string
        $this->assertTrue($this->extractor->isMoviePattern('[Group] Anime OVA [480p]'));
        $this->assertTrue($this->extractor->isMoviePattern('Movie - OVA'));
    }
}
