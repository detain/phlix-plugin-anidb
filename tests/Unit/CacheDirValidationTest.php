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
 * is prohibited by the manifest/README contract — and since PG-153 that
 * documented rule is ENFORCED at the same choke point: an existing
 * configured directory carrying the other-write bit is refused at
 * construction, because the index is served from it and any local user
 * could plant or swap it (the same activation-by-misconfiguration class
 * as a relative path).
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

    /**
     * PG-153 (review anidb__secaudit #3[m]): plugin.json and README both
     * decree the cache dir MUST NOT be world-writable — every existing pin
     * enforced everything EXCEPT that sentence. An existing configured
     * directory carrying the other-write bit is now refused at the same
     * choke point, naming the mode and the remedy.
     */
    public function test_world_writable_existing_cache_dir_setting_is_rejected(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-anidb-ww-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0o777, true));
        $this->assertTrue(chmod($dir, 0o777)); // mkdir honors the umask

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/world-writable/');

            $this->provider(['cache_dir' => $dir]);
        } finally {
            @rmdir($dir);
        }
    }

    public function test_sticky_world_writable_existing_cache_dir_setting_is_rejected(): void
    {
        // 1777 (a /tmp-style shared scratch): sticky protects files from
        // OTHER owners' deletes, but the index's own freshness window, a
        // planted symlink at the exact index name, and an empty-dir race are
        // all open to any local user. The documented rule is flat — the
        // other-write bit refuses regardless of sticky.
        $dir = sys_get_temp_dir() . '/phlix-anidb-sww-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0o777, true));
        $this->assertTrue(chmod($dir, 0o1777));

        try {
            $this->expectException(\InvalidArgumentException::class);

            $this->provider(['cache_dir' => $dir]);
        } finally {
            @rmdir($dir);
        }
    }

    public function test_world_writable_cache_dir_environment_is_rejected(): void
    {
        // The env twin is the same operator-facing surface — same rule.
        $dir = sys_get_temp_dir() . '/phlix-anidb-ww-env-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0o777, true));
        $this->assertTrue(chmod($dir, 0o777)); // mkdir honors the umask

        try {
            putenv('PHLIX_ANIDB_CACHE_DIR=' . $dir);

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/PHLIX_ANIDB_CACHE_DIR/');

            $this->provider([]);
        } finally {
            @rmdir($dir);
        }
    }

    public function test_group_writable_but_not_world_writable_cache_dir_is_allowed(): void
    {
        // The documented invariant is about the WORLD bit. A shared service
        // group writing the cache dir (0775) stays lawful — refusing it
        // would break a legitimate deployment the docs never forbade.
        $dir = sys_get_temp_dir() . '/phlix-anidb-grw-' . bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0o775, true));
        $this->assertTrue(chmod($dir, 0o775)); // mkdir honors the umask

        try {
            $this->assertSame(realpath($dir), $this->cacheDirOf($this->provider(['cache_dir' => $dir])));
        } finally {
            @rmdir($dir);
        }
    }

    public function test_not_yet_existing_world_writable_parent_is_not_refused(): void
    {
        // realpath cannot check a directory that does not exist yet (the
        // indexer creates it lazily); the check applies to the configured
        // directory ITSELF when it exists, mirroring the canonicalization
        // rule exactly — a nonexistent path passes through unchanged.
        $wanted = sys_get_temp_dir() . '/phlix-anidb-fresh-' . bin2hex(random_bytes(4));

        $this->assertSame($wanted, $this->cacheDirOf($this->provider(['cache_dir' => $wanted])));
    }
}
