<?php

/**
 * cache_dir validation pins (W-min1, review anidb__secaudit).
 *
 * The operator-supplied `cache_dir` setting (and its PHLIX_ANIDB_CACHE_DIR
 * environment twin) previously flowed into the title-dump cache path with
 * only an is_string/empty check — a relative value silently resolved
 * against the worker's current directory, and a non-string value was
 * ignored without a word. resolveCacheDir() now fails loud: values must be
 * non-empty strings naming an absolute path, and an existing directory is
 * canonicalized through realpath() so a symlinked configured path cannot
 * quietly redirect where dump data lands. A world-writable cache directory
 * is prohibited by the manifest/README contract (operator-facing docs).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use PHPUnit\Framework\TestCase;

final class CacheDirValidationTest extends TestCase
{
    /** @var string|false Original env value to restore. */
    private string|false $envBackup = false;

    protected function setUp(): void
    {
        $this->envBackup = getenv('PHLIX_ANIDB_CACHE_DIR');
        putenv('PHLIX_ANIDB_CACHE_DIR');
    }

    protected function tearDown(): void
    {
        if (is_string($this->envBackup)) {
            putenv('PHLIX_ANIDB_CACHE_DIR=' . $this->envBackup);
        } else {
            putenv('PHLIX_ANIDB_CACHE_DIR');
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function provider(array $settings): AnidbMetadataProvider
    {
        return new AnidbMetadataProvider(array_merge([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'https://example.invalid/anime-titles.dat.gz',
        ], $settings));
    }

    private function cacheDirOf(AnidbMetadataProvider $provider): string
    {
        $reflection = new \ReflectionProperty($provider, 'cacheDir');
        $reflection->setAccessible(true);

        /** @var string $value */
        $value = $reflection->getValue($provider);

        return $value;
    }

    public function test_relative_cache_dir_setting_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/absolute/i');

        $this->provider(['cache_dir' => 'var/cache/phlix/anidb']);
    }

    public function test_relative_cache_dir_environment_is_rejected(): void
    {
        putenv('PHLIX_ANIDB_CACHE_DIR=relative/dump-dir');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PHLIX_ANIDB_CACHE_DIR/');

        $this->provider([]);
    }

    public function test_non_string_cache_dir_setting_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->provider(['cache_dir' => 1234]);
    }

    public function test_absolute_cache_dir_setting_is_honored(): void
    {
        $wanted = sys_get_temp_dir() . '/phlix-anidb-absolute-' . bin2hex(random_bytes(4));

        $this->assertSame($wanted, $this->cacheDirOf($this->provider(['cache_dir' => $wanted])));
    }

    public function test_existing_symlinked_cache_dir_is_canonicalized(): void
    {
        $real = sys_get_temp_dir() . '/phlix-anidb-real-' . bin2hex(random_bytes(4));
        $link = $real . '-link';
        $this->assertTrue(mkdir($real, 0o700, true));
        $this->assertTrue(symlink($real, $link));

        try {
            $this->assertSame(realpath($real), $this->cacheDirOf($this->provider(['cache_dir' => $link])));
        } finally {
            @unlink($link);
            @rmdir($real);
        }
    }

    public function test_unset_cache_dir_falls_back_to_temp_subdir(): void
    {
        $this->assertSame(
            sys_get_temp_dir() . '/phlix-plugin-anidb',
            $this->cacheDirOf($this->provider([])),
        );
    }

    public function test_explicit_constructor_cache_dir_arg_wins_unvalidated(): void
    {
        // The explicit argument is the trusted host-internal seam (typed
        // ?string at the boundary); it takes precedence and is passed raw.
        $provider = new AnidbMetadataProvider([
            'username' => 'testuser',
            'api_key' => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'https://example.invalid/anime-titles.dat.gz',
            'cache_dir' => '/tmp/settings-side-would-not-matter',
        ], null, null, null, null, null, null, null, 'rel-explicit');

        $this->assertSame('rel-explicit', $this->cacheDirOf($provider));
    }
}
