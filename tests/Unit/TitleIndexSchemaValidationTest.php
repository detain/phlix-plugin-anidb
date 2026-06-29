<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for title_index.json schema validation in AnidbMetadataProvider.
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
            unlink($file);
        }
        rmdir($this->tempDir);
        parent::tearDown();
    }

    /**
     * Create a provider with a custom cache dir pointing to our temp directory.
     */
    private function createProvider(string $cacheDir): AnidbMetadataProvider
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => true,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);

        $reflection = new \ReflectionClass($provider);
        $cacheDirProp = $reflection->getProperty('cacheDir');
        $cacheDirProp->setAccessible(true);
        $cacheDirProp->setValue($provider, $cacheDir);

        return $provider;
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

        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);
        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        $this->assertCount(2, $titleIndex);
        $this->assertSame(12345, $titleIndex[0]['aid']);
        $this->assertSame('Fate/stay night', $titleIndex[0]['titles'][0]['title']);
        $this->assertSame(67890, $titleIndex[1]['aid']);
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

        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);
        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        // Only the valid entries (index 0 and 2) should be loaded
        $this->assertCount(2, $titleIndex);
        $this->assertSame(12345, $titleIndex[0]['aid']);
        $this->assertSame(67890, $titleIndex[1]['aid']);
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

        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);
        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        // Only the entries with proper 'titles' key should be loaded
        $this->assertCount(2, $titleIndex);
        $this->assertSame(12345, $titleIndex[0]['aid']);
        $this->assertSame(11111, $titleIndex[1]['aid']);
    }

    public function test_non_array_root_falls_back_to_empty(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';

        // Non-array root (string instead of array)
        file_put_contents($indexFile, json_encode('this is not an array'));

        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);
        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        // Should fall back to empty array, no fatal error
        $this->assertSame([], $titleIndex);
    }

    public function test_junk_json_falls_back_to_empty(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';

        // Completely invalid JSON
        file_put_contents($indexFile, 'not valid json at all {{{');

        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);
        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        // Should fall back to empty array, no fatal error
        $this->assertSame([], $titleIndex);
    }

    public function test_missing_title_index_file_falls_back_to_empty(): void
    {
        // No file created - path does not exist
        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);
        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        $this->assertSame([], $titleIndex);
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

        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);
        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        // The entry should be rejected entirely because one of its titles is malformed
        $this->assertSame([], $titleIndex);
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

        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);
        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        $this->assertSame([], $titleIndex);
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

        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);
        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        $this->assertSame([], $titleIndex);
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

        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);
        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        $this->assertSame([], $titleIndex);
    }

    public function test_empty_array_root_falls_back_to_empty(): void
    {
        $indexFile = $this->tempDir . '/title_index.json';

        // Empty array is valid JSON but has no entries
        file_put_contents($indexFile, json_encode([]));

        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);
        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        $this->assertSame([], $titleIndex);
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

        $provider = $this->createProvider($this->tempDir);
        $reflection = new \ReflectionClass($provider);
        $method = $reflection->getMethod('ensureTitleIndexLoaded');
        $method->setAccessible(true);

        $method->invoke($provider);

        $titleIndexProp = $reflection->getProperty('titleIndex');
        $titleIndexProp->setAccessible(true);
        $titleIndex = $titleIndexProp->getValue($provider);

        // Should fall back to empty array
        $this->assertSame([], $titleIndex);
    }
}