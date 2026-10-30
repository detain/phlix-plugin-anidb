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
