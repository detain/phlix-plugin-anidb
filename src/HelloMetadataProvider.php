<?php

declare(strict_types=1);

namespace Phlex\PluginExample;

use Phlex\Plugins\Contract\LifecycleInterface;
use Psr\Container\ContainerInterface;

/**
 * Reference metadata-provider plugin for Phlex.
 *
 * Demonstrates the smallest functional plugin that satisfies the Phlex
 * plugin contract introduced in Step A.4 of `PHLEX_EXPANSION_PLAN.md`.
 * The plugin implements {@see LifecycleInterface} so the
 * `Phlex\Plugins\PluginLoader` can instantiate, enable, and disable it,
 * and exposes a {@see self::lookup()} method that returns a fixed
 * greeting for a well-known fixture path. The greeting itself is
 * configurable through the plugin's `greeting` setting in `plugin.json`.
 *
 * Plugin authors are expected to fork this repository as a starter and
 * progressively replace {@see lookup()} with real metadata lookups
 * against TMDB, TVDB, Fanart.tv, or any other source.
 *
 * ## Lifecycle notes
 *
 * - {@see onEnable()} keeps state minimal — it only stashes the host
 *   container so {@see lookup()} can resolve services lazily if a
 *   future implementation needs them.
 * - {@see subscribedEvents()} returns an empty array; this plugin does
 *   not listen for PSR-14 events. A real metadata provider can leave
 *   this empty too — Phase A's `MetadataManager` invokes providers
 *   directly rather than via the dispatcher.
 *
 * ## Provenance
 *
 * The `LifecycleInterface` lives in `Phlex\Plugins\Contract` today;
 * Step B.1 of the expansion plan moves it to
 * `Phlex\Shared\Plugin\LifecycleInterface`. When that ships this class
 * will be republished against the new namespace; until then, plugin
 * authors targeting master pin to the current FQCN.
 *
 * @link https://github.com/detain/phlex/blob/master/docs/plugins/developer-guide.md Phlex plugin developer guide
 * @package Phlex\PluginExample
 * @since 0.1.0
 */
final class HelloMetadataProvider implements LifecycleInterface
{
    /**
     * Filesystem path that {@see lookup()} treats as the canonical
     * fixture. The README documents this constant so anyone smoke-testing
     * the plugin knows what to type into the metadata picker.
     */
    public const FIXTURE_PATH = '/test/hello.mkv';

    /**
     * Default greeting used when the plugin's `greeting` setting has not
     * been overridden in `plugin.json`. Mirrors the default declared in
     * the manifest so the in-class fallback matches what the loader
     * persists at install time.
     */
    private const DEFAULT_GREETING = 'Hello, World';

    /**
     * Greeting returned as the metadata title. Defaults to
     * {@see DEFAULT_GREETING}; can be overridden via the constructor
     * for tests or by future settings wiring (Phase A.6 reads the
     * default; per-install overrides come with Phase A.7 settings UI).
     */
    private string $greeting;

    /**
     * Host container handle stashed during {@see onEnable()}. Real
     * provider implementations resolve `LoggerInterface`, HTTP clients,
     * or cache services through it; the example does not yet need it
     * but keeping the reference makes the extension point obvious for
     * authors copying this class as a starter.
     */
    private ?ContainerInterface $container = null;

    /**
     * @param string $greeting Override for the greeting returned by
     *     {@see lookup()}. Tests pass a custom value; production
     *     instantiations rely on the manifest default.
     */
    public function __construct(string $greeting = self::DEFAULT_GREETING)
    {
        $this->greeting = $greeting;
    }

    /**
     * Loader hook called once when the plugin is enabled.
     *
     * Stashes the host container so later metadata lookups can resolve
     * services lazily. Real providers might also open an HTTP client or
     * warm a cache here — keep it cheap; the loader is in the request
     * path.
     *
     * @param ContainerInterface $container Host PSR-11 container.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function onEnable(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    /**
     * Loader hook called once when the plugin is disabled.
     *
     * Releases the stashed container reference. Plugins that opened
     * clients in {@see onEnable()} should close them here so the
     * lifecycle is symmetric.
     *
     * @return void
     *
     * @since 0.1.0
     */
    public function onDisable(): void
    {
        $this->container = null;
    }

    /**
     * Returns the PSR-14 listener subscriptions this plugin wants.
     *
     * Metadata providers are invoked synchronously by
     * `Phlex\Media\Metadata\MetadataManager` and therefore do not need
     * to subscribe to playback or library events. Returning an empty
     * array keeps the loader happy.
     *
     * @return array<class-string, string|callable> Always empty for the
     *     reference plugin.
     *
     * @since 0.1.0
     */
    public function subscribedEvents(): array
    {
        return [];
    }

    /**
     * Demonstration metadata-lookup entry point.
     *
     * Returns the configured greeting as the title when called with the
     * well-known {@see FIXTURE_PATH}; returns an empty array for any
     * other input. A production provider would parse the path, hit an
     * upstream API, and merge the response into the shape documented in
     * `Phlex\Media\Metadata\MetadataProviderInterface::getDetails()`.
     *
     * @param string $filePath Absolute filesystem path of the media
     *     item the host wants metadata for.
     *
     * @return array{title: string}|array{} Matched fixture payload or
     *     an empty array when the path is not recognised.
     *
     * @since 0.1.0
     */
    public function lookup(string $filePath): array
    {
        if ($filePath !== self::FIXTURE_PATH) {
            return [];
        }

        return [
            'title' => $this->greeting,
        ];
    }
}
