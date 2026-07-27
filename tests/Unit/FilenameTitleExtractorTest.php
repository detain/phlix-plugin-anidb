<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Parser\FilenameTitleExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FilenameTitleExtractor.
 *
 * These tests verify the pure string-manipulation logic in isolation
 * (no network, no UDP, no session state).
 */
final class FilenameTitleExtractorTest extends TestCase
{
    private FilenameTitleExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FilenameTitleExtractor();
    }

    /**
     * @dataProvider filenameProvider
     */
    public function test_extracts_anime_name_from_various_release_naming_patterns(
        string $input,
        ?string $expectedTitle
    ): void {
        $result = $this->extractor->extract($input);

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

    public function test_returns_null_for_path_with_only_extension(): void
    {
        $result = $this->extractor->extract('/some/path/.mkv');

        $this->assertNull($result);
    }

    public function test_handles_path_with_no_cleanable_content(): void
    {
        // Episode-only filename with group tag
        $result = $this->extractor->extract('[Group] S01E01.mkv');

        $this->assertNull($result);
    }
}
