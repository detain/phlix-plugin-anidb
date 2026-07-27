<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit\TitleDump;

use Phlix\Anidb\TitleDump\TitleDumpIndexer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TitleDumpIndexer.
 *
 * Covers parsing of anime-titles.dat.gz format, index building, and mtime guard.
 */
final class TitleDumpIndexerTest extends TestCase
{
    /**
     * Sample anime-titles.dat content (uncompressed).
     * Includes comments, blank lines, and varied title types.
     */
    private const FIXTURE_DAT = <<<'DAT'
# aid|type|lang|title
1|main|x-jat|Bokura no Leader
1|main|ja|僕らのリーダー
1|official|en|The Leader of Our Group
1|synonym|x-jat|Bokura no Leader
2|main|x-jat|Utena
2|official|en|Revolutionary Girl Utena
2|short|en|Utena
3|main|x-jat|Fate/stay night
3|official|en|Fate/stay night
3|synonym|en|Fate/Stay Night
DAT;

    /**
     * gzipped version of FIXTURE_DAT — pre-computed once.
     */
    private static ?string $fixtureGz = null;

    public static function setUpBeforeClass(): void
    {
        self::$fixtureGz = gzencode(self::FIXTURE_DAT, 6);
    }

    // -------------------------------------------------------------------------
    // parse()
    // -------------------------------------------------------------------------

    public function test_parse_produces_grouped_index_with_correct_shape(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse(self::FIXTURE_DAT);

        $this->assertIsArray($result);

        // Should be grouped by AID
        $this->assertCount(3, $result);

        // Entry 1: AID 1
        $this->assertSame(1, $result[0]['aid']);
        $this->assertIsArray($result[0]['titles']);
        $this->assertCount(4, $result[0]['titles']);

        // Entry 2: AID 2
        $this->assertSame(2, $result[1]['aid']);
        $this->assertCount(3, $result[1]['titles']);

        // Entry 3: AID 3
        $this->assertSame(3, $result[2]['aid']);
        $this->assertCount(3, $result[2]['titles']);
    }

    public function test_parse_skips_comment_lines(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("# comment line\n1|main|x-jat|Test\n# another comment\n");

        $this->assertCount(1, $result);
        $this->assertSame('Test', $result[0]['titles'][0]['title']);
    }

    public function test_parse_skips_blank_lines(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("\n\n1|main|x-jat|Test\n\n\n2|main|x-jat|Test2\n");

        $this->assertCount(2, $result);
    }

    public function test_parse_skips_malformed_lines(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("1|main|x-jat|Valid\nmalformed\n1|only-two-parts\n2|main|x-jat|Also Valid\n");

        $this->assertCount(2, $result);
        $this->assertSame('Valid', $result[0]['titles'][0]['title']);
        $this->assertSame('Also Valid', $result[1]['titles'][0]['title']);
    }

    public function test_parse_skips_invalid_aid(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("0|main|x-jat|Invalid AID 0\n1|main|x-jat|Valid\n-1|main|x-jat|Negative AID\n");

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['aid']);
    }

    public function test_parse_groups_multiple_titles_under_same_aid(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse(self::FIXTURE_DAT);

        // AID 1 has 4 titles (main x2, official, synonym)
        $aid1Entry = null;
        foreach ($result as $entry) {
            if ($entry['aid'] === 1) {
                $aid1Entry = $entry;
                break;
            }
        }

        $this->assertNotNull($aid1Entry);
        $this->assertCount(4, $aid1Entry['titles']);

        $titleValues = array_column($aid1Entry['titles'], 'title');
        $this->assertContains('Bokura no Leader', $titleValues);
        $this->assertContains('僕らのリーダー', $titleValues);
        $this->assertContains('The Leader of Our Group', $titleValues);
    }

    public function test_parse_preserves_type_and_lang_fields(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("1|main|x-jat|Bokura no Leader\n1|official|en|The Leader\n1|synonym|x-jat|Bokura\n");

        $titles = $result[0]['titles'];

        $mainTitle = null;
        $officialTitle = null;
        $synonymTitle = null;
        foreach ($titles as $t) {
            if ($t['type'] === 'main') {
                $mainTitle = $t;
            }
            if ($t['type'] === 'official') {
                $officialTitle = $t;
            }
            if ($t['type'] === 'synonym') {
                $synonymTitle = $t;
            }
        }

        $this->assertNotNull($mainTitle);
        $this->assertSame('main', $mainTitle['type']);
        $this->assertSame('x-jat', $mainTitle['lang']);
        $this->assertSame('Bokura no Leader', $mainTitle['title']);

        $this->assertNotNull($officialTitle);
        $this->assertSame('official', $officialTitle['type']);
        $this->assertSame('en', $officialTitle['lang']);
        $this->assertSame('The Leader', $officialTitle['title']);

        $this->assertNotNull($synonymTitle);
        $this->assertSame('synonym', $synonymTitle['type']);
    }

    public function test_parse_returns_empty_array_for_empty_content(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("");

        $this->assertCount(0, $result);
    }

    public function test_parse_returns_empty_array_for_only_comments(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("# comment\n# another\n");

        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // isStale()
    // -------------------------------------------------------------------------

    public function test_isStale_returns_true_for_nonexistent_file(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/nonexistent_dir_12345', 'http://example.com/anime-titles.dat.gz');

        $this->assertTrue($indexer->isStale('/tmp/nonexistent_dir_12345/title_index.json'));
    }

    public function test_isStale_returns_false_for_fresh_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'title_index_') . '.json';
        file_put_contents($tmpFile, '[]');

        try {
            $indexer = new TitleDumpIndexer(dirname($tmpFile), 'http://example.com/anime-titles.dat.gz');

            $this->assertFalse($indexer->isStale($tmpFile));
        } finally {
            unlink($tmpFile);
        }
    }

    public function test_isStale_returns_true_for_old_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'title_index_') . '.json';
        file_put_contents($tmpFile, '[]');
        // Set mtime to 25 hours ago
        touch($tmpFile, time() - (25 * 3600));

        try {
            $indexer = new TitleDumpIndexer(dirname($tmpFile), 'http://example.com/anime-titles.dat.gz');

            $this->assertTrue($indexer->isStale($tmpFile));
        } finally {
            unlink($tmpFile);
        }
    }

    // -------------------------------------------------------------------------
    // downloadAndIndex() — integration via mocked HTTP client
    // -------------------------------------------------------------------------

    public function test_downloadAndIndex_writes_correct_json_shape(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $gzData = gzencode(self::FIXTURE_DAT, 6);
        $this->assertNotNull($gzData);

        $httpCallCount = 0;

        // B5: HTTP client now uses callback-based interface (string $url, callable $onResult): void
        $httpClient = static function (string $url, callable $onResult) use ($gzData, &$httpCallCount): void {
            ++$httpCallCount;
            $onResult($gzData);
        };

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            $result = $indexer->downloadAndIndex();

            $this->assertTrue($result);
            $this->assertSame(1, $httpCallCount);

            $indexFile = $tmpDir . '/title_index.json';
            $this->assertFileExists($indexFile);

            /** @var list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}> $loaded */
            $loaded = json_decode(file_get_contents($indexFile), true);

            $this->assertCount(3, $loaded);
            $this->assertArrayHasKey('aid', $loaded[0]);
            $this->assertArrayHasKey('titles', $loaded[0]);
            $this->assertIsArray($loaded[0]['titles']);
        } finally {
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_downloadAndIndex_does_not_re_download_within_24h(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $gzData = gzencode(self::FIXTURE_DAT, 6);
        $this->assertNotNull($gzData);

        $httpCallCount = 0;

        // B5: HTTP client now uses callback-based interface (string $url, callable $onResult): void
        $httpClient = static function (string $url, callable $onResult) use ($gzData, &$httpCallCount): void {
            ++$httpCallCount;
            $onResult($gzData);
        };

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            // First call: should download and index
            $result1 = $indexer->downloadAndIndex();
            $this->assertTrue($result1);
            $this->assertSame(1, $httpCallCount);

            // Second call: index is fresh (not stale), should NOT re-download
            $result2 = $indexer->downloadAndIndex();
            $this->assertTrue($result2);
            $this->assertSame(1, $httpCallCount); // Still 1, no second HTTP call
        } finally {
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_downloadAndIndex_re_downloads_when_stale(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $gzData1 = gzencode("1|main|x-jat|Old Title\n", 6);
        $gzData2 = gzencode("1|main|x-jat|New Title\n", 6);
        $this->assertNotNull($gzData1);
        $this->assertNotNull($gzData2);

        $callCount = 0;
        // B5: HTTP client now uses callback-based interface (string $url, callable $onResult): void
        $httpClient = static function (string $url, callable $onResult) use ($gzData1, $gzData2, &$callCount): void {
            ++$callCount;
            $onResult($callCount === 1 ? $gzData1 : $gzData2);
        };

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            // First call: downloads initial data
            $indexer->downloadAndIndex();
            $this->assertSame(1, $callCount);

            // Touch the index file to make it stale (25 hours old)
            $indexFile = $tmpDir . '/title_index.json';
            touch($indexFile, time() - (25 * 3600));

            // Third call: should re-download because stale
            $result = $indexer->downloadAndIndex();
            $this->assertTrue($result);
            $this->assertSame(2, $callCount); // Second HTTP call was made

            // Verify the new content is in the index
            /** @var list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}> $loaded */
            $loaded = json_decode(file_get_contents($indexFile), true);
            $this->assertSame('New Title', $loaded[0]['titles'][0]['title']);
        } finally {
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_downloadAndIndex_returns_false_on_http_failure(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        // B5: HTTP client now uses callback-based interface, invoke callback with null on failure
        $httpClient = static function (string $url, callable $onResult): void {
            $onResult(null);
        };

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            $result = $indexer->downloadAndIndex();

            $this->assertFalse($result);
        } finally {
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_downloadAndIndex_returns_false_on_gzdecode_failure(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        // B5: HTTP client returns non-gzip data, which will fail gzdecode
        $httpClient = static function (string $url, callable $onResult): void {
            $onResult('not gzipped data');
        };

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            $result = $indexer->downloadAndIndex();

            $this->assertFalse($result);
        } finally {
            $this->recursiveDelete($tmpDir);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
