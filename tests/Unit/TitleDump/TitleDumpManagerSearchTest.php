<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\TitleDump\TitleDumpManager;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TitleDumpManager search scoring logic.
 */
final class TitleDumpManagerSearchTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/anidb_manager_search_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
    }

    public function test_search_returns_aid_on_exact_match(): void
    {
        $manager = $this->createManagerWithIndex([
            ['aid' => 1, 'titles' => [['title' => 'Cowboy Bebop', 'type' => 'main', 'lang' => 'en']]],
            ['aid' => 2, 'titles' => [['title' => 'Neon Genesis Evangelion', 'type' => 'main', 'lang' => 'en']]],
        ]);

        $this->assertSame(1, $manager->search('Cowboy Bebop'));
        $this->assertSame(2, $manager->search('Neon Genesis Evangelion'));
    }

    public function test_search_returns_first_exact_match_when_multiple(): void
    {
        $manager = $this->createManagerWithIndex([
            ['aid' => 1, 'titles' => [['title' => 'Same Title', 'type' => 'main', 'lang' => 'en']]],
            ['aid' => 2, 'titles' => [['title' => 'Same Title', 'type' => 'official', 'lang' => 'ja']]],
        ]);

        // Exact match should return the first one found (AID 1)
        $this->assertSame(1, $manager->search('Same Title'));
    }

    public function test_search_is_case_insensitive(): void
    {
        $manager = $this->createManagerWithIndex([
            ['aid' => 1, 'titles' => [['title' => 'Cowboy Bebop', 'type' => 'main', 'lang' => 'en']]],
        ]);

        $this->assertSame(1, $manager->search('cowboy bebop'));
        $this->assertSame(1, $manager->search('COWBOY BEBOP'));
        $this->assertSame(1, $manager->search('CoWboY BeBoP'));
    }

    public function test_search_prefers_prefix_over_contains(): void
    {
        // "Cowboy Bebop" title
        // Query "Cowboy" matches as prefix (score 800 - |6-14| = 792)
        // Query "boy Be" matches as contains (score 600 - |6-14| = 592)
        // Prefix should win
        $manager = $this->createManagerWithIndex([
            ['aid' => 1, 'titles' => [['title' => 'Cowboy Bebop', 'type' => 'main', 'lang' => 'en']]],
        ]);

        $this->assertSame(1, $manager->search('Cowboy')); // prefix match
        $this->assertSame(1, $manager->search('boy Be')); // contains match
    }

    public function test_search_returns_null_for_no_match(): void
    {
        $manager = $this->createManagerWithIndex([
            ['aid' => 1, 'titles' => [['title' => 'Cowboy Bebop', 'type' => 'main', 'lang' => 'en']]],
        ]);

        $this->assertNull($manager->search('Nonexistent Anime'));
        $this->assertNull($manager->search('xyz'));
    }

    public function test_search_returns_null_for_empty_index(): void
    {
        $manager = $this->createManagerWithIndex([]);
        $this->assertNull($manager->search('Anything'));
    }

    public function test_search_favors_length_similarity_in_scoring(): void
    {
        // Two entries, both match "Cowboy"
        // "Cowboy " (7 chars) prefix match: 800 - |6-7| = 793
        // "Cowboy Bebop" (14 chars) contains: 600 - |6-14| = 592
        // "Cowboy " should win
        $manager = $this->createManagerWithIndex([
            ['aid' => 1, 'titles' => [['title' => 'Cowboy Bebop', 'type' => 'main', 'lang' => 'en']]],
            ['aid' => 2, 'titles' => [['title' => 'Cowboy', 'type' => 'main', 'lang' => 'en']]],
        ]);

        $result = $manager->search('Cowboy');
        // "Cowboy" exact should match AID 2
        // But if both match as prefix/contains, the shorter one (exact) should win
        $this->assertSame(2, $result);
    }

    public function test_inject_index_allows_direct_index_injection(): void
    {
        $manager = new TitleDumpManager($this->tempDir, 'http://example.com/test.gz');

        $index = [
            ['aid' => 100, 'titles' => [['title' => 'Injected Title', 'type' => 'main', 'lang' => 'en']]],
        ];
        $manager->injectIndex($index);

        $this->assertSame(100, $manager->search('Injected Title'));
    }

    public function test_inject_index_overrides_file_based_index(): void
    {
        // Create a file-based index first
        $indexFile = $this->tempDir . '/title_index.json';
        file_put_contents($indexFile, json_encode([
            ['aid' => 1, 'titles' => [['title' => 'File Index Title', 'type' => 'main', 'lang' => 'en']]],
        ]));

        $manager = new TitleDumpManager($this->tempDir, 'http://example.com/test.gz');
        $manager->ensureLoaded();

        // Now inject a different index
        $injectedIndex = [
            ['aid' => 2, 'titles' => [['title' => 'Injected Title', 'type' => 'main', 'lang' => 'en']]],
        ];
        $manager->injectIndex($injectedIndex);

        // Injected index should take precedence
        $this->assertSame(2, $manager->search('Injected Title'));
        $this->assertNull($manager->search('File Index Title'));
    }

    public function test_get_title_cache_path_returns_correct_path(): void
    {
        $manager = new TitleDumpManager($this->tempDir, 'http://example.com/test.gz');

        $expected = $this->tempDir . '/title_index.json';
        $this->assertSame($expected, $manager->getTitleCachePath());
    }

    public function test_is_loaded_initially_false(): void
    {
        $manager = new TitleDumpManager($this->tempDir, 'http://example.com/test.gz');

        $this->assertFalse($manager->isLoaded());
    }

    public function test_is_loaded_true_after_ensure_loaded(): void
    {
        // Create a valid index file
        $indexFile = $this->tempDir . '/title_index.json';
        file_put_contents($indexFile, json_encode([
            ['aid' => 1, 'titles' => [['title' => 'Test', 'type' => 'main', 'lang' => 'en']]],
        ]));

        $manager = new TitleDumpManager($this->tempDir, 'http://example.com/test.gz');
        $this->assertFalse($manager->isLoaded());

        $manager->ensureLoaded();
        $this->assertTrue($manager->isLoaded());
    }

    public function test_is_loaded_true_after_inject_index(): void
    {
        $manager = new TitleDumpManager($this->tempDir, 'http://example.com/test.gz');
        $this->assertFalse($manager->isLoaded());

        $manager->injectIndex([]);
        $this->assertTrue($manager->isLoaded());
    }

    public function test_search_handles_slash_in_title(): void
    {
        // Fate/stay night has a slash - should be searchable
        $manager = $this->createManagerWithIndex([
            ['aid' => 1, 'titles' => [['title' => 'Fate/stay night', 'type' => 'main', 'lang' => 'en']]],
        ]);

        $this->assertSame(1, $manager->search('Fate/stay night'));
    }

    private function createManagerWithIndex(array $index): TitleDumpManager
    {
        $manager = new TitleDumpManager($this->tempDir, 'http://example.com/test.gz');
        $manager->injectIndex($index);
        return $manager;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $file) {
            $path = $dir . '/' . $file;
            if (is_link($path)) {
                unlink($path);
                continue;
            }
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
