<?php

/**
 * Settings normalization pins (PG-157, review anidb__srphp #8[m]).
 *
 * AnidbMetadataProvider used to store the host-delivered settings map RAW —
 * while its own docblocks declared `array{username: string, api_key: string,
 * use_title_dump: bool, ...}`. The shape was documentation-time only: a
 * partial or wrongly-typed map made the hot paths (`use_title_dump` reads in
 * onEnable()/findAidByTitle(), credential subscripts in UdpClient::
 * authenticate()) emit undefined-array-key / type warnings once per lookup
 * inside a resident worker, and a string "false" read truthy. The
 * constructor now normalizes once — plugin.json declared defaults applied,
 * types checked — so every later read is from the declared shape.
 * requireCredentials() (UdpClient) stays as the runtime safety net: a fresh
 * install with no credentials MUST construct, and the descriptive throw
 * still lands at AUTH time, not at enable time.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use Phlix\Anidb\TitleDump\TitleDumpUrlMigration;
use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class SettingsNormalizationTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     *
     * @return array{0: AnidbMetadataProvider, 1: UdpClientInterface}
     */
    private function provider(array $settings): array
    {
        $transport = new class implements UdpClientInterface {
            /** @var list<string> */
            public array $commands = [];

            public function open(): void
            {
            }

            public function send(string $data): ?string
            {
                $this->commands[] = $data;

                return null;
            }

            public function close(): void
            {
            }

            public function lastReplyHost(): ?string
            {
                return 'api.anidb.net';
            }

            public function lastReplyPort(): ?int
            {
                return 9000;
            }
        };

        $waiter = new class implements WaiterInterface {
            public function wait(float $seconds): void
            {
            }
        };

        // Deliberately do NOT inject a credential-complete UdpClient: the
        // point of these pins is that the provider's OWN normalization feeds
        // the session, so build the default session from the (normalized)
        // settings exactly as production does.
        $provider = new AnidbMetadataProvider($settings, $transport, $waiter);

        return [$provider, $transport];
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function settingsOf(array $settings): array
    {
        [$provider] = $this->provider($settings);

        $reflection = new \ReflectionProperty($provider, 'settings');
        $reflection->setAccessible(true);

        /** @var array<string, mixed> $value */
        $value = $reflection->getValue($provider);

        return $value;
    }

    /**
     * Collect every E_WARNING/E_NOTICE the call emits (undefined-array-key
     * and type-warning class — the exact artifacts the register names) and
     * hand back the call's result.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function withoutPhpDiagnostics(callable $call): mixed
    {
        $diagnostics = [];
        set_error_handler(static function (int $severity, string $message) use (&$diagnostics): bool {
            $diagnostics[] = $message;

            return true;
        });

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $diagnostics, 'normalized reads must never warn: ' . implode(' | ', $diagnostics));

        return $result;
    }

    public function test_empty_settings_map_constructs_without_warnings_and_carries_declared_defaults(): void
    {
        $settings = $this->settingsOf([]);

        $this->assertSame('', $settings['username']);
        $this->assertSame('', $settings['api_key']);
        $this->assertTrue($settings['use_title_dump'], 'plugin.json declares the title dump default true');
        $this->assertSame(TitleDumpUrlMigration::DEFAULT_URL, $settings['title_dump_url']);
    }

    public function test_partial_map_emits_no_warnings_on_the_declared_reads(): void
    {
        // The old defect shape: the host delivers a subset (fresh install,
        // pre-registration). Every declared-key read must be warning-free.
        $this->withoutPhpDiagnostics(function (): void {
            $provider = new AnidbMetadataProvider(['username' => 'only-user']);
            $reflection = new \ReflectionMethod($provider, 'findAidByTitle');
            $reflection->setAccessible(true);

            try {
                // Declared reads first (:767 truthiness), then the credential
                // safety net fires its descriptive RuntimeException — which is
                // an EXCEPTION, never a per-lookup warning stream.
                $reflection->invoke($provider, 'Cowboy Bebop');
            } catch (\RuntimeException $expected) {
                $this->assertStringContainsString('the "AniDB Username" and "AniDB UDP API Key" settings are required', $expected->getMessage());
            }
        });
    }

    public function test_non_string_settings_are_coerced_to_the_declared_type_without_throwing(): void
    {
        // A wrongly-typed map must not explode at enable time (fresh installs
        // and stale rows legitimately carry nulls); it lands on the declared
        // default and is reported via error_log, not PHP warnings.
        $dir = sys_get_temp_dir() . '/anidb_norm_' . bin2hex(random_bytes(8));
        mkdir($dir, 0755, true);
        $logFile = $dir . '/stderr.log';
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $settings = $this->withoutPhpDiagnostics(function (): array {
                $provider = new AnidbMetadataProvider([
                    'username' => 42,
                    'api_key' => ['array'],
                    'use_title_dump' => 'not-a-bool',
                ]);
                $reflection = new \ReflectionProperty($provider, 'settings');
                $reflection->setAccessible(true);

                /** @var array<string, mixed> $value */
                $value = $reflection->getValue($provider);

                return $value;
            });

            $this->assertSame('', $settings['username']);
            $this->assertSame('', $settings['api_key']);
            $this->assertTrue($settings['use_title_dump'], 'uncoercible boolean falls to the declared default');

            $logged = file_get_contents($logFile);
            $this->assertIsString($logged);
            $this->assertStringContainsString('username', $logged);
            $this->assertStringContainsString('api_key', $logged);
            $this->assertStringContainsString('use_title_dump', $logged);
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '');
            @unlink($logFile);
            @rmdir($dir);
        }
    }

    /**
     * plugin.json's declared boolean is the DEFAULT, not a forced true:
     * stringly "false"/"0"/"off" and int 0 are unambiguous operator "no"
     * answers and must normalize to false, not read truthy as raw strings
     * did.
     *
     * @return array<string, array{mixed, bool}>
     */
    public static function booleanCoercionProvider(): array
    {
        return [
            'native true'    => [true, true],
            'native false'   => [false, false],
            'string 1'       => ['1', true],
            'string 0'       => ['0', false],
            'string true'    => ['true', true],
            'string false'   => ['false', false],
            'int 0'          => [0, false],
            'int 1'          => [1, true],
        ];
    }

    #[DataProvider('booleanCoercionProvider')]
    public function test_use_title_dump_normalizes_to_a_declared_bool(mixed $stored, bool $expected): void
    {
        $settings = $this->settingsOf(['use_title_dump' => $stored]);

        $this->assertSame($expected, $settings['use_title_dump']);
    }

    public function test_legacy_http_anidb_url_still_migrates_through_normalization(): void
    {
        // The SM-0.3 fix-up runs inside normalization — a stored plain-http
        // AniDB URL still arrives at the indexer as https.
        $dir = sys_get_temp_dir() . '/anidb_norm_' . bin2hex(random_bytes(8));
        mkdir($dir, 0755, true);
        $logFile = $dir . '/stderr.log';
        $previous = ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $settings = $this->settingsOf([
                'username' => 'u',
                'api_key' => 'k',
                'title_dump_url' => 'http://anidb.net/api/anime-titles.dat.gz',
            ]);

            $this->assertSame('https://anidb.net/api/anime-titles.dat.gz', $settings['title_dump_url']);

            $logged = file_get_contents($logFile);
            $this->assertIsString($logged);
            $this->assertStringContainsString('migrated stored title_dump_url', $logged);
        } finally {
            ini_set('error_log', is_string($previous) ? $previous : '');
            @unlink($logFile);
            @rmdir($dir);
        }
    }

    public function test_normalized_settings_are_what_the_udp_session_receives(): void
    {
        // The provider hands $this->settings to the default UdpClient; pin
        // that the normalized map (not the raw host map) is what the AUTH
        // credential lookup reads, so a non-string username can never reach
        // urlencode() as a type-warning generator.
        $provider = new AnidbMetadataProvider(
            ['username' => 'real-user', 'api_key' => 'real-key', 'use_title_dump' => false],
            new class implements UdpClientInterface {
                public function open(): void
                {
                }

                public function send(string $data): ?string
                {
                    return null;
                }

                public function close(): void
                {
                }

                public function lastReplyHost(): ?string
                {
                    return 'api.anidb.net';
                }

                public function lastReplyPort(): ?int
                {
                    return 9000;
                }
            },
            new class implements WaiterInterface {
                public function wait(float $seconds): void
                {
                }
            },
        );

        $session = new \ReflectionProperty($provider, 'udpSession');
        $session->setAccessible(true);
        /** @var UdpClient $udpSession */
        $udpSession = $session->getValue($provider);

        $sessionSettings = new \ReflectionProperty(UdpClient::class, 'settings');
        $sessionSettings->setAccessible(true);
        /** @var array<string, mixed> $handed */
        $handed = $sessionSettings->getValue($udpSession);

        $this->assertSame('real-user', $handed['username']);
        $this->assertSame('real-key', $handed['api_key']);
        $this->assertFalse($handed['use_title_dump']);
        $this->assertSame(TitleDumpUrlMigration::DEFAULT_URL, $handed['title_dump_url']);
    }
}
