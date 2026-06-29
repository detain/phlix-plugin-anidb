# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[gotcha:project]** The default branch in the Phlix repos (including this plugin) is `master`, NOT `main`. `git push origin main` fails with `error: src refspec main does not match any` — push to `master`. Verify with `git branch --show-current` before assuming the branch name.
- **[gotcha:project]** A GitHub Actions workflow whose `on.push.branches` lists only `[main]` will NEVER run, because commits land on `master`. Set `branches: [master, main]` so CI fires on the actual default branch.
- **[gotcha:project]** `detain/phlix-shared` is NOT on Packagist. composer.json must declare an HTTPS VCS `repositories` entry pointing at `https://github.com/detain/phlix-shared.git`; without it `composer install` fails with an unresolvable-dependency error as composer falls back to searching Packagist.
- **[fix:project]** When `composer validate --strict` reports `lock file is not up to date` or `Required package "detain/phlix-shared" is not present in the lock file`, run `composer update detain/phlix-shared --no-interaction --no-progress` to refresh the lock — do NOT hand-edit composer.lock.
- **[gotcha:project]** `detain/phlix-shared` requires PHP `^8.3`. The CI PHP matrix must be `['8.3', '8.4']` — an older floor like 8.1/8.2 makes composer refuse to install phlix-shared.
- **[env:project]** When pushing over the SSH remote (`git@github.com:detain/...`), `unset GITHUB_TOKEN` first — a stale `GITHUB_TOKEN` in the environment can interfere with the push.
- **[pattern:project]** Don't clone `phlix-shared` as a sibling directory for a `path` composer repository — consumers depend on it via the HTTPS VCS repo + composer.lock instead. Installer/update scripts that clone a sibling `../phlix-shared` are obsolete.
