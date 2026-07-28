<?php

/**
 * Tests for the title-dump URL default and the stored-value fix-up.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit\TitleDump;

use Phlix\Anidb\AnidbMetadataProvider;
use Phlix\Anidb\TitleDump\TitleDumpManager;
use Phlix\Anidb\TitleDump\TitleDumpUrlMigration;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * CONSEQUENCE tests for SM-0.3 — the AniDB title dump never populated because
 * `title_dump_url` used `http://`, which Cloudflare answers with 403; `https://`
 * answers 200 with ~1.4 MB of gzip. On prod the resulting title_index.json was
 * 2 bytes.
 *
 * Two things have to hold, and the second is the one that actually fixes an
 * existing install: the MANIFEST DEFAULT must be https, and a value already
 * PERSISTED as http must be migrated on the way into the plugin (the host merges
 * array_merge($defaults, $stored), so the stored value otherwise wins forever).
 */
final class TitleDumpUrlMigrationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // MANIFEST DEFAULT
    // -------------------------------------------------------------------------

    public function test_manifest_default_title_dump_url_is_https(): void
    {
        $default = self::manifestTitleDumpDefault();

        $this->assertStringStartsWith(
            'https://',
            $default,
            'plugin.json title_dump_url default is not https — AniDB returns 403 for plain http',
        );
        $this->assertSame(
            TitleDumpUrlMigration::DEFAULT_URL,
            $default,
            'plugin.json default drifted from TitleDumpUrlMigration::DEFAULT_URL',
        );
    }

    public function test_manifest_contains_no_plain_http_anidb_url(): void
    {
        $raw = file_get_contents(self::manifestPath());
        $this->assertIsString($raw);

        $this->assertStringNotContainsString(
            'http://anidb.net',
            $raw,
            'plugin.json still carries a plain-http AniDB URL',
        );
    }

    // -------------------------------------------------------------------------
    // STORED-VALUE FIX-UP
    // -------------------------------------------------------------------------

    /**
     * @dataProvider provideUrls
     */
    public function test_resolve_migrates_only_insecure_anidb_urls(mixed $configured, string $expected): void
    {
        $this->assertSame($expected, TitleDumpUrlMigration::resolve($configured));
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function provideUrls(): array
    {
        return [
            // The exact value stored on prod — the whole point of this step.
            'stored prod value' => [
                'http://anidb.net/api/anime-titles.dat.gz',
                'https://anidb.net/api/anime-titles.dat.gz',
            ],
            'anidb subdomain' => [
                'http://api.anidb.net/api/anime-titles.dat.gz',
                'https://api.anidb.net/api/anime-titles.dat.gz',
            ],
            'uppercase scheme and host' => [
                'HTTP://AniDB.NET/api/anime-titles.dat.gz',
                'https://AniDB.NET/api/anime-titles.dat.gz',
            ],
            'fully qualified trailing dot' => [
                'http://anidb.net./api/anime-titles.dat.gz',
                'https://anidb.net./api/anime-titles.dat.gz',
            ],
            'query string preserved' => [
                'http://anidb.net/api/anime-titles.dat.gz?v=2',
                'https://anidb.net/api/anime-titles.dat.gz?v=2',
            ],
            'already https is untouched (idempotent)' => [
                'https://anidb.net/api/anime-titles.dat.gz',
                'https://anidb.net/api/anime-titles.dat.gz',
            ],
            // Operator-chosen mirrors keep their scheme: some serve http only,
            // and rewriting them would break a working install.
            'third-party http mirror untouched' => [
                'http://mirror.example.org/anime-titles.dat.gz',
                'http://mirror.example.org/anime-titles.dat.gz',
            ],
            // Host-based decision, not a substring match.
            'anidb.net in the path is not an anidb host' => [
                'http://evil.example/anidb.net/anime-titles.dat.gz',
                'http://evil.example/anidb.net/anime-titles.dat.gz',
            ],
            'anidb.net as a userinfo prefix is not an anidb host' => [
                'http://anidb.net@evil.example/anime-titles.dat.gz',
                'http://anidb.net@evil.example/anime-titles.dat.gz',
            ],
            'lookalike suffix is not an anidb host' => [
                'http://notanidb.net/anime-titles.dat.gz',
                'http://notanidb.net/anime-titles.dat.gz',
            ],
            'blank falls back to the default' => ['', TitleDumpUrlMigration::DEFAULT_URL],
            'whitespace falls back to the default' => ['   ', TitleDumpUrlMigration::DEFAULT_URL],
            'missing/non-string falls back to the default' => [null, TitleDumpUrlMigration::DEFAULT_URL],
        ];
    }

    public function test_is_legacy_insecure_anidb_url_flags_only_the_broken_shape(): void
    {
        $this->assertTrue(
            TitleDumpUrlMigration::isLegacyInsecureAnidbUrl('http://anidb.net/api/anime-titles.dat.gz'),
        );
        $this->assertFalse(
            TitleDumpUrlMigration::isLegacyInsecureAnidbUrl('https://anidb.net/api/anime-titles.dat.gz'),
        );
        $this->assertFalse(
            TitleDumpUrlMigration::isLegacyInsecureAnidbUrl('http://mirror.example.org/anime-titles.dat.gz'),
        );
    }

    // -------------------------------------------------------------------------
    // END TO END — a stored http value must reach the indexer as https
    // -------------------------------------------------------------------------

    public function test_stored_http_setting_reaches_the_title_dump_manager_as_https(): void
    {
        // Exactly what an already-installed server (prod) hands the plugin: the
        // manifest default is only consulted for keys that are NOT stored.
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => true,
            'title_dump_url' => 'http://anidb.net/api/anime-titles.dat.gz',
        ]);

        $provider->onEnable(new NoServicesContainer());

        $this->assertSame(
            'https://anidb.net/api/anime-titles.dat.gz',
            self::titleDumpUrlOf($provider),
            'a stored plain-http AniDB URL was handed to the downloader unchanged',
        );
    }

    public function test_stored_mirror_setting_is_handed_through_untouched(): void
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => true,
            'title_dump_url' => 'http://mirror.example.org/anime-titles.dat.gz',
        ]);

        $provider->onEnable(new NoServicesContainer());

        $this->assertSame(
            'http://mirror.example.org/anime-titles.dat.gz',
            self::titleDumpUrlOf($provider),
        );
    }

    public function test_blank_stored_setting_falls_back_to_the_https_default(): void
    {
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => true,
            'title_dump_url' => '',
        ]);

        $provider->onEnable(new NoServicesContainer());

        $this->assertSame(TitleDumpUrlMigration::DEFAULT_URL, self::titleDumpUrlOf($provider));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Read the URL the provider actually handed to its {@see TitleDumpManager}.
     */
    private static function titleDumpUrlOf(AnidbMetadataProvider $provider): string
    {
        $managerProp = (new ReflectionClass($provider))->getProperty('titleDumpManager');
        $managerProp->setAccessible(true);
        $manager = $managerProp->getValue($provider);

        if (!$manager instanceof TitleDumpManager) {
            throw new RuntimeException('onEnable() did not build a TitleDumpManager');
        }

        $urlProp = (new ReflectionClass($manager))->getProperty('titleDumpUrl');
        $urlProp->setAccessible(true);
        $url = $urlProp->getValue($manager);

        return is_string($url) ? $url : '';
    }

    private static function manifestPath(): string
    {
        return dirname(__DIR__, 3) . '/plugin.json';
    }

    private static function manifestTitleDumpDefault(): string
    {
        $raw = file_get_contents(self::manifestPath());
        self::assertIsString($raw, 'plugin.json is unreadable');

        /** @var mixed $manifest */
        $manifest = json_decode($raw, true);
        self::assertIsArray($manifest, 'plugin.json is not valid JSON');
        self::assertArrayHasKey('settings', $manifest);
        self::assertIsArray($manifest['settings']);
        self::assertArrayHasKey('title_dump_url', $manifest['settings']);
        self::assertIsArray($manifest['settings']['title_dump_url']);
        self::assertArrayHasKey('default', $manifest['settings']['title_dump_url']);
        self::assertIsString($manifest['settings']['title_dump_url']['default']);

        return $manifest['settings']['title_dump_url']['default'];
    }
}

/**
 * PSR-11 container that exposes nothing, so registerWithMetadataManager()
 * degrades to a no-op without any I/O.
 *
 * @internal Test fixture only.
 */
final class NoServicesContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new class ('not found') extends RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
        };
    }

    public function has(string $id): bool
    {
        return false;
    }
}
