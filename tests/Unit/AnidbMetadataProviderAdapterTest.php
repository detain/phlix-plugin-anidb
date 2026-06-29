<?php

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
        $this->assertSame(['anime', 'series'], $types);
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
}
