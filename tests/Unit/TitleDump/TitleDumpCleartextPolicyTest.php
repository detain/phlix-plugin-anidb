<?php

/**
 * M-ANIDB-10 (review anidb__secaudit M3) + ANI-S9 pins: the title-dump fetch
 * never follows redirects, and a cleartext download is DEFAULT-DENIED.
 *
 * Background: resolve() intentionally keeps operator-configured third-party
 * mirror URLs verbatim (the migration only upgrades anidb.net legacy http),
 * so a stored http://mirror keeps whatever scheme the operator set. The
 * bundled client follows up to 5 redirects by default, so even an https
 * request can answer 301 -> http:// and move the fetch to plaintext — a leg
 * where the strict M-ANIDB-08 context cannot protect anything (no
 * certificate exists on http). The redirect cap closes the downgrade; ANI-S9
 * flips the operator-configured http mirror from a loud warning to a
 * refusal, with the documented http-mirror path preserved behind the
 * explicit PHLIX_ANIDB_ALLOW_HTTP_DUMP_MIRROR=1 opt-in (which still logs the
 * cleartext warning on every attempt).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit\TitleDump;

use Phlix\Anidb\TitleDump\TitleDumpIndexer;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
        putenv(TitleDumpIndexer::ALLOW_HTTP_DUMP_MIRROR_ENV);
    }

    protected function tearDown(): void
    {
        putenv(TitleDumpIndexer::ALLOW_HTTP_DUMP_MIRROR_ENV);
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

    /**
     * Run one download attempt through the private seam and report whether the
     * transport was reached.
     *
     * @return array{result: bool, transportCalls: int, log: string}
     */
    private function attempt(string $url): array
    {
        // PHPUnit 12 redirects error_log() to its own capture file after
        // setUp(), so the per-test sink must be (re)pointed inside the test.
        ini_set('error_log', $this->dir . '/error.log');
        $calls = 0;
        $httpClient = static function (string $url, callable $onResult) use (&$calls): void {
            $calls++;
            $onResult(null);
        };
        $indexer = new TitleDumpIndexer($this->dir, $url, $httpClient);

        $attempt = new \ReflectionMethod(TitleDumpIndexer::class, 'doDownloadAndIndex');
        $attempt->setAccessible(true);
        $result = $attempt->invoke($indexer);

        return [
            'result'         => $result === true,
            'transportCalls' => $calls,
            'log'            => (string) @file_get_contents($this->dir . '/error.log'),
        ];
    }

    public function test_cleartextMirrorIsRefusedByDefault(): void
    {
        $outcome = $this->attempt('http://user:s3cret@mirror.example.org/private/anime-titles.dat.gz');

        $this->assertFalse($outcome['result'], 'ANI-S9: a refused attempt is a failed attempt');
        $this->assertSame(
            0,
            $outcome['transportCalls'],
            'ANI-S9: without the opt-in, a cleartext title_dump_url must never reach the transport'
        );
        $this->assertStringContainsString('REFUSING', $outcome['log']);
        $this->assertStringContainsString('mirror.example.org', $outcome['log']);
        $this->assertStringContainsString(TitleDumpIndexer::ALLOW_HTTP_DUMP_MIRROR_ENV . '=1', $outcome['log']);
        // The refusal names the host only — settings values can embed credentials.
        $this->assertStringNotContainsString('s3cret', $outcome['log']);
        $this->assertStringNotContainsString('/private/', $outcome['log']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonOptInValues(): array
    {
        return [
            'zero'        => ['0'],
            'empty'       => [''],
            'word true'   => ['true'],
            'yes'         => ['yes'],
            'padded one'  => [' 1'],
        ];
    }

    #[DataProvider('nonOptInValues')]
    public function test_onlyTheExactOptInValueUnlocksCleartext(string $value): void
    {
        putenv(TitleDumpIndexer::ALLOW_HTTP_DUMP_MIRROR_ENV . '=' . $value);

        $outcome = $this->attempt('http://mirror.example.org/anime-titles.dat.gz');

        $this->assertSame(0, $outcome['transportCalls'], 'ANI-S9: the opt-in is exactly "1", nothing looser');
        $this->assertStringContainsString('REFUSING', $outcome['log']);
    }

    public function test_optInFetchesCleartextMirrorWithLoudWarning(): void
    {
        putenv(TitleDumpIndexer::ALLOW_HTTP_DUMP_MIRROR_ENV . '=1');

        // Fake transport: the warning sits at the fetch() seam so it fires for
        // EVERY transport (production client and injected fakes), not only the
        // Workerman path.
        $outcome = $this->attempt('http://mirror.example.org/anime-titles.dat.gz');

        $this->assertSame(1, $outcome['transportCalls'], 'ANI-S9: the opt-in preserves the documented http-mirror path');
        $this->assertStringNotContainsString('REFUSING', $outcome['log']);
        $this->assertStringContainsString('cleartext', $outcome['log']);
        $this->assertStringContainsString('mirror.example.org', $outcome['log']);
    }

    public function test_httpsFetchStaysQuietAboutCleartext(): void
    {
        $outcome = $this->attempt('https://anidb.net/api/anime-titles.dat.gz');

        $this->assertSame(1, $outcome['transportCalls'], 'https is never gated by the cleartext opt-in');
        $this->assertStringNotContainsString('cleartext', $outcome['log']);
        $this->assertStringNotContainsString(TitleDumpIndexer::ALLOW_HTTP_DUMP_MIRROR_ENV, $outcome['log']);
    }
}
