# Host integration — AniDB metadata provider

How this plugin's metadata reaches the Phlix server, and the one server-side
follow-up needed for full end-to-end consumption.

> Status: **Q1 viability gate resolved → Option A.** The plugin now self-registers
> a host-contract adapter. One cross-repo server wiring task remains (see
> "Outstanding server-side work" below) for the movie/series scan path.

## How the host invokes this plugin (Option A)

The host plugin pipeline never calls `lookup()` — there is no code path that does.
Consumption happens through the host metadata registry instead:

1. **Enable.** `Phlix\Plugins\PluginLoader::enable()`
   (`phlix-server/src/Plugins/PluginLoader.php:215`) `require_once`'s the plugin's
   own `vendor/autoload.php` (`:223–225`), resolves the entry class
   `Phlix\Anidb\AnidbMetadataProvider` from the host PSR-11 container (`:238`),
   asserts it `instanceof Phlix\Shared\Plugin\LifecycleInterface` (`:248`), then
   calls `->onEnable($container)` (`:258`).

2. **Self-registration.** `AnidbMetadataProvider::onEnable()`
   (`src/AnidbMetadataProvider.php`) resolves the host
   `Phlix\Media\Metadata\MetadataManager` from the container and calls
   `registerProvider('anidb', new AnidbMetadataProviderAdapter($this), ['anime','series'])`.
   This mirrors the built-in `Phlix\Plugins\Oidc\Plugin::onEnable()`
   (`phlix-server/src/Plugins/Oidc/Plugin.php:53–71`) and
   `Phlix\Plugins\Ldap\Plugin::onEnable()`
   (`phlix-server/src/Plugins/Ldap/Plugin.php:44–71`), which resolve
   `AuthProviderRegistry` from the container and `registerProvider(...)` against it.

3. **Consumption.** `MetadataManager::registerProvider()`
   (`phlix-server/src/Media/Metadata/MetadataManager.php:76`) stores the provider in
   `providersByType` / `providers`. `MetadataManager::refreshItemMetadata()`
   (`:144`) → `getProvidersForType()` (`:123`) → `tryProvider()` (`:215`) then calls
   `$provider->search()` (`:236`), `$provider->getDetails()` (`:250`) and
   `$provider->getImages()` (`:260`) on each registered provider in priority order.
   The adapter implements exactly those methods.

### Why `MetadataProviderInterface` is reachable from the plugin

`Phlix\Media\Metadata\MetadataProviderInterface`
(`phlix-server/src/Media/Metadata/MetadataProviderInterface.php:7`) lives in the
**server** repo (PSR-4 `Phlix\` => `src/`, `phlix-server/composer.json`), not in
`phlix-shared` and not in this plugin's `vendor/`. That is fine at runtime: Phlix is
a resident-memory Workerman process, so the server's Composer autoloader is already
registered when the loader `require_once`'s the plugin autoloader. Requiring the
plugin autoloader ADDS the `Phlix\Anidb\` prefix; it never unregisters the server's
`Phlix\` => `src/` mapping. So the interface resolves in production, and
`$container->get(MetadataManager::class)` resolves because the manager is bound in
`phlix-server/src/Common/Container/Providers/MediaServicesProvider.php:181`
(`MetadataManager::class => autowire()`).

For unit tests (no server checkout) the test bootstrap defines a byte-for-byte
stub of the interface at the same FQCN — see `tests/Stub/MetadataProviderInterface.php`
and the `interface_exists()` guard in `tests/bootstrap.php`.

## Outstanding server-side work (cross-repo — schedule separately)

Registering the adapter makes the plugin **registrable and consumable by anything
that drives the container `MetadataManager`** (today: the music path —
`phlix-server/src/Media/Library/MusicLibraryManager.php:284` calls
`$this->metadata->refreshItemMetadata(...)`, and that manager is the container
instance per `WebPortalServicesProvider.php:167`).

However, the **movie/series scan/match path does not currently drive the container
`MetadataManager`**, so anime/series items will not yet be enriched by registered
providers until the server is wired up. Two independent gaps in `phlix-server`:

1. **No production caller registers movie/series providers.** A repo-wide search
   shows `MetadataManager::registerProvider()` is called only from tests
   (`tests/Unit/Media/Metadata/MetadataManagerTest.php`), never from `src/`. The
   built-in Tmdb/Tvdb/Fanart/LocalNfo providers are consumed directly via
   `MovieMetadataResolver` / `SeriesMetadataResolver` / `LibraryMetadataMatcher`,
   not through `MetadataManager`. So the container `MetadataManager` is an
   essentially empty registry for movie/series in production.

2. **`Application.php` builds a throwaway manager.**
   `phlix-server/src/Server/Core/Application.php:2601` does
   `new \Phlix\Media\Metadata\MetadataManager($db, $itemRepo)` — a fresh instance
   disconnected from the container instance the plugin registers against. Anything
   matching through that local instance will never see plugin providers.

### Concrete server follow-up to schedule

Pick ONE of:

- **(Preferred) Route the movie/series match path through the container
  `MetadataManager`.** Have `LibraryMetadataMatcher` (or the scan worker) consult
  `MetadataManager::getProvidersForType('series'|'anime')` so registered plugin
  providers participate, and stop `new`-ing a standalone `MetadataManager` in
  `Application.php:2601` (resolve it from the container instead). Add `'anime'` to
  `MetadataManager::$providerPriority` (currently has movie/series/episode/
  artist/album/track only — `MetadataManager.php:40–47`) if anime is to be a
  first-class type.

- **(Alternative) Teach `PluginLoader` to honor `type: "metadata-provider"`
  manifests** by registering the entry/adapter into the container `MetadataManager`
  itself, instead of relying on each plugin's `onEnable()` to self-register. This
  centralizes the wiring but is a larger loader change; the self-registration in
  this plugin remains valid either way.

Neither task changes this plugin; both are `phlix-server` changes. This plugin is
correct and forward-compatible as written: the moment the server drives the
container `MetadataManager` for series/anime, AniDB enrichment lights up with no
further plugin change.
