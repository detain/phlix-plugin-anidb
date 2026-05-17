<?php

declare(strict_types=1);

namespace Phlex\PluginExample\Tests;

use Phlex\PluginExample\HelloMetadataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests for the reference {@see HelloMetadataProvider} plugin.
 *
 * Mirrors the behavioural contract that the server-side
 * `SamplePluginSmokeTest` (in `detain/phlex`) asserts at the
 * integration boundary so the plugin can be developed in isolation
 * before it is published.
 */
final class HelloMetadataProviderTest extends TestCase
{
    public function test_returns_hello_world_for_known_fixture_path(): void
    {
        $provider = new HelloMetadataProvider();

        $result = $provider->lookup(HelloMetadataProvider::FIXTURE_PATH);

        $this->assertSame(['title' => 'Hello, World'], $result);
    }

    public function test_returns_empty_for_unknown_path(): void
    {
        $provider = new HelloMetadataProvider();

        $this->assertSame([], $provider->lookup('/library/movies/unknown.mkv'));
    }

    public function test_constructor_greeting_overrides_default(): void
    {
        $provider = new HelloMetadataProvider('Hola, Mundo');

        $this->assertSame(
            ['title' => 'Hola, Mundo'],
            $provider->lookup(HelloMetadataProvider::FIXTURE_PATH),
        );
    }

    public function test_subscribed_events_is_empty(): void
    {
        $provider = new HelloMetadataProvider();

        $this->assertSame([], $provider->subscribedEvents());
    }
}
