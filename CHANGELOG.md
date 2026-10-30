# Changelog

All notable changes to `phlix-plugin-anidb` are documented here. Dates are
commit dates; SHAs point at the introducing commits on `master`.

## Semver-era disclosure (0.4.x)

`plugin.json` declares version **0.4.1**, but **no 0.4.x release has ever been
tagged**: the published git tags stop at `v0.3.0` (2026-07-10, `3bf51b6`;
before that `v0.2.0`, 2026-07-09, `b3eaf97`). The phlix-plugins catalog pins
release entries by tag with the version read from `plugin.json` at that tag,
so 0.4.x is only ever resolvable through the dev channel (default-branch HEAD
pin, version `"dev"`, verified:false) until the owner cuts a `v0.4.x` tag.
README prose referring to "before 0.4.1" (the URL-migration story) therefore
describes dev-channel builds prior to `46cbb1d`, not an installed release.

## [Unreleased] — 0.4.x development line

### Fixed
- `genres` documentation now matches parser reality (categories are not
  fetched; the key is always empty) — `45955a4` (2026-10-29).
- `cache_dir` is declared in `plugin.json` so the documented cache knob is
  actually settable (it was a ghost key the server would reject on save),
  with settings-parity guard tests — `02bf834` (2026-10-29).
- `cache_dir` / `PHLIX_ANIDB_CACHE_DIR` values are validated: must be strings
  naming absolute paths (relative values used to resolve against the worker
  CWD silently); existing directories are canonicalized via `realpath()` —
  W-min1 minors cluster (this release line).
- Review-campaign doc-truth cluster (W-min1): README `lookup()` attribution,
  synonyms source label, 506 response-term drift ("SESSION EXPIRED"), orphan
  refactor docblocks and a duplicated section header removed in
  `src/AnidbMetadataProvider.php`, `declare(strict_types=1)` added to
  `scripts/add-copyright-headers.php`, and this CHANGELOG introduced.
- Review-campaign minors cluster (W-min2, eight items across docmaint/srphp/
  secaudit lenses): `mapAnimeStatus` docblock no longer claims dateflags
  (never parsed; dates alone decide); the stale "Wave 2 must inject" TODO in
  the `cacheDir` constructor docblock replaced by the shipped validation
  story; `TitleDumpManager::search` ranking prose corrected to length-
  proximity scoring and README's "fuzzy-match" claim made honest; exact
  title lookups now O(1) from a lowercase-title map built once per loaded
  index (first entry wins, `injectIndex()` resets the memo; behavior-
  equivalent by construction — the pre-map scan always resolved the same
  AID); the `validateSchema` whole-entry drop policy is now documented,
  commented at both `continue 2` arms, and fully pinned (new non-array-
  title arm test); `AnimeDto` class docblock, the dead import in
  `AnidbMetadataProvider`, and the AGENTS.md seam list now state the
  unwired as-shipped truth (disposition stays in W-min5); the wildcard UDP
  `0.0.0.0` bind site documents the verified reply-origin compensation
  (review anidb__secaudit m4 accepted-cure alternative); dead
  `httpClient === null` branch removed from `fetch()` (the constructor
  coalesces to the default transport unconditionally) and `writeIndex`
  mkdir made race-safe `@mkdir` + re-check, failing loud-early with no
  doomed `file_put_contents` attempt when the directory cannot exist.
  Suite 366→371 tests, 1098→1110 assertions (exactly five new pins).
- Review-campaign minors cluster (W-min3, five items, secaudit/qa lenses):
  the `UdpClientInterface` / `SocketUdpClient` docblocks now state the shipped
  truth (async waiter, 506 re-auth and origin validation all landed; transports
  capture reply origins, `UdpClient` validates them) instead of the pre-S1b
  "later steps" roadmap and the dangling `openSocket()` `@see`;
  `UdpClient::authenticate()` gained a fail-fast `requireCredentials()` guard
  (missing/non-string/empty `username`/`api_key` throw a descriptive
  `RuntimeException` before a malformed AUTH datagram can leave the socket —
  15 new pins); the README documents the protocol-inherent cleartext-UDP
  credential posture with the dedicated-account mitigation (M-ANIDB-15);
  `TitleDumpIndexer` no longer swallows the upstream fetch cause (the error
  callback logs class + message; null-body, invalid-gzip and both size-cap
  refusals log their reason) and the two operator notices redact absolute
  paths to basenames, restored in full by `PHLIX_ANIDB_DEBUG=1` (M-ANIDB-19;
  flipped notice pins + one new debug-mode pin); downloads are bounded by
  `MAX_DOWNLOAD_BYTES`/`MAX_DECOMPRESSED_BYTES` and the constructor refuses
  any non-http(s) `title_dump_url` scheme at the boundary without echoing the
  URL (M-ANIDB-28).
  Suite 371→395 tests, 1110→1200 assertions.
- Review-campaign minors cluster (W-min4, four items, srphp/devops lenses):
  `composer.json` now declares the extensions the code actually uses
  (`ext-sockets`, `ext-zlib`, `ext-mbstring`), drops the unused direct
  `psr/log` (it remains transitively via `detain/phlix-shared`), and raises
  `detain/phlix-shared` to `^0.52.0` — the `MetadataSourceInterface` /
  `LifecycleInterface` method sets are identical between 0.21 and 0.52, so
  the plugin source is untouched by the bump. The README gained a
  Requirements section stating the Workerman soft-dependency law (host-
  provided, `class_exists`-guarded, deliberately never a composer require)
  (M-ANIDB-11, M-ANIDB-32). `phpstan.neon` is now zero-suppression: the 13
  `ignoreErrors` blocks (21 instances) and the
  `AnidbMetadataProviderAdapter` exclusion are all replaced by real fixes —
  `mapAnimeStatus` returns `string` (no null arm ever existed),
  `mapToMetadataReturn` parses `romaji`/`english`/`kanji`/`synonyms` at the
  boundary instead of blind mixed derefs, the write-only `$container`
  property was deleted, `FilenameTitleExtractor` chains `?? $clean` on every
  preg step and dropped its dead final ternary, `SocketUdpClient` types the
  socket as PHP 8's `Socket` object (the old `resource` annotations
  described a type that has not existed since PHP 8.0), and the adapter is
  analyzed against `tests/Stub/MetadataProviderInterface.php` (M-ANIDB-20;
  M-ANIDB-12 cured-by-truth — the README/AGENTS "level 9" claim now covers
  all of `src/` with nothing hidden). Suite 395 tests held.
- Review-campaign minors cluster (W-min5, three items, srphp lens):
  `TitleDumpManager` now owns a single canonical in-memory title shape
  `array{title, type, lang, lower, len}` minted once per load by
  `canonicalTitleEntry()` from both entry points (`validateSchema()` on the
  cache file and the `injectIndex()` test seam, which still accepts minimal
  `{title,type,lang}` fixtures); the per-query scan and the exact-match map
  read the precomputed `lower`/`len` instead of re-running
  `mb_strtolower()`/`mb_strlen()` over every title of every query
  (M-ANIDB-23). `TitleDumpIndexer::parse()` correspondingly stopped
  persisting the `lower_title` field the schema validator stripped anyway —
  `title_index.json` now carries raw fields only (pinned). The misplaced
  `tests/Unit/TitleDump/TitleDumpManagerSearchTest.php` namespace matches
  its directory (composer's PSR-4 warning cleared; M-ANIDB-30). The dead
  `AnimeDto` import (M-ANIDB-22) was already cured by the W-min2 cluster at
  `70f3874` — recorded, not re-edited. Suite 395→397 tests,
  1200→1214 assertions (one builder pin re-cut to the new law, two new
  pins).

### Maintenance eras (post-0.4.1, moving main)
- S246: whole-lock security audit gate (require + require-dev) — `0860a64`
  (2026-08-07).
- S440: audit-gate guard test port (sample-theme parity) — `239e8f7`
  (2026-10-07).
- CI: ubuntu-26.04 deliberate pin — `47b38b7` (2026-10-08); Codacy coverage
  reporter re-pinned to the v1.3.0 tag commit — `5e4cc80` (2026-10-08).
- Dependency security updates — `8757759` (2026-10-20).

## 0.4.1 (era bump `46cbb1d`, 2026-07-28 — never tagged)

- `title_dump_url` migration: a stored plain-http AniDB URL is rewritten to
  https at construction (`src/TitleDump/TitleDumpUrlMigration.php`,
  `@since 0.4.1`) — AniDB's Cloudflare front answers http with 403, which
  silently left the offline index empty.
- Title-dump blocked-marker naming + symlink comment correction — `4b80320`
  (2026-07-28).
- Test-coverage push and CI-SHA-pinning era (`63712cb`, `cff5ecc`,
  `6dbcb46`, `06cc50c`, 2026-07-31/08-01), including the cross-repo
  catalog-checksum dispatch workflow (`8eb6efb` era).

## 0.4.0 (era bump `215717b`, 2026-07-22 — never tagged)

- Boot I/O correctness: everything deferred to first lookup (`onEnable()` is
  wiring-only), reply-origin validation, 506 re-auth retry, poster CDN,
  cache-directory sandbox — `2d1be92` (2026-07-21).
- Async waiter seam (`src/Udp/WaiterInterface.php`, `ProductionWaiter.php`,
  `@since 0.4.0`).
- Per-field settings tier classifications in the manifest — `215717b`.
- `detain/phlix-shared` constraint raised to `^0.21.0` — `db33366` (2026-07-20).
- MIT copyright headers across all PHP files — `21453cb` (2026-07-27).

## [0.3.0] - 2026-07-10 (`3bf51b6`)

- Manifest settings help enrichment; require server >= 1.2.0 — `3bf51b6`.
- `detain/phlix-shared` constraint raised to `^0.17.0` — `bd8bc79` (2026-07-10).

## [0.2.0] - 2026-07-09 (`b3eaf97`)

- P9-S5: improved episode mapping and anime-specific fields — `8bfe1f0`.
- Shared `MetadataSourceInterface` implemented on the entry class — `49de55a`;
  `detain/phlix-shared` resolved from Packagist — `8ea6410` (2026-07-01).
