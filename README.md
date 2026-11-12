# phlix-plugin-anidb

[![tests](https://github.com/detain/phlix-plugin-anidb/actions/workflows/test.yml/badge.svg)](https://github.com/detain/phlix-plugin-anidb/actions/workflows/test.yml)

> AniDB metadata provider plugin for [Phlix](https://github.com/detain/phlix-server)
> — anime titles, descriptions, episodes, ratings via UDP API and daily title dump.

## Overview

This plugin fetches structured anime metadata from [AniDB](https://anidb.net/) using:

1. **UDP API** (`api.anidb.net:9000`) — fetch anime details, descriptions, episodes
2. **Daily title dump** (`anime-titles.dat.gz`) — fast offline title→AID lookups without hitting the API

## Features

- **Title dump search** — offline exact/prefix/contains match search across all AniDB anime titles (exact hits resolve in O(1) from a lowercase-title index built once per load; near-misses are ranked by length similarity — not fuzzy matching)
- **Full metadata** — romaji/english/kanji titles, synonyms, year, type, rating (the `genres` key is always empty — AniDB category tags are not in the fetched response, see Protocol Notes)
- **Description** — fetch long descriptions via separate ANIMEDESC command
- **Flood protection** — 4-second rate limiting between API calls (per AniDB rules)
- **Session management** — keep-alive pings every 30 min, auto-reconnect on 506 SESSION EXPIRED
- **Episode info** — episode number parsed from the filename by `src/Parser/EpisodeExtractor.php` and returned as `episode_number`

## Install

The plugin is unsigned by design. Install via the Phlix admin UI:

1. Log in to your Phlix server as an admin user (`users.is_admin = 1`).
2. Browse to `/admin/plugins`.
3. Paste this URL into the **Install from URL** form:

   ```
   https://raw.githubusercontent.com/detain/phlix-plugin-anidb/master/plugin.json
   ```

4. The server downloads and validates the manifest, runs `composer install --no-dev`, and stores a row in the `plugins` table.
5. Configure your AniDB credentials in the plugin settings form:
   - **AniDB Username**: your AniDB username
   - **AniDB UDP API Key**: the API password you set in your AniDB profile
     (Settings → Account → API), NOT your login password

   If either is missing or empty, AUTH is refused before any datagram is sent
   with `AniDB: the "AniDB Username" and "AniDB UDP API Key" settings are
   required (missing or empty: …)`, naming each unusable field by its form
   label. A server-side `500 LOGIN FAILED` surfaces as `AniDB AUTH failed:
   invalid "AniDB Username" or "AniDB UDP API Key" …`.
6. Enable the plugin.

## Requirements

- **PHP** >= 8.3 with `ext-sockets` (AniDB UDP transport), `ext-zlib`
  (title-dump gzip handling) and `ext-mbstring` (title normalization) — all
  three are declared in `composer.json`, so the host's `composer install`
  refuses to install onto a runtime that lacks them.
- **Workerman** is provided by the Phlix server runtime and is deliberately
  *not* a composer dependency of this plugin. The deferred title-dump
  download uses `Workerman\Http\Client` / `Workerman\Timer` when present
  (`class_exists`-guarded); outside a Workerman runtime (plain CLI, unit
  tests) the download is skipped rather than blocking, and the UDP path
  serves. See "AniDB Protocol Notes".

## Configuration

Configure these in the Phlix admin **Plugins → Configure** dialog.

| Setting | Type | Required | Default | Description |
|---------|------|----------|---------|-------------|
| `username` | string | **Yes** | — | Your AniDB account username. [Register free](https://anidb.net/). |
| `api_key` | string (secret) | **Yes** | — | The UDP **API Key** (API password) set in your AniDB profile under Settings → Account → API — separate from your login password. See the [AniDB API docs](https://anidb.net/software/api). |
| `use_title_dump` | boolean | No | `true` | Download the daily title dump for fast, offline search (reduces rate-limited API calls). |
| `title_dump_url` | string | No | `https://anidb.net/api/anime-titles.dat.gz` | URL to `anime-titles.dat.gz`; change only for a mirror. Must be **HTTPS** — AniDB's Cloudflare front answers plain HTTP with 403, and any other plain-HTTP URL is refused (no download, a `REFUSING` log line) unless the server environment sets `PHLIX_ANIDB_ALLOW_HTTP_DUMP_MIRROR=1` — see "Protocol & credential posture". A stored `http://…anidb.net/…` value from before 0.4.1 is migrated to https automatically. |
| `cache_dir` | string | No | — | Absolute path of a writable directory for the title-dump index. It **must** live outside the read-only plugin tree (systemd sandbox) and **must not** be world-writable (the index is served from it). A relative path, a non-string value, or an existing world-writable directory is refused at construction with a loud error. Unset falls back to the `PHLIX_ANIDB_CACHE_DIR` environment variable (same rule), then the system temp dir. |

The title-dump index is written to a writable cache directory outside the plugin tree, resolved in this order: constructor argument → `cache_dir` setting → the `PHLIX_ANIDB_CACHE_DIR` environment variable → `sys_get_temp_dir()/phlix-plugin-anidb`.

> The AniDB UDP API authenticates with your **username** + a separate **API Key** you set in your
> profile (not your website login password). Set the API Key under
> [your AniDB profile](https://anidb.net/) → Settings → Account → API.

## How It Works

The plugin registers an `AnidbMetadataProviderAdapter` with the host MetadataManager.
When the server needs anime metadata it calls the adapter's `search()` or
`getDetails()` methods (the adapter's surface is `search`/`getDetails`/`getImages`/
`getProviders`/`getSourceName`; the file-path-based `lookup()` lives on the wrapped
provider in `src/AnidbMetadataProvider.php`, not on the adapter):

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
- **Session**: valid 35 minutes; keep-alive with PING every ~30 minutes
- **Flood ban**: reusing the same local UDP port is critical to avoid IP-level bans
- **Field coverage**: the ANIME amask in use (`00f0f0f0000000`) returns titles, episode/date and rating fields only — AniDB's category/genre tags are not part of that response, so `genres` is structurally always empty (deriving real genres would additionally require vote/weight filtering over the tag cloud)

### Protocol & credential posture

The AniDB UDP API has no TLS/encrypted transport and no TCP alternative. The
client supports the md5 challenge form of AUTH (`auth=md5(challenge + key)`,
protover 3), so the API Key need not travel in the clear **when the server issues
a challenge**; the initial AUTH datagram still carries `pass=` in a **cleartext
UDP datagram** until the challenge wire form is confirmed by a live capture
(owner-gated). Cleartext is the optional fallback, not an inherent property of
the client. Until then, any party on the network path between the Phlix server and `api.anidb.net:9000`
could read those bytes. Mitigations, in order of importance:

- Use a **dedicated AniDB account** for Phlix, never your personal one.
- Set an **API password** that is unique to AniDB and not reused anywhere
  (it is already separate from the site login password — see Settings above).
- Run Phlix on trusted network infrastructure; if in doubt, confirm with your
  host that the egress path to `api.anidb.net` is not shared with untrusted
  tenants.

**Reply-origin check is an address allowlist, not authentication.** A UDP reply
is accepted only if it comes from port 9000 on one of the resolved IPs of
`api.anidb.net` (DNS is resolved again every hour). That drops stray and
misrouted datagrams, but UDP does not authenticate source addresses, and AniDB
does not sign its replies. Anyone on the network path, or anyone who can spoof
AniDB's source IP and port and get the timing right, can still get a forged
reply accepted. That reply could be fake metadata or a fake AUTH/session
answer. The protocol cannot close this gap. The mitigation is the same trusted
network path described above.

**`title_dump_url` must be HTTPS (default-deny cleartext).** The title dump
feeds every worker's offline title→AID index, and a plain-HTTP download has no
certificate to verify, so anyone on the network path could serve a forged dump.
A non-HTTPS `title_dump_url` is therefore **refused**: each download attempt
logs `TitleDumpIndexer: REFUSING download — title_dump_url uses cleartext HTTP
(http://<host>)` and contacts nothing, and the previously cached index (if any)
keeps serving. If your mirror genuinely has no HTTPS, set the environment
variable `PHLIX_ANIDB_ALLOW_HTTP_DUMP_MIRROR=1` (exactly `1`) for the Phlix
server process; the fetch then proceeds and logs a cleartext warning on
every attempt. Redirects are never followed, so an https URL cannot be walked
down to http by a 301 either way.

**`title_dump_url` fetch reachability (deferred guard).** The server fetches
whatever URL the `title_dump_url` setting names. The setting lives in the
plugin settings form (advanced tier), so only an administrator can change it.
Members cannot reach it. The fetched body is never shown to anyone. It is
size-capped and thrown away unless it decompresses and parses as a valid title
dump. Logs carry only refusal reasons, byte counts, the scrubbed URL and the
transport error class/message — never body content. There is **no host
allowlist and no private/loopback-range deny** on this URL today, so an
administrator can point the fetch at an internal address and the server will
send that request; only the transport outcome is logged. This is accepted
because only administrators can set the URL.
**If `title_dump_url` ever becomes settable by members**, a host
allowlist or a private-range deny must ship in the same change.

See the [AniDB UDP API docs](https://wiki.anidb.net/UDP_API_Definition) for full details.

## Data Returned

```php
[
    'title'         => 'Seikai no Monshou',      // Primary romanized title
    'original_name' => 'Crest of the Stars',     // English official title (or kanji)
    'overview'      => 'A space opera...',       // Description (fetched separately)
    'year'          => 1999,                     // First release year
    'genres'        => [],                        // Always empty — categories not in the fetched amask
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
    'studio'        => null,                     // No studio field; AniDB's studio data lives in categories (not fetched)
    'studios'       => [],                       // Same, as a list
    'source'        => null,                     // Source material, when known
    'is_movie'      => false,                    // Derived from type / filename pattern
    'synonyms'      => ['Crest of the Stars'],   // True synonyms from the ANIME response (field 11); short_names (field 10) also feed 'titles'
    'description_fetch_failed' => false,         // true when the synopsis (ANIMEDESC) fetch failed — distinguishes "no synopsis exists" from "synopsis fetch failed"
    'episode_number' => 1,                       // Parsed from the filename; key is ABSENT (not null) when the filename yields none
]
```

## Fork as a Starter

This plugin is based on [`phlix-plugin-example`](https://github.com/detain/phlix-plugin-example). To create your own metadata provider:

1. Fork or copy this repository.
2. Edit `plugin.json` — pick a new `name` (must start with `phlix-plugin-`), set `version` to `0.1.0` for your fork, change `entry` to your FQCN.
3. Edit `composer.json` — rename the package, update PSR-4 autoload prefix.
4. Replace `src/AnidbMetadataProvider.php` with your own implementation.
5. Run tests: `composer install && vendor/bin/phpunit`.

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpunit --testdox              # verbose output
vendor/bin/phpstan analyse --no-progress  # static analysis, level 9 over ALL of src/ — zero ignoreErrors, zero excludes (phpstan.neon)
php scripts/security-audit-check.php      # security audit over the whole lock (require + require-dev)
```

## License

MIT — see [`LICENSE`](LICENSE).
