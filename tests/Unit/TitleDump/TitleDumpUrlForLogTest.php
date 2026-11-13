<?php

/**
 * PG-159 (review anidb__secaudit #2[m]) pins: ONE urlForLog scrubber for
 * every dump-URL echo site.
 *
 * The credential-echo policy was inconsistent: the constructor scheme-guard
 * refused to echo the URL "it may embed userinfo", the cleartext warning
 * logged only the parsed host — yet the constructor migration log echoed the
 * stored URL verbatim and httpClientConfig embedded the full URL in a thrown
 * RuntimeException under a comment claiming the URL was credential-free "by
 * contract". No such contract existed: nothing rejects or strips a
 * user-and-password component of an http(s) URL, and TitleDumpUrlMigration
 * preserves userinfo across the scheme swap — so an operator-configured
 * `http://user:pass@anidb.net/...` landed BOTH credentials-bearing strings in
 * the shared error log, and a userinfo URL that failed the host check landed
 * its credentials in every exception log that renders the throw.
 *
 * urlForLog makes every echo honest by construction: userinfo is replaced
 * with `***`, a credential-free parseable URL passes through byte-identically
 * (existing diagnostic output is preserved), and anything parse_url cannot
 * prove credential-free — malformed or a scheme-less userinfo shape — is
 * withheld instead of echoed.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit\TitleDump;

use Phlix\Anidb\AnidbMetadataProvider;
use Phlix\Anidb\TitleDump\TitleDumpIndexer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;

final class TitleDumpUrlForLogTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function urlShapesProvider(): array
    {
        return [
            'plain https mirror passes through unchanged' => [
                'https://anidb.net/api/anime-titles.dat.gz',
                'https://anidb.net/api/anime-titles.dat.gz',
            ],
            'query and fragment pass through unchanged' => [
                'https://mirror.example/d.dat.gz?v=2#top',
                'https://mirror.example/d.dat.gz?v=2#top',
            ],
            'path-only string (no authority) passes through' => [
                'notaurl',
                'notaurl',
            ],
            'full userinfo is replaced, rest preserved' => [
                'http://user:sup3rs3cr3t@mirror.example/d.dat.gz',
                'http://***@mirror.example/d.dat.gz',
            ],
            'user-only userinfo is replaced' => [
                'https://user@anidb.net/api/anime-titles.dat.gz',
                'https://***@anidb.net/api/anime-titles.dat.gz',
            ],
            'port, path and query survive the scrub' => [
                'http://u:p@mirror.example:8080/a/b.dat.gz?v=2',
                'http://***@mirror.example:8080/a/b.dat.gz?v=2',
            ],
            'IPv6 host keeps its brackets' => [
                'http://u:p@[::1]:8080/a.dat.gz',
                'http://***@[::1]:8080/a.dat.gz',
            ],
            'unparseable URL is withheld, never echoed' => [
                'http://host:notaport/foo',
                '(title_dump_url withheld — credentials suspected)',
            ],
            'scheme-less userinfo shape is withheld' => [
                'user:sup3rs3cr3t@evil.example.com/d.dat.gz',
                '(title_dump_url withheld — credentials suspected)',
            ],
        ];
    }

    #[DataProvider('urlShapesProvider')]
    public function test_url_for_log_scrubs_userinfo_by_construction(string $input, string $expected): void
    {
        $this->assertSame($expected, TitleDumpIndexer::urlForLog($input));
    }

    public function test_url_for_log_never_carries_the_password_it_was_given(): void
    {
        // The sweep that matters: no input shape may leave the probe
        // password in the rendered string, whatever else the output looks
        // like. (Exact per-shape outputs are pinned by the dataProvider.)
        foreach (self::urlShapesProvider() as $case) {
            $scrubbed = TitleDumpIndexer::urlForLog($case[0]);
            $this->assertStringNotContainsString('sup3rs3cr3t', $scrubbed, $case[0]);
        }
    }

    /**
     * The throw site the register names: the no-host RuntimeException used to
     * embed the full URL under a false "credential-free by contract" claim.
     * The message must still name the failure (and, for genuinely credential-
     * free host-less shapes, still echo the URL — diagnostics stay intact),
     * but a userinfo-bearing input must never reach the message.
     */
    public function test_http_client_config_throw_does_not_echo_userinfo(): void
    {
        try {
            TitleDumpIndexer::httpClientConfig('http://user:sup3rs3cr3t@/anime-titles.dat.gz');
            $this->fail('expected the no-host RuntimeException');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no host to verify', $exception->getMessage());
            $this->assertStringNotContainsString('sup3rs3cr3t', $exception->getMessage());
            $this->assertStringNotContainsString('user:', $exception->getMessage());
        }
    }

    public function test_http_client_config_throw_still_echoes_credential_free_hostless_urls(): void
    {
        // Provable-credential-free shape (no authority at all): the loud echo
        // stays — this is the diagnostic the old comment was protecting.
        try {
            TitleDumpIndexer::httpClientConfig('notaurl');
            $this->fail('expected the no-host RuntimeException');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('notaurl', $exception->getMessage());
        }
    }

    /**
     * The provider migration log — the register's verbatim-echo site. A
     * legacy http AniDB URL with userinfo migrates to https (userinfo
     * SURVIVES TitleDumpUrlMigration) and both sides used to hit error_log
     * raw. Both must now be scrubbed while the line stays diagnosable.
     */
    public function test_provider_migration_log_scrubs_userinfo_on_both_sides(): void
    {
        $dir = sys_get_temp_dir() . '/anidb_log_' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        $logFile = $dir . '/error.log';
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            new AnidbMetadataProvider(
                [
                    'username' => 'u',
                    'api_key' => 'k',
                    'cache_dir' => $dir,
                    'title_dump_url' => 'http://user:sup3rs3cr3t@anidb.net/api/anime-titles.dat.gz',
                ],
            );

            $logged = (string) file_get_contents($logFile);
            $this->assertStringContainsString('migrated stored title_dump_url', $logged);
            $this->assertStringContainsString('"http://***@anidb.net/api/anime-titles.dat.gz"', $logged);
            $this->assertStringContainsString('"https://***@anidb.net/api/anime-titles.dat.gz"', $logged);
            $this->assertStringNotContainsString('sup3rs3cr3t', $logged);
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '');
            @unlink($logFile);
            @rmdir($dir);
        }
    }

    /**
     * Backward compatibility of the scrubber at the same site: the ordinary
     * (credential-free) migration log keeps its exact prior rendering, so the
     * pin SettingsNormalizationTest already carries stays green and operators
     * lose no diagnostic detail.
     */
    public function test_provider_migration_log_is_verbatim_for_credential_free_urls(): void
    {
        $dir = sys_get_temp_dir() . '/anidb_log_' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        $logFile = $dir . '/error.log';
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            new AnidbMetadataProvider(
                [
                    'username' => 'u',
                    'api_key' => 'k',
                    'cache_dir' => $dir,
                    'title_dump_url' => 'http://anidb.net/api/anime-titles.dat.gz',
                ],
            );

            $logged = (string) file_get_contents($logFile);
            $this->assertStringContainsString(
                'migrated stored title_dump_url "http://anidb.net/api/anime-titles.dat.gz"'
                . ' -> "https://anidb.net/api/anime-titles.dat.gz"',
                $logged,
            );
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '');
            @unlink($logFile);
            @rmdir($dir);
        }
    }
}
