<?php

/**
 * M-ANIDB-08 (review anidb__secaudit M1) pins: the title-dump HTTP client must
 * verify TLS, and the wiring must not rot back to the vendored insecure base.
 *
 * ID-SPACE LAW: this file's item numbers live in the MAJORS queue
 * (MAJORS_QUEUE.md ## M-ANIDB-NN); the minors vein closed a different
 * M-ANIDB-28 that happened to touch the same class.
 *
 * Why a config-method pin instead of an e2e TLS test: the download runs on
 * host-provided Workerman\Http\Client, which this repo does not require
 * (class_exists guard). The security property lives entirely in the options
 * array handed to the constructor — vendored ConnectionPool::create() ships
 * ssl.verify_peer=false / verify_peer_name=false / allow_self_signed=true as
 * its BASE and only overlays a caller-supplied 'context' via a shallow
 * array_merge, so omitting the context silently keeps the insecure defaults,
 * and supplying a partial ssl sub-array silently drops the pool's own
 * peer_name. These pins freeze the full strict sub-array.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit\TitleDump;

use Phlix\Anidb\TitleDump\TitleDumpIndexer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TitleDumpHttpClientConfigTest extends TestCase
{
    /** @var string|false */
    private string|false $originalCaBundleEnv;

    protected function setUp(): void
    {
        $this->originalCaBundleEnv = getenv(TitleDumpIndexer::CA_BUNDLE_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->originalCaBundleEnv === false) {
            putenv(TitleDumpIndexer::CA_BUNDLE_ENV);
        } else {
            putenv(TitleDumpIndexer::CA_BUNDLE_ENV . '=' . $this->originalCaBundleEnv);
        }
    }

    public function test_configEnforcesFullStrictTlsVerification(): void
    {
        $config = TitleDumpIndexer::httpClientConfig('https://anidb.net/api/anime-titles.dat.gz');

        $ssl = $config['context']['ssl'] ?? null;
        $this->assertIsArray($ssl, 'M-ANIDB-08: dump client must carry an ssl context — without it the vendored pool keeps its INSECURE base (no verification at all)');
        /** @var array<string, mixed> $ssl */
        $this->assertTrue($ssl['verify_peer'], 'verify_peer must be true');
        $this->assertTrue($ssl['verify_peer_name'], 'verify_peer_name must be true');
        $this->assertFalse($ssl['allow_self_signed'], 'allow_self_signed must be false — a self-signed cert must not authenticate a dump');
    }

    public function test_configPinsPeerNameToTheUrlHost(): void
    {
        // The pool derives peer_name only in ITS OWN base array; the caller's
        // context shallow-merges OVER it, so an omitted peer_name means the
        // name check runs against whatever the stream defaults to.
        $config = TitleDumpIndexer::httpClientConfig('https://mirror.example.org/dumps/anime-titles.dat.gz');

        $this->assertSame('mirror.example.org', $config['context']['ssl']['peer_name'] ?? null);
    }

    public function test_configKeepsTheTimeoutAndIsPureForHttpUrls(): void
    {
        $config = TitleDumpIndexer::httpClientConfig('http://anidb.net/api/anime-titles.dat.gz');

        $this->assertSame(TitleDumpIndexer::HTTP_TIMEOUT_SECONDS, $config['timeout']);
        // Cleartext URLs are still verified config-shape-wise; the scheme
        // itself is policy-checked elsewhere (M-ANIDB-10 fetch warning +
        // constructor scheme refusal).
        $this->assertTrue($config['context']['ssl']['verify_peer']);
    }

    public function test_configRefusesHostlessUrl(): void
    {
        $this->expectException(RuntimeException::class);
        TitleDumpIndexer::httpClientConfig('not-a-url');
    }

    public function test_caBundleEnvIsHonoredWhenReadable(): void
    {
        $ca = tempnam(sys_get_temp_dir(), 'anidbca');
        $this->assertIsString($ca);
        file_put_contents($ca, "-----BEGIN CERTIFICATE-----\nplaceholder\n");

        putenv(TitleDumpIndexer::CA_BUNDLE_ENV . '=' . $ca);
        $config = TitleDumpIndexer::httpClientConfig('https://anidb.net/api/anime-titles.dat.gz');

        $this->assertSame($ca, $config['context']['ssl']['cafile'] ?? null);
        unlink($ca);
    }

    public function test_caBundleEnvFailsLoudWhenUnusable(): void
    {
        putenv(TitleDumpIndexer::CA_BUNDLE_ENV . '=' . sys_get_temp_dir() . '/no-such-bundle-' . getmypid());

        $this->expectException(RuntimeException::class);
        TitleDumpIndexer::httpClientConfig('https://anidb.net/api/anime-titles.dat.gz');
    }

    public function test_defaultHttpClientActuallyConsumesTheStrictConfig(): void
    {
        // Wiring pin: a correct, never-used config method is security theater.
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/TitleDump/TitleDumpIndexer.php');

        $this->assertStringContainsString(
            'new Client(self::httpClientConfig($url))',
            $source,
            'M-ANIDB-08: defaultHttpClient must construct the Workerman client WITH the strict context'
        );
    }
}
