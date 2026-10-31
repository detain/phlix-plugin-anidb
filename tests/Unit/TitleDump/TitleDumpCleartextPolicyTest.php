<?php

/**
 * M-ANIDB-10 (review anidb__secaudit M3) pins: the title-dump fetch never
 * follows redirects, and a cleartext download is LOUD.
 *
 * Background: resolve() intentionally keeps operator-configured third-party
 * mirror URLs verbatim (the migration only upgrades anidb.net legacy http),
 * so a stored http://mirror keeps whatever scheme the operator set. The
 * bundled client follows up to 5 redirects by default, so even an https
 * request can answer 301 -> http:// and move the fetch to plaintext — a leg
 * where the strict M-ANIDB-08 context cannot protect anything (no
 * certificate exists on http). Hard-refusing operator http mirrors would
 * break documented mirror support (owner territory); the review's own floor
 * is "at minimum error_log a loud warning" + capping redirects — both here.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit\TitleDump;

use Phlix\Anidb\TitleDump\TitleDumpIndexer;
use PHPUnit\Framework\TestCase;

final class TitleDumpCleartextPolicyTest extends TestCase
{
    /** @var string scratch dir, removed in tearDown */
    private string $dir;

    /** @var string|false */
    private string|false $originalErrorLog;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/anidb-m10-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
        $this->originalErrorLog = ini_get('error_log');
        ini_set('error_log', $this->dir . '/error.log');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', is_string($this->originalErrorLog) ? $this->originalErrorLog : '');
        @unlink($this->dir . '/error.log');
        @rmdir($this->dir);
    }

    public function test_dumpRequestOptionsRefuseRedirects(): void
    {
        $options = TitleDumpIndexer::dumpRequestOptions();

        $this->assertSame(
            ['max' => 0],
            $options['allow_redirects'] ?? null,
            'M-ANIDB-10: following a 301 to http:// silently downgrades the dump fetch; max must be pinned to 0'
        );
    }

    public function test_defaultHttpClientActuallyUsesTheOptionSet(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/TitleDump/TitleDumpIndexer.php');

        $this->assertStringContainsString(
            '$client->request($url, self::dumpRequestOptions() + [',
            $source,
            'M-ANIDB-10: the redirect cap is theater unless defaultHttpClient hands it to the client'
        );
    }

    public function test_cleartextFetchLogsLoudWarning(): void
    {
        // Fake transport: the warning sits at the fetch() seam so it fires for
        // EVERY transport (production client and injected fakes), not only the
        // Workerman path.
        $httpClient = static function (string $url, callable $onResult): void {
            $onResult(null);
        };
        $indexer = new TitleDumpIndexer(
            $this->dir,
            'http://mirror.example.org/anime-titles.dat.gz',
            $httpClient,
        );

        $attempt = new \ReflectionMethod(TitleDumpIndexer::class, 'doDownloadAndIndex');
        $attempt->setAccessible(true);
        $attempt->invoke($indexer);

        $log = (string) file_get_contents($this->dir . '/error.log');
        $this->assertStringContainsString('cleartext', $log);
        $this->assertStringContainsString('mirror.example.org', $log);
    }

    public function test_httpsFetchStaysQuietAboutCleartext(): void
    {
        $httpClient = static function (string $url, callable $onResult): void {
            $onResult(null);
        };
        $indexer = new TitleDumpIndexer(
            $this->dir,
            'https://anidb.net/api/anime-titles.dat.gz',
            $httpClient,
        );

        $attempt = new \ReflectionMethod(TitleDumpIndexer::class, 'doDownloadAndIndex');
        $attempt->setAccessible(true);
        $attempt->invoke($indexer);

        $log = (string) @file_get_contents($this->dir . '/error.log');
        $this->assertStringNotContainsString('cleartext', $log);
    }
}
