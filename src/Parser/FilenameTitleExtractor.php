<?php

declare(strict_types=1);

namespace Phlix\Anidb\Parser;

/**
 * Extracts a likely anime title from a file path via pure string manipulation.
 *
 * This class has no dependencies on UDP, sessions, or any external state —
 * making it a pure, independently-testable unit.
 */
final class FilenameTitleExtractor
{
    /**
     * Extract a likely anime title from a file path.
     *
     * Strips common release-group tags, episode patterns, resolution/codec
     * suffixes, and year annotations to leave the core title.
     *
     * @param string $filePath Absolute path to media file.
     *
     * @return string|null Extracted title or null if no clear match.
     */
    public function extract(string $filePath): ?string
    {
        $filename = pathinfo($filePath, PATHINFO_FILENAME);

        // Strip common release group patterns: [GroupName], Group-Name, etc.
        $clean = preg_replace('/\[[^\]]+\]/', '', $filename);
        $clean = preg_replace('/\(TX\)/', '', $clean);
        $clean = preg_replace('/\([^\)]+\)/', '', $clean);

        // Strip episode patterns: S01E02, 01x02, Episode 01, Episode.01, standalone 01, 1000
        $clean = preg_replace('/[Ss]\d{1,2}[Ee]\d{1,4}/', '', $clean);
        $clean = preg_replace('/\d{1,2}[Xx]\d{1,4}/', '', $clean);
        $clean = preg_replace('/[.\- _]*[Ee]p?[i]?[t]?[.]?\d{1,4}/i', '', $clean);
        // Strip standalone episode numbers: leading ., -, _, space before 1-4 digits
        $clean = preg_replace('/[.\- ][0-9]{1,4}$/', '', $clean);

        // Strip common suffixes: 720p, 1080p, BluRay, HDTV, etc.
        $clean = preg_replace('/(720p|1080p|2160p|480p|BluRay|BRRip|HDRip|HDTV|DVDRip|x264|x265|HEVC|AAC|AC3)/i', '', $clean);

        // Strip year patterns: (2016), 2001, 2023 (at end of string)
        $clean = preg_replace('/\(\d{4}\)/', '', $clean);
        $clean = preg_replace('/\s+\d{4}$/', '', $clean);

        // Strip resolution and codec patterns
        $clean = preg_replace('/\d{3,4}[xX]\d{3,4}/', '', $clean);

        // Strip leading/trailing dashes, dots, underscores, spaces
        $clean = trim($clean, '.-_ ');

        // Replace remaining dots with spaces (common in anime filenames)
        $clean = str_replace('.', ' ', $clean);

        // If result is too short or looks like garbage, skip
        if (strlen($clean) < 2) {
            return null;
        }

        return $clean ?: null;
    }
}
