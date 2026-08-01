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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TitleDumpIndexer private method branches via reflection.
 */
final class TitleDumpIndexerMethodsTest extends TestCase
{
    private const FIXTURE_DAT = "1|main|x-jat|Bokura no Leader\n2|main|x-jat|Utena\n";

    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->recursiveDelete($dir);
        }
        $this->tmpDirs = [];
    }

    /**
     * Test markerIsLive via reflection with various mtime values.
     */
    #[DataProvider('markerMtimeProvider')]
    public function test_marker_is_live(int|false $mtime, bool $expected): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/test.gz');

        $reflection = new \ReflectionClass($indexer);
        $method = $reflection->getMethod('markerIsLive');
        $method->setAccessible(true);

        $result = $method->invoke($indexer, $mtime);

        $this->assertSame($expected, $result);
    }

    /**
     * @return array<string, array{int|false, bool}>
     */
    public static function markerMtimeProvider(): array
    {
        $now = time();
        $recent = $now - 60; // 60 seconds ago - within TTL
        $old = $now - TitleDumpIndexer::IN_FLIGHT_TTL_SECONDS - 10; // older than TTL

        return [
            'false mtime is reclaimable' => [false, false],
            'recent mtime is live' => [$recent, true],
            'old mtime is reclaimable' => [$old, false],
            'future mtime is stale (treated as reclaimable)' => [$now + 86400, false],
        ];
    }

    /**
     * Test describeMarkerType via reflection with various file types.
     */
    #[DataProvider('markerTypeProvider')]
    public function test_describe_marker_type(string $type, string $markerPath, string $expectedContains): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/test.gz');

        $reflection = new \ReflectionClass($indexer);
        $method = $reflection->getMethod('describeMarkerType');
        $method->setAccessible(true);

        $result = $method->invoke($indexer, $type, $markerPath);

        $this->assertStringContainsString($expectedContains, $result);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function markerTypeProvider(): array
    {
        return [
            'dir' => ['dir', '/tmp/test', 'a directory'],
            'fifo' => ['fifo', '/tmp/test', 'a FIFO'],
            'socket' => ['socket', '/tmp/test', 'a socket'],
            'block' => ['block', '/tmp/test', 'a block device'],
            'char' => ['char', '/tmp/test', 'a character device'],
            'unknown' => ['unknown', '/tmp/test', 'a unknown'],
        ];
    }

    public function test_in_flight_marker_path_returns_correct_path(): void
    {
        $dir = $this->makeTmpDir();
        $indexer = new TitleDumpIndexer($dir, 'http://example.com/test.gz');

        $reflection = new \ReflectionClass($indexer);
        $method = $reflection->getMethod('inFlightMarkerPath');
        $method->setAccessible(true);

        $result = $method->invoke($indexer);

        $this->assertSame($dir . '/title_index.download.lock', $result);
    }

    public function test_index_file_path_returns_correct_path(): void
    {
        $dir = $this->makeTmpDir();
        $indexer = new TitleDumpIndexer($dir, 'http://example.com/test.gz');

        $reflection = new \ReflectionClass($indexer);
        $method = $reflection->getMethod('indexFilePath');
        $method->setAccessible(true);

        $result = $method->invoke($indexer);

        $this->assertSame($dir . '/title_index.json', $result);
    }

    public function test_default_http_client_returns_null_when_workerman_unavailable(): void
    {
        // When Workerman\Client is not available, defaultHttpClient should invoke callback with null
        $dir = $this->makeTmpDir();
        $indexer = new TitleDumpIndexer($dir, 'http://example.com/test.gz');

        $reflection = new \ReflectionClass($indexer);
        $method = $reflection->getMethod('defaultHttpClient');
        $method->setAccessible(true);

        $receivedResult = 'not set';
        $callback = static function (?string $result) use (&$receivedResult): void {
            $receivedResult = $result;
        };

        // Call the private static method directly
        $method->invoke(null, 'http://example.com/test.gz', $callback);

        // When Client class doesn't exist, callback is invoked with null
        $this->assertNull($receivedResult);
    }

    public function test_fetch_invokes_http_client_with_url_and_callback(): void
    {
        $dir = $this->makeTmpDir();
        $receivedUrl = 'not set';
        $receivedCallback = 'not set';

        $httpClient = static function (string $url, callable $onResult) use (&$receivedUrl, &$receivedCallback): void {
            $receivedUrl = $url;
            $receivedCallback = $onResult;
            $onResult('test-data');
        };

        $indexer = new TitleDumpIndexer($dir, 'http://example.com/test.gz', $httpClient);

        $reflection = new \ReflectionClass($indexer);
        $method = $reflection->getMethod('fetch');
        $method->setAccessible(true);

        $result = null;
        $callback = static function (?string $data) use (&$result): void {
            $result = $data;
        };

        $method->invoke($indexer, $callback);

        $this->assertSame('http://example.com/test.gz', $receivedUrl);
        $this->assertSame('test-data', $result);
    }

    public function test_fetch_calls_callback_with_null_when_http_client_is_null(): void
    {
        $dir = $this->makeTmpDir();

        // Create indexer with null httpClient
        $indexer = new TitleDumpIndexer($dir, 'http://example.com/test.gz', null);

        $reflection = new \ReflectionClass($indexer);
        $method = $reflection->getMethod('fetch');
        $method->setAccessible(true);

        $result = 'not called';
        $callback = static function (?string $data) use (&$result): void {
            $result = $data;
        };

        $method->invoke($indexer, $callback);

        $this->assertNull($result);
    }

    public function test_write_index_creates_directory_if_missing(): void
    {
        $dir = $this->makeTmpDir() . '/nested/deep';
        $indexer = new TitleDumpIndexer($dir, 'http://example.com/test.gz');

        $reflection = new \ReflectionClass($indexer);
        $method = $reflection->getMethod('writeIndex');
        $method->setAccessible(true);

        $result = $method->invoke($indexer, [['aid' => 1, 'titles' => []]]);

        $this->assertTrue($result);
        $this->assertFileExists($dir . '/title_index.json');
    }

    public function test_write_index_returns_false_on_failure(): void
    {
        // Try to write to an unwritable location
        $dir = $this->makeTmpDir();
        chmod($dir, 0555);

        try {
            $indexer = new TitleDumpIndexer($dir, 'http://example.com/test.gz');

            $reflection = new \ReflectionClass($indexer);
            $method = $reflection->getMethod('writeIndex');
            $method->setAccessible(true);

            // Suppress the expected PHP warning from file_put_contents permission denied
            $result = @$method->invoke($indexer, [['aid' => 1, 'titles' => []]]);

            $this->assertFalse($result);
        } finally {
            chmod($dir, 0755);
        }
    }

    private function makeTmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/anidb_idxmeth_' . bin2hex(random_bytes(8));
        mkdir($dir, 0755, true);
        $this->tmpDirs[] = $dir;

        return $dir;
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
