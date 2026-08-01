# phlix-plugin-anidb

[![tests](https://github.com/detain/phlix-plugin-anidb/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-anidb/actions/workflows/test.yml)

> AniDB metadata provider plugin for [Phlix](https://github.com/detain/phlix)
> — anime titles, descriptions, episodes, ratings via UDP API and daily title dump.

## Overview

This plugin fetches structured anime metadata from [AniDB](https://anidb.net/) using:

1. **UDP API** (`api.anidb.net:9000`) — fetch anime details, descriptions, episodes
2. **Daily title dump** (`anime-titles.dat.gz`) — fast offline title→AID lookups without hitting the API

## Features

- **Title dump search** — fast fuzzy-match search against all AniDB anime titles
- **Full metadata** — romaji/english/kanji titles, synonyms, genres, year, type, rating
- **Description** — fetch long descriptions via separate ANIMEDESC command
- **Flood protection** — 4-second rate limiting between API calls (per AniDB rules)
- **Session management** — keepalive pings every 30 min, auto-reconnect on 506 INVALID SESSION
- **Episode info** — episode number parsed from the filename by `src/Parser/EpisodeExtractor.php` and returned as `episode_number`

## Install

The plugin is unsigned by design. Install via the Phlix admin UI:

1. Log in to your Phlix server as an admin user (`users.is_admin = 1`).
2. Browse to `/admin/plugins`.
3. Paste this URL into the **Install from URL** form:

   ```
   https://raw.githubusercontent.com/detain/phlix-plugin-anidb/main/plugin.json
   ```

4. The server downloads and validates the manifest, runs `composer install --no-dev`, and stores a row in the `plugins` table.
5. Configure your AniDB credentials in the plugin settings form:
   - **Username**: your AniDB username
   - **API Password**: your AniDB API password (from your AniDB profile, NOT your login password)
6. Enable the plugin.

## Configuration

Configure these in the Phlix admin **Plugins → Configure** dialog.

| Setting | Type | Required | Default | Description |
|---------|------|----------|---------|-------------|
| `username` | string | **Yes** | — | Your AniDB account username. [Register free](https://anidb.net/). |
| `api_key` | string (secret) | **Yes** | — | The UDP **API Key** (API password) set in your AniDB profile under Settings → Account → API — separate from your login password. See the [AniDB API docs](https://anidb.net/software/api). |
| `use_title_dump` | boolean | No | `true` | Download the daily title dump for fast, offline search (reduces rate-limited API calls). |
| `title_dump_url` | string | No | `https://anidb.net/api/anime-titles.dat.gz` | URL to `anime-titles.dat.gz`; change only for a mirror. Must be **https** — AniDB's Cloudflare front answers plain http with 403, so an http URL silently leaves the offline index empty. A stored `http://…anidb.net/…` value from before 0.4.1 is migrated to https automatically. |

The title-dump index is written to a writable cache directory outside the plugin tree, resolved in this order: constructor argument → `cache_dir` setting → the `PHLIX_ANIDB_CACHE_DIR` environment variable → `sys_get_temp_dir()`.

> The AniDB UDP API authenticates with your **username** + a separate **API Key** you set in your
> profile (not your website login password). Set the API Key under
> [your AniDB profile](https://anidb.net/) → Settings → Account → API.

## How It Works

The plugin registers an `AnidbMetadataProviderAdapter` with the host MetadataManager.
When the server needs anime metadata it calls the adapter's `search()` or
`getDetails()` methods (the adapter's `lookup()` method is also available for
file-path-based lookups):

1. **search(title)** — resolve an anime title to an AID using the title dump or API fallback
2. **getDetails(aid)** — fetch full anime metadata by AID
3. **lookup(filePath)** — extract anime name from a file path and return metadata

Internal flow (when `lookup()` or `getDetails()` is called):

1. **Fetch details** — send `ANIME aid=...` for full anime data
2. **Fetch description** — send `ANIMEDESC aid=...` for the full synopsis
3. **Map response** — translate AniDB field layout to MetadataManager's expected return shape

Registration (`onEnable()`) does wiring only — the socket, title-dump download and
index load are deferred to the first real lookup so plugin boot never blocks the
resident Workerman worker.

## AniDB Protocol Notes

- **Protocol**: UDP (not HTTP) to `api.anidb.net:9000`
- **Flood protection**: ≤ 0.5 packets/sec after first 5, minimum 4 seconds between packets
- **Session**: valid 35 minutes; keep alive with PING every ~30 minutes
- **Flood ban**: reusing the same local UDP port is critical to avoid IP-level bans

See the [AniDB UDP API docs](https://wiki.anidb.net/UDP_API_Definition) for full details.

## Data Returned

```php
[
    'title'         => 'Seikai no Monshou',      // Primary romanized title
    'original_name' => 'Crest of the Stars',     // English official title (or kanji)
    'overview'      => 'A space opera...',       // Description (fetched separately)
    'year'          => 1999,                     // First release year
    'genres'        => ['SciFi', 'Space'],       // Category tags
    'rating'        => 8.53,                     // AniDB rating (0-10)
    'vote_count'    => 3225,                     // Number of votes
    'poster_url'    => 'https://cdn-eu.anidb.net/images/main/1.jpg',  // null if no picname
    'fanart_url'    => null,                     // Not provided by AniDB
    'episodes'      => 13,                       // Episode count (null if unknown)
    'type'          => 'tv',                      // Normalized type (tv, movie, ova, etc.)
    'anidb_id'      => 1,                        // AniDB AID
    'titles'        => ['Seikai no Monshou', 'Crest of the Stars', '星界の紋章'],
    'status'        => 'Finished',               // Finished / Currently Airing / Upcoming
    'runtime_ticks'  => null,                     // Not provided by AniDB
    'studio'        => null,                     // AniDB uses categories instead
    'studios'       => [],                       // Same, as a list
    'source'        => null,                     // Source material, when known
    'is_movie'      => false,                    // Derived from type / filename pattern
    'synonyms'      => ['Crest of the Stars'],   // Short/synonym titles from the dump
    'episode_number' => 1,                       // Parsed from the filename (null if absent)
]
```

## Fork as a Starter

This plugin is based on [`phlix-plugin-example`](https://github.com/detain/phlix-plugin-example). To create your own metadata provider:

1. Fork or copy this repository.
2. Edit `plugin.json` — pick a new `name` (must start with `phlix-plugin-`), bump `version` to `0.1.0`, change `entry` to your FQCN.
3. Edit `composer.json` — rename the package, update PSR-4 autoload prefix.
4. Replace `src/AnidbMetadataProvider.php` with your own implementation.
5. Run tests: `composer install && vendor/bin/phpunit`.

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpunit --testdox              # verbose output
vendor/bin/phpstan analyse --no-progress  # static analysis, level 9 (phpstan.neon)
```

## License

MIT — see [`LICENSE`](LICENSE).
