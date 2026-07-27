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
 * Unit tests for title_index.json schema validation in TitleDumpManager.
 */
final class TitleIndexSchemaValidationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/anidb_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * Create a TitleDumpManager with a custom cache dir pointing to our temp directory.
     */
    private function createManager(string $cacheDir): TitleDumpManager
    {
        return new TitleDumpManager(
            $cacheDir,
            'http://example.com/anime-titles.dat.gz',
        );
    }

    public function test_valid_entries_load_and_are_used(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';
        $validData = [
            [
                'aid' => 12345,
                'titles' => [
                    ['title' => 'Fate/stay night', 'type' => 'main', 'lang' => 'en'],
                    ['title' => 'フェイト・ステイル', 'type' => 'ja', 'lang' => 'ja'],
                ],
            ],
            [
                'aid' => 67890,
                'titles' => [
                    ['title' => 'Cowboy Bebop', 'type' => 'main', 'lang' => 'en'],
                ],
            ],
        ];
        file_put_contents($indexFile, json_encode($validData));

        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        // Test via search to verify the index was loaded correctly
        $this->assertSame(12345, $manager->search('Fate/stay night'));
        $this->assertSame(67890, $manager->search('Cowboy Bebop'));
    }

    public function test_wrong_typed_aid_rejected_others_accepted(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';
        // Entry 0: valid, Entry 1: aid is string instead of int, Entry 2: valid
        $mixedData = [
            [
                'aid' => 12345,
                'titles' => [
                    ['title' => 'Valid Anime', 'type' => 'main', 'lang' => 'en'],
                ],
            ],
            [
                'aid' => 'not_an_int',
                'titles' => [
                    ['title' => 'Invalid Anime', 'type' => 'main', 'lang' => 'en'],
                ],
            ],
            [
                'aid' => 67890,
                'titles' => [
                    ['title' => 'Another Valid', 'type' => 'main', 'lang' => 'en'],
                ],
            ],
        ];
        file_put_contents($indexFile, json_encode($mixedData));

        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        // Only the valid entries (index 0 and 2) should be loaded
        $this->assertSame(12345, $manager->search('Valid Anime'));
        $this->assertSame(67890, $manager->search('Another Valid'));
        // Entry with string AID should not be found
        $this->assertNull($manager->search('Invalid Anime'));
    }

    public function test_missing_titles_key_rejected(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';
        // Entry 0: valid, Entry 1: missing 'titles' key
        $mixedData = [
            [
                'aid' => 12345,
                'titles' => [
                    ['title' => 'Valid Anime', 'type' => 'main', 'lang' => 'en'],
                ],
            ],
            [
                'aid' => 67890,
                // missing 'titles' key entirely
            ],
            [
                'aid' => 11111,
                'titles' => [
                    ['title' => 'Also Valid', 'type' => 'main', 'lang' => 'en'],
                ],
            ],
        ];
        file_put_contents($indexFile, json_encode($mixedData));

        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        // Only the entries with proper 'titles' key should be loaded
        $this->assertSame(12345, $manager->search('Valid Anime'));
        $this->assertSame(11111, $manager->search('Also Valid'));
        // Entry missing titles should not be found
        $this->assertNull($manager->search('Missing Titles'));
    }

    public function test_non_array_root_falls_back_to_empty(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';

        // Non-array root (string instead of array)
        file_put_contents($indexFile, json_encode('this is not an array'));

        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        // Should fall back to empty index, search returns null
        $this->assertNull($manager->search('anything'));
    }

    public function test_junk_json_falls_back_to_empty(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';

        // Completely invalid JSON
        file_put_contents($indexFile, 'not valid json at all {{{');

        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        // Should fall back to empty index, search returns null
        $this->assertNull($manager->search('anything'));
    }

    public function test_missing_title_index_file_falls_back_to_empty(): void
    {
        // No file created - path does not exist
        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        $this->assertNull($manager->search('anything'));
    }

    public function test_title_entry_missing_lang_rejected(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';
        // Entry with title missing 'lang' field
        $data = [
            [
                'aid' => 12345,
                'titles' => [
                    ['title' => 'Valid Title', 'type' => 'main', 'lang' => 'en'],
                    ['title' => 'Missing Lang', 'type' => 'main'/* no lang */],
                ],
            ],
        ];
        file_put_contents($indexFile, json_encode($data));

        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        // The entry should be rejected entirely because one of its titles is malformed
        $this->assertNull($manager->search('Valid Title'));
    }

    public function test_title_entry_missing_type_rejected(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';
        $data = [
            [
                'aid' => 12345,
                'titles' => [
                    ['title' => 'Valid Title', 'type' => 'main', 'lang' => 'en'],
                    ['title' => 'Missing Type', 'lang' => 'en'/* no type */],
                ],
            ],
        ];
        file_put_contents($indexFile, json_encode($data));

        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        // The entry should be rejected entirely
        $this->assertNull($manager->search('Valid Title'));
    }

    public function test_title_entry_missing_title_field_rejected(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';
        $data = [
            [
                'aid' => 12345,
                'titles' => [
                    ['title' => 'Valid Title', 'type' => 'main', 'lang' => 'en'],
                    ['type' => 'main', 'lang' => 'en'/* no title */],
                ],
            ],
        ];
        file_put_contents($indexFile, json_encode($data));

        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        // The entry should be rejected entirely
        $this->assertNull($manager->search('Valid Title'));
    }

    public function test_title_entry_wrong_type_for_title_rejected(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';
        $data = [
            [
                'aid' => 12345,
                'titles' => [
                    ['title' => 12345, 'type' => 'main', 'lang' => 'en'], // title should be string
                ],
            ],
        ];
        file_put_contents($indexFile, json_encode($data));

        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        // The entry should be rejected because title is not a string
        $this->assertNull($manager->search('12345'));
    }

    public function test_empty_array_root_falls_back_to_empty(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';

        // Empty array is valid JSON but has no entries
        file_put_contents($indexFile, json_encode([]));

        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        $this->assertNull($manager->search('anything'));
    }

    public function test_only_malformed_entries_falls_back_to_empty(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';

        // All entries are malformed
        $data = [
            ['aid' => 'not_int', 'titles' => []],
            ['aid' => 12345], // missing titles
            ['titles' => [['title' => 'x', 'type' => 'y', 'lang' => 'z']]], // missing aid
        ];
        file_put_contents($indexFile, json_encode($data));

        $manager = $this->createManager($this->tempDir);
        $manager->ensureLoaded();

        // Should fall back to empty index
        $this->assertNull($manager->search('x'));
    }
}
