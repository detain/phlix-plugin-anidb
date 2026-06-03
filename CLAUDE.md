# phlix-plugin-anidb

AniDB metadata-provider plugin for [Phlix](https://github.com/detain/phlix). Resolves anime metadata via the AniDB UDP API (`api.anidb.net:9000`) and the daily title dump. PHP 8.3+, PSR-4 namespace `Phlix\Anidb\`.

## Commands

```bash
composer install               # install deps (incl. phpunit ^10)
vendor/bin/phpunit             # run the Unit suite (phpunit.xml)
vendor/bin/phpunit --testdox   # verbose, human-readable output
```
CI runs the same on push via `.github/workflows/test.yml` (PHP 8.3+).

```bash
vendor/bin/phpunit tests/Unit/AnidbMetadataProviderTest.php          # run a single test file
vendor/bin/phpunit --filter test_parses_anime_response_correctly     # run one test method
```

```bash
composer dump-autoload   # regenerate the PSR-4 autoloader after adding a class
composer validate        # verify composer.json is well-formed
```

## Architecture

- **Entry**: `src/AnidbMetadataProvider.php` — FQCN `Phlix\Anidb\AnidbMetadataProvider`, declared as `entry` in `plugin.json`.
- **Contract**: implements `LifecycleInterface` from `detain/phlix-shared` ^0.6; `subscribedEvents()` returns `[]`, plus `lookup(string $filePath): array`.
- **Lookup flow** (`lookup()`): `extractAnimeName()` parses filename → title-dump search → fallback `ANIME aname=` UDP call → `parseAnimeResponse()` → `mapToMetadataReturn()` / `mapAnimeStatus()`.
- **Settings**: `username`, `api_key` (secret), `use_title_dump`, `title_dump_url` — defined in `plugin.json` `settings`.
- **Design reference**: `PLAN.md` documents the UDP protocol, flood limits, amask bits, session lifecycle, and DTO shape.
- **Tests**: `tests/Unit/AnidbMetadataProviderTest.php`; bootstrap `tests/bootstrap.php` only requires `vendor/autoload.php`.

## Conventions

- `declare(strict_types=1);` at the top of every PHP file (see `src/` and `tests/`).
- Test namespace `Phlix\Anidb\Tests\` (autoload-dev in `composer.json`); test private methods via `ReflectionClass` + `setAccessible(true)`, cover edge cases with `static` data providers.
- `lookup()` return shape is fixed — keys `title`, `original_name`, `overview`, `year`, `genres`, `rating`, `poster_url`, `episodes`, `type`, `anidb_id`, `titles`, `status` (full list in `README.md`).
- AniDB UDP rules: ≥4s between packets, reuse one local port, PING ~30 min to keep the session. Never exceed flood limits.
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
