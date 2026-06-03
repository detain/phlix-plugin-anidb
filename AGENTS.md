# phlix-plugin-anidb — Agent Guide

AniDB metadata-provider plugin for [Phlix](https://github.com/detain/phlix). Resolves anime metadata via the AniDB UDP API (`api.anidb.net:9000`) and the daily title dump (`anime-titles.dat.gz`). PHP `>=8.3`, PSR-4 namespace `Phlix\Anidb\`.

## Commands

```bash
composer install               # install deps (phpunit ^10, detain/phlix-shared ^0.6)
vendor/bin/phpunit             # run the Unit suite (config in phpunit.xml)
vendor/bin/phpunit --testdox   # verbose output
```
CI mirrors this via `.github/workflows/test.yml` (PHP 8.3+).

## Architecture

- **Entry**: `src/AnidbMetadataProvider.php` — FQCN `Phlix\Anidb\AnidbMetadataProvider`, set as `entry` in `plugin.json`.
- **Contract**: implements `LifecycleInterface` (`detain/phlix-shared`); `subscribedEvents()` → `[]`, plus `lookup(string $filePath): array`.
- **Flow**: `lookup()` → `extractAnimeName()` → title-dump search → fallback `ANIME aname=` UDP → `parseAnimeResponse()` → `mapToMetadataReturn()`/`mapAnimeStatus()`.
- **Settings** (`plugin.json`): `username`, `api_key` (secret), `use_title_dump`, `title_dump_url`.
- **Protocol/design**: `PLAN.md` (UDP flood limits, amask bits, session lifecycle, DTO).
- **Tests**: `tests/Unit/AnidbMetadataProviderTest.php`, bootstrap `tests/bootstrap.php`.

## Conventions

- `declare(strict_types=1);` in every PHP file.
- Tests namespace `Phlix\Anidb\Tests\`; reach private methods with `ReflectionClass` + `setAccessible(true)`; edge cases via `static` data providers.
- `lookup()` returns a fixed key set (`title`, `original_name`, `overview`, `year`, `genres`, `rating`, `poster_url`, `episodes`, `type`, `anidb_id`, `titles`, `status`) or `[]`.
- AniDB UDP: ≥4s between packets, reuse one local port, PING ~30 min. No PDO/raw mysqli.
- One PR per `PLAN.md` phase; don't move hardcoded CI creds in `.github/workflows/` to `secrets.*` unprompted.

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
  2. Run: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, read `.agents/skills/setup-caliber/SKILL.md` and follow its instructions to install Caliber.
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->
