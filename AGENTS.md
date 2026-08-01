# phlix-plugin-anidb — Agent Guide

AniDB metadata-provider plugin for [Phlix](https://github.com/detain/phlix). Resolves anime metadata via the AniDB UDP API (`api.anidb.net:9000`) and the daily title dump (`anime-titles.dat.gz`). PHP `>=8.3`, PSR-4 namespace `Phlix\Anidb\`.

## Commands

```bash
composer install               # install deps (phpunit ^10, phpstan ^2.2, detain/phlix-shared ^0.21.0)
vendor/bin/phpunit             # run the Unit suite (config in phpunit.xml)
vendor/bin/phpunit --testdox   # verbose output
vendor/bin/phpstan analyse --no-progress   # static analysis, level 9 (phpstan.neon)
```
CI mirrors this via `.github/workflows/test.yml` (`phpunit` job on PHP 8.3 + 8.4, plus a `phpstan` job).

## Architecture

- **Entry**: `src/AnidbMetadataProvider.php` — FQCN `Phlix\Anidb\AnidbMetadataProvider`, set as `entry` in `plugin.json`.
- **Contract**: implements `LifecycleInterface` + `MetadataSourceInterface` (`detain/phlix-shared` ^0.21); `subscribedEvents()` → `[]`, plus `lookup(string $filePath): array`.
- **Host adapter**: `src/AnidbMetadataProviderAdapter.php` implements the server-side `Phlix\Media\Metadata\MetadataProviderInterface` (`search()`/`getDetails()`/`getImages()`), registered with the host MetadataManager from `onEnable()`.
- **Seams** (constructor-injected, all default-constructed): `src/Udp/UdpClient.php` over `src/Udp/UdpClientInterface.php` (`src/Udp/SocketUdpClient.php`), `src/Udp/WaiterInterface.php` (`src/Udp/ProductionWaiter.php`), `src/AnimeResponseParser.php`, `src/Parser/FilenameTitleExtractor.php`, `src/Parser/EpisodeExtractor.php`, `src/TitleDump/TitleDumpManager.php`, DTO `src/Dto/AnimeDto.php`.
- **Flow**: `lookup()` → `FilenameTitleExtractor::extract()` → `ensureConnected()` (lazy, never at boot) → `EpisodeExtractor::extract()` → `TitleDumpManager::search()` → fallback `ANIME aname=` UDP → `AnimeResponseParser::parseAnimeResponse()` → `mapToMetadataReturn()`/`mapAnimeStatus()`.
- **Title dump**: `src/TitleDump/TitleDumpIndexer.php` (download + index off the event loop), `src/TitleDump/TitleDumpUrlMigration.php` (stored `http://` URL → https).
- **Settings** (`plugin.json`): `username`, `api_key` (secret), `use_title_dump`, `title_dump_url`.
- **Protocol/design**: `PLAN.md` (UDP flood limits, amask bits, session lifecycle, DTO); `docs/HOST_INTEGRATION.md` (host wiring).
- **Tests**: `tests/Unit/` and `tests/Unit/TitleDump/`, bootstrap `tests/bootstrap.php` (loads `tests/Stub/MetadataProviderInterface.php` when the host interface is absent).

## Conventions

- `declare(strict_types=1);` and the `@copyright`/`@license` docblock in every PHP file (`php scripts/add-copyright-headers.php src`).
- Tests namespace `Phlix\Anidb\Tests\`; reach private methods with `ReflectionClass` + `setAccessible(true)`; edge cases via `static` data providers; inject the seams instead of hitting the network.
- `lookup()` returns a fixed key set (`title`, `original_name`, `overview`, `year`, `genres`, `rating`, `vote_count`, `poster_url`, `fanart_url`, `episodes`, `type`, `anidb_id`, `titles`, `status`, `runtime_ticks`, `studio`, `studios`, `source`, `is_movie`, `synonyms`, `episode_number`) or `[]`.
- `onEnable()` is wiring only — no network/disk I/O; defer to `ensureConnected()` on first lookup.
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
If the pre-commit hook is not set up, read `.agents/skills/setup-caliber/SKILL.md` and follow the setup instructions.
<!-- /caliber:managed:sync -->
