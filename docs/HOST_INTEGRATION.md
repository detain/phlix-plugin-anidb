# Host integration — AniDB metadata provider

How this plugin's metadata reaches the Phlix server, and how the server consumes
it today.

> Status: **Q1 viability gate resolved → Option A; server-side consumption has
> since LANDED.** The plugin self-registers a host-contract adapter, and the
> server now drives plugin metadata sources end-to-end (see
> "Server-side consumption (landed)" below). Cross-repo line numbers are avoided
> on purpose — they drift; anchors below are file + symbol.

## How the host invokes this plugin (Option A)

The host plugin pipeline never calls `lookup()` — there is no code path that does.
Consumption happens through the host metadata registry instead:

1. **Enable.** `Phlix\Plugins\PluginLoader::enable()`
   (`phlix-server/src/Plugins/PluginLoader.php`) `require_once`'s the plugin's own
   `vendor/autoload.php`, resolves the entry class
   `Phlix\Anidb\AnidbMetadataProvider` from the host PSR-11 container,
   asserts it `instanceof Phlix\Shared\Plugin\LifecycleInterface`, then
   calls `->onEnable($container)`.

2. **Self-registration.** `AnidbMetadataProvider::onEnable()`
   (`src/AnidbMetadataProvider.php`) resolves the host
   `Phlix\Media\Metadata\MetadataManager` from the container and calls
   `registerProvider('anidb', $this->adapter(), $this->supportedMediaTypes())`, where
   `supportedMediaTypes()` returns `['series', 'movie']` — the media types the host
   actually keys lookups by. AniDB is anime *content*, but "anime" is not a host
   media-type: series items are matched under `series` and anime movies under
   `movie` (pinned by `test_supported_media_types_are_series_and_movie_only`).
   This mirrors the built-in `Phlix\Plugins\Oidc\Plugin::onEnable()`
   (`phlix-server/src/Plugins/Oidc/Plugin.php`) and
   `Phlix\Plugins\Ldap\Plugin::onEnable()`
   (`phlix-server/src/Plugins/Ldap/Plugin.php`), which resolve
   `AuthProviderRegistry` from the container and `registerProvider(...)` against it.

3. **Consumption.** `MetadataManager::registerProvider()`
   (`phlix-server/src/Media/Metadata/MetadataManager.php`) stores the provider in
   `providersByType` / `providers`. `MetadataManager::refreshItemMetadata()`
   → `getProvidersForType()` → `tryProvider()` then calls
   `$provider->search()`, `$provider->getDetails()` and `$provider->getImages()`
   on each registered provider in priority order. The adapter implements exactly
   those methods. Additionally, `AnidbMetadataProvider` itself implements
   `Phlix\Shared\Metadata\MetadataSourceInterface`, so the server's
   `PluginLoader::enable()` registers the provider instance into the
   `SourceRegistry` as a first-class plugin metadata source — see
   "Server-side consumption (landed)" below.

### Why `MetadataProviderInterface` is reachable from the plugin

`Phlix\Media\Metadata\MetadataProviderInterface`
(`phlix-server/src/Media/Metadata/MetadataProviderInterface.php`) lives in the
**server** repo (PSR-4 `Phlix\` => `src/`, `phlix-server/composer.json`), not in
`phlix-shared` and not in this plugin's `vendor/`. That is fine at runtime: Phlix is
a resident-memory Workerman process, so the server's Composer autoloader is already
registered when the loader `require_once`'s the plugin autoloader. Requiring the
plugin autoloader ADDS the `Phlix\Anidb\` prefix; it never unregisters the server's
`Phlix\` => `src/` mapping. So the interface resolves in production, and
`$container->get(MetadataManager::class)` resolves because the manager is bound in
`phlix-server/src/Common/Container/Providers/MediaServicesProvider.php`
(`MetadataManager::class => autowire()`).

For unit tests (no server checkout) the test bootstrap defines a byte-for-byte
stub of the interface at the same FQCN — see `tests/Stub/MetadataProviderInterface.php`
and the `interface_exists()` guard in `tests/bootstrap.php`.

## Server-side consumption (landed)

*(Verified against phlix-server master `f1a13efc`, 2026-10; anchors are file +
symbol, not line numbers — they drift.)*

The "will anime ever be consumed?" question this document used to park is
**resolved on the server side**:

- **`SourceRegistry` registration.** `PluginLoader::enable()`
  (`phlix-server/src/Plugins/PluginLoader.php`) registers any enabled plugin
  instance that implements `Phlix\Shared\Metadata\MetadataSourceInterface`
  into `Phlix\Media\Metadata\Resolution\SourceRegistry`.
  `AnidbMetadataProvider` implements that interface, so the plugin is a
  first-class registered metadata source, not just a self-registration guest.
- **Resolvers consult plugin sources.** `MovieMetadataResolver` /
  `SeriesMetadataResolver` (`phlix-server/src/Media/Metadata/`) take a
  `SourceRegistry` and consult plugin sources when `resolve()` runs with
  `$includePluginSources = true` (`Phlix\Media\Metadata\Resolution\PluginSourceConsultation`
  is the shared consultation helper). Bulk scans keep the flag off so they
  never hit third-party quotas; enrichment of scanned items happens through
  the background path instead.
- **Background enrichment.** `Phlix\Media\Metadata\Enrichment\PluginMetadataEnricher`
  (`enrichOne()`) drains ONE queued media item per tick and gap-fills it from
  the enabled plugin metadata sources — its own docblock names
  `omdb / anidb / myanimelist` — off the scan path, rate-limited by
  `SourceRateLimiter` and driven by `BackgroundEnrichmentSubscriber`.
- **The container `MetadataManager` is live on the movie/series path.** The
  throwaway `new \Phlix\Media\Metadata\MetadataManager(...)` inside
  `Application.php` is gone (zero hits); the manager resolves as the container
  singleton (`MediaServicesProvider` binding), which is the same instance the
  plugin's `onEnable()` self-registration above registers against. The music
  path (`MusicLibraryManager::` → `refreshItemMetadata()`) continues to drive
  the container manager as before.

So an enabled anidb plugin participates in movie/series enrichment today via
BOTH routes: the typed `MetadataSourceInterface` → `SourceRegistry` path
(background enricher) and the adapter → container `MetadataManager` path
(`search`/`getDetails`/`getImages` during `refreshItemMetadata`). No
outstanding cross-repo wiring task remains for consumption; future server-side
changes are tracked in the phlix-server repo, not here.
