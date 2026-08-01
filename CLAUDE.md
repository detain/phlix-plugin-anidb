# phlix-plugin-anidb

AniDB metadata-provider plugin for [Phlix](https://github.com/detain/phlix). Resolves anime metadata via the AniDB UDP API (`api.anidb.net:9000`) and the daily title dump. PHP 8.3+, PSR-4 namespace `Phlix\Anidb\`.

## Commands

```bash
composer install               # install deps (incl. phpunit ^10, phpstan ^2.2)
vendor/bin/phpunit             # run the Unit suite (phpunit.xml)
vendor/bin/phpunit --testdox   # verbose, human-readable output
vendor/bin/phpstan analyse --no-progress   # static analysis, level 9 (phpstan.neon)
```
CI runs both on push via `.github/workflows/test.yml` (`phpunit` job on PHP 8.3 + 8.4, plus a `phpstan` job).

```bash
vendor/bin/phpunit tests/Unit/AnidbMetadataProviderTest.php          # run a single test file
vendor/bin/phpunit --filter test_parses_anime_response_correctly     # run one test method
```

```bash
composer dump-autoload   # regenerate the PSR-4 autoloader after adding a class
composer validate        # verify composer.json is well-formed
php scripts/add-copyright-headers.php src   # idempotently insert the @copyright docblock
```

## Architecture

- **Entry**: `src/AnidbMetadataProvider.php` — FQCN `Phlix\Anidb\AnidbMetadataProvider`, declared as `entry` in `plugin.json`.
- **Contract**: implements `LifecycleInterface` + `MetadataSourceInterface` from `detain/phlix-shared` ^0.21; `subscribedEvents()` returns `[]`, plus `lookup(string $filePath): array`.
- **Host adapter**: `src/AnidbMetadataProviderAdapter.php` implements the server-side `Phlix\Media\Metadata\MetadataProviderInterface` (`search()`/`getDetails()`/`getImages()`) and is registered with the host MetadataManager from `onEnable()`.
- **Seams** (constructor-injected, all default-constructed): `src/Udp/UdpClient.php` (AUTH, session key, flood protection, `506` retry) over `src/Udp/UdpClientInterface.php` (`src/Udp/SocketUdpClient.php`), `src/Udp/WaiterInterface.php` (`src/Udp/ProductionWaiter.php`), `src/AnimeResponseParser.php`, `src/Parser/FilenameTitleExtractor.php`, `src/Parser/EpisodeExtractor.php`, `src/TitleDump/TitleDumpManager.php`, DTO `src/Dto/AnimeDto.php`.
- **Lookup flow** (`lookup()`): `FilenameTitleExtractor::extract()` → `ensureConnected()` (lazy, never at boot) → `EpisodeExtractor::extract()` → `TitleDumpManager::search()` → fallback `ANIME aname=` UDP call → `AnimeResponseParser::parseAnimeResponse()` → `mapToMetadataReturn()` / `mapAnimeStatus()`.
- **Title dump**: `src/TitleDump/TitleDumpIndexer.php` downloads and indexes `anime-titles.dat.gz` off the event loop; `src/TitleDump/TitleDumpUrlMigration.php` rewrites a stored `http://` URL to https.
- **Settings**: `username`, `api_key` (secret), `use_title_dump`, `title_dump_url` — defined in `plugin.json` `settings`.
- **Design reference**: `PLAN.md` documents the UDP protocol, flood limits, amask bits, session lifecycle, and DTO shape; `docs/HOST_INTEGRATION.md` documents host wiring.
- **Tests**: `tests/Unit/` and `tests/Unit/TitleDump/`; bootstrap `tests/bootstrap.php` requires `vendor/autoload.php` and loads `tests/Stub/MetadataProviderInterface.php` when the host interface is absent.

## Conventions

- `declare(strict_types=1);` and the `@copyright`/`@license` docblock at the top of every PHP file (see `src/` and `tests/`).
- Test namespace `Phlix\Anidb\Tests\` (autoload-dev in `composer.json`); test private methods via `ReflectionClass` + `setAccessible(true)`, cover edge cases with `static` data providers, and inject the seams instead of hitting the network.
- `lookup()` return shape is fixed — keys `title`, `original_name`, `overview`, `year`, `genres`, `rating`, `vote_count`, `poster_url`, `fanart_url`, `episodes`, `type`, `anidb_id`, `titles`, `status`, `runtime_ticks`, `studio`, `studios`, `source`, `is_movie`, `synonyms`, `episode_number` (full list in `README.md`).
- AniDB UDP rules: ≥4s between packets, reuse one local port, PING ~30 min to keep the session. Never exceed flood limits.
- `onEnable()` does wiring only — no network/disk/socket I/O (it runs in every resident worker at boot); downloads and index loads are deferred to `ensureConnected()`.
- Title-dump cache dir must live outside the plugin tree: explicit ctor arg → `$settings['cache_dir']` → env `PHLIX_ANIDB_CACHE_DIR` → `sys_get_temp_dir()`.
- No PDO/raw mysqli; this plugin makes no direct DB calls.

## Workflow

- One PR per logical phase (see `PLAN.md`): commit with a detailed message → push → open + merge PR → continue.
- Do NOT refactor hardcoded CI credentials in `.github/workflows/` to `secrets.*` without asking first.

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:model-config -->
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

<!-- /caliber:managed:model-config -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->
