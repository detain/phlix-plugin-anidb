<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\AnidbMetadataProvider;
use Phlix\Anidb\AnidbMetadataProviderAdapter;
use Phlix\Media\Metadata\MetadataProviderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for the host-interface bridge (Step Q1, Option A).
 *
 * Asserts that:
 *  - the adapter satisfies the host MetadataProviderInterface contract and
 *    advertises the correct source name / provider aliases;
 *  - onEnable() resolves a MetadataManager from the host container and calls
 *    registerProvider() on it — i.e. the plugin's metadata is now wired into
 *    the host's consumption path (the gap Q1 was raised to close).
 */
final class AnidbMetadataProviderAdapterTest extends TestCase
{
    /**
     * Build a provider with inert settings (no socket opened in these tests).
     *
     * @return AnidbMetadataProvider
     */
    private function makeProvider(): AnidbMetadataProvider
    {
        return new AnidbMetadataProvider([
            'username'       => 'testuser',
            'api_key'        => 'testkey',
            'use_title_dump' => false,
            'title_dump_url' => 'http://example.com/anime-titles.dat.gz',
        ]);
    }

    public function test_adapter_implements_host_metadata_provider_interface(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $this->assertInstanceOf(MetadataProviderInterface::class, $adapter);
    }

    public function test_get_source_name_returns_anidb(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $this->assertSame('anidb', $adapter->getSourceName());
        $this->assertSame(AnidbMetadataProviderAdapter::SOURCE_NAME, $adapter->getSourceName());
    }

    public function test_get_providers_returns_anidb_alias(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $this->assertSame(['anidb'], $adapter->getProviders());
    }

    public function test_get_details_with_invalid_external_id_returns_empty(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        // Non-numeric / zero / empty ids must short-circuit before any network I/O.
        $this->assertSame([], $adapter->getDetails('not-an-aid'));
        $this->assertSame([], $adapter->getDetails('0'));
        $this->assertSame([], $adapter->getDetails(''));
    }

    public function test_get_images_with_invalid_external_id_returns_empty(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $this->assertSame([], $adapter->getImages('not-an-aid'));
    }

    /**
     * The core Q1 assertion: enabling the plugin must register an adapter that
     * implements the host contract with the host MetadataManager (resolved from
     * the host container) under the 'anidb' name for anime/series types.
     *
     * The "MetadataManager" here is a runtime stand-in object exposing the same
     * registerProvider(string, MetadataProviderInterface, array) signature; the
     * provider resolves it from a mocked PSR-11 container exactly as it would the
     * real one.
     */
    public function test_on_enable_registers_adapter_with_metadata_manager(): void
    {
        $managerClass = 'Phlix\\Media\\Metadata\\MetadataManager';

        // A spy that records the registerProvider() call arguments.
        $manager = new class {
            /** @var array{0: string, 1: object, 2: array<int, string>}|null */
            public ?array $registered = null;

            /**
             * @param array<int, string> $supportedTypes
             */
            public function registerProvider(string $name, object $provider, array $supportedTypes = []): void
            {
                $this->registered = [$name, $provider, $supportedTypes];
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn (string $id): bool => $id === $managerClass);
        $container->method('get')
            ->willReturnCallback(static function (string $id) use ($managerClass, $manager) {
                if ($id === $managerClass) {
                    return $manager;
                }
                throw new \RuntimeException('unexpected container id: ' . $id);
            });

        // Drive ONLY the registration step (avoid opening a real UDP socket) by
        // invoking the private registerWithMetadataManager() via reflection —
        // this is the exact call onEnable() makes after authenticate().
        $provider = $this->makeProvider();
        $ref = new \ReflectionMethod($provider, 'registerWithMetadataManager');
        $ref->setAccessible(true);
        $ref->invoke($provider, $container);

        $this->assertNotNull($manager->registered, 'registerProvider() was not called');
        [$name, $registeredProvider, $types] = $manager->registered;

        $this->assertSame('anidb', $name);
        $this->assertInstanceOf(AnidbMetadataProviderAdapter::class, $registeredProvider);
        $this->assertInstanceOf(MetadataProviderInterface::class, $registeredProvider);
        $this->assertSame(['series', 'movie'], $types);
    }

    public function test_on_enable_registration_is_noop_when_manager_absent(): void
    {
        // Container without the MetadataManager entry: registration must be a
        // graceful no-op (plugin still usable, no throw).
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $container->expects($this->never())->method('get');

        $provider = $this->makeProvider();
        $ref = new \ReflectionMethod($provider, 'registerWithMetadataManager');
        $ref->setAccessible(true);

        // Must not throw.
        $ref->invoke($provider, $container);
        $this->addToAssertionCount(1);
    }

    public function test_get_images_with_whitespace_aid_returns_empty(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $this->assertSame([], $adapter->getImages('   '));
    }

    public function test_get_details_with_zero_aid_returns_empty(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $this->assertSame([], $adapter->getDetails('0'));
    }

    public function test_get_images_with_zero_aid_returns_empty(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $this->assertSame([], $adapter->getImages('0'));
    }

    public function test_parse_aid_rejects_negative_numbers(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        // Negative number strings are rejected by parseAid
        $this->assertSame([], $adapter->getDetails('-1'));
        $this->assertSame([], $adapter->getImages('-1'));
    }

    public function test_parse_aid_rejects_non_numeric_strings(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $this->assertSame([], $adapter->getDetails('abc'));
        $this->assertSame([], $adapter->getImages('abc'));
    }

    public function test_parse_aid_accepts_valid_positive_integer_strings(): void
    {
        // Use reflection to test parseAid directly since we can't mock the provider
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $ref = new \ReflectionClass(AnidbMetadataProviderAdapter::class);
        $method = $ref->getMethod('parseAid');
        $method->setAccessible(true);

        // Valid cases - positive integers should parse correctly
        $this->assertSame(1, $method->invoke($adapter, '1'));
        $this->assertSame(12345, $method->invoke($adapter, '12345'));
        $this->assertSame(999999, $method->invoke($adapter, '999999'));
    }

    public function test_parse_aid_rejects_invalid_inputs(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $ref = new \ReflectionClass(AnidbMetadataProviderAdapter::class);
        $method = $ref->getMethod('parseAid');
        $method->setAccessible(true);

        // Invalid cases
        $this->assertNull($method->invoke($adapter, ''));
        $this->assertNull($method->invoke($adapter, '   '));
        $this->assertNull($method->invoke($adapter, '0'));
        $this->assertNull($method->invoke($adapter, '-1'));
        $this->assertNull($method->invoke($adapter, '1.5'));
        $this->assertNull($method->invoke($adapter, '1a'));
        $this->assertNull($method->invoke($adapter, 'a1'));
    }

    public function test_string_or_returns_value_when_non_empty_string(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $ref = new \ReflectionClass(AnidbMetadataProviderAdapter::class);
        $method = $ref->getMethod('stringOr');
        $method->setAccessible(true);

        $this->assertSame('actual value', $method->invoke($adapter, 'actual value', 'fallback'));
    }

    public function test_string_or_returns_fallback_when_value_is_null(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $ref = new \ReflectionClass(AnidbMetadataProviderAdapter::class);
        $method = $ref->getMethod('stringOr');
        $method->setAccessible(true);

        $this->assertSame('fallback', $method->invoke($adapter, null, 'fallback'));
    }

    public function test_string_or_returns_fallback_when_value_is_empty_string(): void
    {
        $adapter = new AnidbMetadataProviderAdapter($this->makeProvider());

        $ref = new \ReflectionClass(AnidbMetadataProviderAdapter::class);
        $method = $ref->getMethod('stringOr');
        $method->setAccessible(true);

        $this->assertSame('fallback', $method->invoke($adapter, '', 'fallback'));
    }
}
