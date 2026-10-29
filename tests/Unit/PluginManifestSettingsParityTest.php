<?php

/**
 * Manifest settings-parity guards (M-ANIDB-02 cure).
 *
 * The title-dump cache dir once resolved through a `$settings['cache_dir']`
 * step that README and code both documented but plugin.json never declared —
 * and the server's PluginAdminController::updateSettings rejects every key
 * missing from the manifest schema ("Unknown setting key."), so the documented
 * knob was a ghost no operator could ever set. These pins make that class of
 * drift impossible: whatever the code consumes from the settings map MUST be
 * declared in the manifest, and the README settings table MUST mirror it.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PluginManifestSettingsParityTest extends TestCase
{
    /**
     * Every key read (or written) through a `$settings[...]` /
     * `$this->settings[...]` subscript anywhere under src/ — code AND prose,
     * since a docblock naming a settings key teaches operators to use it —
     * must exist in the plugin.json settings schema.
     */
    public function test_every_settings_key_consumed_in_source_is_declared_in_the_manifest(): void
    {
        $declared = array_keys($this->manifest()['settings']);
        $consumed = $this->settingsKeysConsumedInSource();

        $ghosts = array_values(array_diff($consumed, $declared));
        $this->assertSame(
            [],
            $ghosts,
            'src/ consumes settings keys that plugin.json does not declare, so the server '
                . 'rejects them on save ("Unknown setting key.") and they are dead: '
                . implode(', ', $ghosts),
        );
    }

    /**
     * The formerly-ghost key, pinned as a first-class optional string setting:
     * removing the declaration (or retyping it) reddens this test, not an
     * operator's silently-failing config dialog.
     */
    public function test_cache_dir_is_declared_as_an_optional_string_setting(): void
    {
        $settings = $this->manifest()['settings'];

        $this->assertArrayHasKey('cache_dir', $settings, 'plugin.json lost its cache_dir declaration');
        $this->assertSame('string', $settings['cache_dir']['type']);
        $this->assertNotTrue(
            $settings['cache_dir']['required'] ?? false,
            'cache_dir must stay optional — its absence is a meaningful fallback signal in resolveCacheDir()',
        );
    }

    /**
     * The README settings table is the operator-facing mirror of the schema;
     * a key in one but not the other is exactly how the cache_dir ghost hid.
     */
    public function test_readme_settings_table_mirrors_the_manifest_keys(): void
    {
        $readme = file_get_contents($this->repoPath('README.md'));
        $this->assertIsString($readme);

        $section = strstr($readme, '## Configuration');
        $this->assertIsString($section, 'README lost its Configuration section');
        $nextHeading = strpos($section, '## ', 3);
        $table = $nextHeading === false ? $section : substr($section, 0, $nextHeading);

        preg_match_all('/^\|\s*`([a-z_]+)`\s*\|/m', $table, $matches);
        $documented = $matches[1];

        sort($documented);
        $declared = array_keys($this->manifest()['settings']);
        sort($declared);

        $this->assertSame(
            $declared,
            $documented,
            'README settings table and plugin.json settings diverged (ghost/missing rows)',
        );
    }

    /**
     * @return list<string> Settings keys referenced via subscript in src/.
     */
    private function settingsKeysConsumedInSource(): array
    {
        $files = iterator_to_array(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->repoPath('src'), \FilesystemIterator::SKIP_DOTS),
            ),
            false,
        );

        $keys = [];
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents);
            if (preg_match_all("/\\\$[a-zA-Z]*[Ss]ettings\\['([a-z_]+)'\\]/", $contents, $matches)) {
                foreach ($matches[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * @return array{settings: array<string, mixed>} Decoded plugin.json.
     */
    private function manifest(): array
    {
        $raw = file_get_contents($this->repoPath('plugin.json'));
        $this->assertIsString($raw, 'plugin.json is unreadable');

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'plugin.json is not valid JSON');
        $this->assertArrayHasKey('settings', $decoded, 'plugin.json lost its settings schema');

        /** @var array{settings: array<string, mixed>} $decoded */
        return $decoded;
    }

    private function repoPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }
}
