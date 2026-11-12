<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ANI-S12: the sys_get_temp_dir() fallback is validated like every other route.
 */
final class CacheDirFallbackHostileTest extends TestCase
{
    private string $tmp;
    private string|false $oldTmpdir;
    private string|false $oldEnv;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/anidb-s12-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700);
        $this->oldTmpdir = getenv('TMPDIR');
        $this->oldEnv = getenv('PHLIX_ANIDB_CACHE_DIR');
        putenv('PHLIX_ANIDB_CACHE_DIR');
        putenv('TMPDIR=' . $this->tmp);
    }

    protected function tearDown(): void
    {
        putenv($this->oldTmpdir === false ? 'TMPDIR' : 'TMPDIR=' . $this->oldTmpdir);
        putenv($this->oldEnv === false ? 'PHLIX_ANIDB_CACHE_DIR' : 'PHLIX_ANIDB_CACHE_DIR=' . $this->oldEnv);
        @unlink($this->tmp . '/phlix-plugin-anidb');
        @rmdir($this->tmp . '/phlix-plugin-anidb');
        @rmdir($this->tmp);
    }

    private function resolve(): string
    {
        $p = (new \ReflectionClass(AnidbMetadataProvider::class))->newInstanceWithoutConstructor();
        $f = new \ReflectionProperty($p, 'fallbackTempBase');
        $f->setAccessible(true);
        $f->setValue($p, $this->tmp);
        $m = new \ReflectionMethod($p, 'resolveCacheDir');
        $m->setAccessible(true);

        return $m->invoke($p, [], null);
    }

    public function test_dangling_symlink_fallback_is_refused(): void
    {
        symlink($this->tmp . '/nowhere', $this->tmp . '/phlix-plugin-anidb');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('symlink');
        $this->resolve();
    }

    public function test_world_writable_fallback_is_refused(): void
    {
        mkdir($this->tmp . '/phlix-plugin-anidb', 0777);
        chmod($this->tmp . '/phlix-plugin-anidb', 0777);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('world-writable');
        $this->resolve();
    }

    public function test_owned_private_fallback_is_accepted(): void
    {
        mkdir($this->tmp . '/phlix-plugin-anidb', 0700);
        $this->assertSame(realpath($this->tmp . '/phlix-plugin-anidb'), $this->resolve());
    }
}
