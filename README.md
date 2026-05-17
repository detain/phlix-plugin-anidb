# phlex-plugin-example

[![tests](https://github.com/detain/phlex-plugin-example/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlex-plugin-example/actions/workflows/test.yml)

> Reference **metadata-provider** plugin for [Phlex](https://github.com/detain/phlex)
> — the smallest plugin that exercises the full Phase A loader lifecycle.

This repo is the canonical hello-world template for Phlex plugin
authors. It does almost nothing on purpose: it implements the
`Phlex\Plugins\Contract\LifecycleInterface` contract introduced in
Phlex Step A.4, returns a fixed greeting when asked about one
well-known fixture path, and ships with a CI workflow plus PHPUnit
tests so you can fork it as a starter and replace the lookup logic
with the real one.

## What it does

When the host calls `HelloMetadataProvider::lookup('/test/hello.mkv')`
the plugin returns:

```php
['title' => 'Hello, World']
```

Any other path returns an empty array. The greeting is read from the
`greeting` setting in `plugin.json` (default `"Hello, World"`) so
operators can flip it without forking the code.

## Install

The plugin is unsigned by design — it's a reference implementation,
not something the trusted-key allowlist should pin. Install via the
Phlex admin UI:

1. Log in to your Phlex server as an admin user
   (`users.is_admin = 1`).
2. Browse to `/admin/plugins`.
3. Paste this URL into the **Install from URL** form and submit:

   ```
   https://raw.githubusercontent.com/detain/phlex-plugin-example/main/plugin.json
   ```

4. The server downloads the manifest, validates it against
   `docs/plugins/manifest.schema.json`, runs
   `composer install --no-dev`, and stores a row in the `plugins`
   table. The plugin lands **disabled** by default.
5. Flip the toggle in the table to enable it.

The same operations are reachable via the JSON API; see
[`docs/plugins/install-from-url.md`](https://github.com/detain/phlex/blob/master/docs/plugins/install-from-url.md)
in the main Phlex repo for the `curl` recipes.

## Use

Once enabled, ask the metadata layer to look up the fixture path. The
exact API path depends on which Phlex version you're on, but the
plugin's behaviour is fixed:

```php
$provider = $container->get(\Phlex\PluginExample\HelloMetadataProvider::class);
$provider->lookup('/test/hello.mkv'); // ['title' => 'Hello, World']
$provider->lookup('/anything/else');  // []
```

The fixture path lives at
`Phlex\PluginExample\HelloMetadataProvider::FIXTURE_PATH` if you want
to reference it from tests.

## Fork as a starter

This repository is intentionally small (one PHP class, one test
file) so you can copy it as the seed for your own plugin:

1. **Fork** or `git clone` this repo, then rename the new directory.
2. Edit **`plugin.json`** — pick a new `name` (must start with
   `phlex-plugin-`), bump `version` back to `0.1.0`, change `entry`
   to your FQCN, and (optionally) declare event aliases under
   `events` if you want to subscribe to playback / library / auth
   events. See the full schema in
   [`docs/plugins/manifest.schema.json`](https://github.com/detain/phlex/blob/master/docs/plugins/manifest.schema.json)
   in the main repo.
3. Edit **`composer.json`** — rename the package, change the PSR-4
   prefix under `autoload.psr-4`.
4. Rewrite **`src/HelloMetadataProvider.php`** with your own
   implementation. Keep `onEnable()` cheap; do the heavy work in the
   listener methods declared by `subscribedEvents()`.
5. Replace the tests in **`tests/`** to match. The CI workflow in
   `.github/workflows/test.yml` runs them on every push.
6. Push to a public Git host. Tell operators to paste the raw URL of
   your `plugin.json` into `/admin/plugins`.

The plugin developer guide in the main Phlex repo has the full
walkthrough, including the lifecycle diagram and the manifest event
alias table:

- [`docs/plugins/developer-guide.md`](https://github.com/detain/phlex/blob/master/docs/plugins/developer-guide.md)
- [`docs/plugins/manifest.md`](https://github.com/detain/phlex/blob/master/docs/plugins/manifest.md)
- [`docs/plugins/install-from-url.md`](https://github.com/detain/phlex/blob/master/docs/plugins/install-from-url.md)
- [`docs/plugins/trusted-plugin-list.md`](https://github.com/detain/phlex/blob/master/docs/plugins/trusted-plugin-list.md)

## Running the tests locally

```bash
composer install
vendor/bin/phpunit
```

## License

MIT — see [`LICENSE`](LICENSE).
