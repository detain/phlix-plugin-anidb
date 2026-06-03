---
name: metadata-provider-method
description: Adds or extends a private method on src/AnidbMetadataProvider.php following the lookup→parse→map pattern (extractAnimeName/findAidByTitle/fetchAnimeDetails/parseAnimeResponse/mapToMetadataReturn/mapAnimeStatus), preserving the fixed lookup() return shape and AniDB UDP flood rules. Use when the user says 'add provider method', 'extend lookup', 'map anidb field', 'parse a new amask byte', or edits AnidbMetadataProvider.php. Do NOT use for plugin.json/composer.json settings edits, for writing tests (use the project's phpunit conventions), or for unrelated plugins.
---

# AniDB Metadata Provider Method

Extend `src/AnidbMetadataProvider.php` (`Phlix\Anidb\AnidbMetadataProvider`, the `entry` in `plugin.json`). It implements `Phlix\Shared\Plugin\LifecycleInterface` and is the single class in this plugin — all logic is private methods called from `lookup()`.

## Critical

- **NEVER change the `lookup()` return shape.** The keys are fixed and consumed by Phlix's MetadataManager: `title, original_name, overview, year, genres, rating, vote_count, poster_url, fanart_url, episodes, type, anidb_id, titles, status, runtime_ticks, studio`. A no-match returns `[]` (empty array), never a partial array. New AniDB data must be folded into an EXISTING key — do not add top-level keys.
- **Every new UDP request goes through `private function sendCommand(string $command): ?string`** — never call `socket_sendto`/`udpSend` directly. `sendCommand()` already attaches `&s=$sessionKey`, enforces the 4s flood gate (`enforceFloodProtection()`), pings on keepalive, and re-auths+retries once on a `506` (session expired) response. Bypassing it risks an AniDB ban.
- **Respect flood limits.** Do not add loops that fire multiple `sendCommand()` calls without need; each call may `usleep` up to 4s. `FLOOD_PROTECTION_INTERVAL_SEC = 4.0`.
- `declare(strict_types=1);` is on line 3 of every PHP file — keep it. Namespace is `Phlix\Anidb`.
- This plugin makes **no DB calls** (no PDO/mysqli). Persistent data lives in `$this->cacheDir` as JSON.

## Instructions

1. **Locate the right section banner.** The class is divided by comment banners; add your method under the matching one:
   - `// Private: Socket & Session` (auth, ping, logout)
   - `// Private: UDP Command Execution` (sendCommand/udpSend/flood)
   - `// Private: Title Lookup` (findAidByTitle, searchTitleDump)
   - `// Private: Anime Details Fetch & Parse` (fetchAnimeDetails, fetchAnimeDescription, parseAnimeResponse)
   - `// Private: Filename Parsing` (extractAnimeName)
   - `// Private: Response Mapping` (mapToMetadataReturn, mapAnimeStatus)
   Verify the banner exists before inserting; if none fits, you are probably editing the wrong class.

2. **Write the method signature with a full PHPDoc block** matching the surrounding style: a one-line summary, `@param` with types, `@return` with array-shape annotations (e.g. `array<string, mixed>|null`), and `@throws \RuntimeException` where it can throw. All new methods are `private` unless they are part of `LifecycleInterface`. Use typed params and return types — never untyped. Verify the visibility/return type before proceeding.

3. **If the method issues an AniDB request**, build the command string like the existing ones and call `sendCommand()`:
   ```php
   $response = $this->sendCommand('ANIMEDESC aid=' . $aid . '&part=0');
   if ($response === null || !str_starts_with(trim($response), '233')) {
       return null;
   }
   ```
   Always (a) null-check the response, (b) guard on the AniDB numeric status prefix (`230` ANIME, `233` ANIMEDESC, `230 ANIME` + AID for name search), (c) return `null` on any mismatch. Verify the status code against `PLAN.md` / the AniDB UDP API before hardcoding it.

4. **If the method parses a response, follow `parseAnimeResponse()` exactly:**
   - Split body off the status line: `$lines = explode("\n", trim($raw), 2);` then `explode('|', $lines[1])`.
   - Guard the field count BEFORE indexing (`if (count($fields) < 27) { return null; }`). Update this count if you widen the `amask`.
   - Decode escaped chars with the local closure: `$decode = fn(string $s): string => str_replace(["\`", '/', "\n"], ["'", '|', ' '], $s);` (AniDB escapes `'`→backtick, `|`→`/`).
   - Ratings are integers ×100: `$rating = (int)$fields[19]; $ratingFloat = $rating > 0 ? $rating / 100 : null;`.
   - Comma lists → `array_filter(array_map('trim', explode(',', $decode($fields[N]))))`.
   - Return a flat `array<string,mixed>` using the SAME internal keys (`aid, romaji, english, kanji, synonyms, episodes, year_int, type, categories, rating, vote_count, start_date, end_date, picname, ...`) so `mapToMetadataReturn()` keeps working.

5. **To expose new AniDB data in the output**, edit `mapToMetadataReturn()` only — map your new parsed internal key onto an existing return key. Example: surfacing a studio would set `'studio' => $anime['studio'] ?? null`. Use `?:`/`??` to fall back to `null`, never to a missing-key access. Verify all 16 return keys are still present and in order after your edit.

6. **Wire the method into the flow if needed.** `lookup()` is the only public path and is a fixed 4-step pipeline: `extractAnimeName()` → `findAidByTitle()` → `fetchAnimeDetails()` → `mapToMetadataReturn()`, each returning `[]` early on null. A new fetch helper is typically called from `fetchAnimeDetails()` (like `fetchAnimeDescription()` is), not added as a new `lookup()` step.

7. **Add/extend a Reflection test** in `tests/Unit/AnidbMetadataProviderTest.php` (namespace `Phlix\Anidb\Tests\Unit`). Construct the provider with the 4-key settings array (`username, api_key, use_title_dump, title_dump_url`), reach the private method via `ReflectionClass` + `getMethod()` + `setAccessible(true)`, and assert with a `static` data provider for edge cases:
   ```php
   $reflection = new \ReflectionClass($provider);
   $method = $reflection->getMethod('parseAnimeResponse');
   $method->setAccessible(true);
   $result = $method->invoke($provider, $rawResponse);
   ```
   Do not write tests that need a live socket — anything touching `sendCommand()`/`lookup()` without `onEnable()` throws `RuntimeException('UDP socket not open')`.

8. **Verify:** run `vendor/bin/phpunit --testdox` and confirm green. This is the same suite CI runs (`.github/workflows/test.yml`, PHP 8.3+). Do not claim done until the output shows all tests passing.

## Examples

**User says:** "Map the AniDB studio/animator into the output."

**Actions taken:**
1. Widen `DEFAULT_ANIME_MASK` (or note studio isn't in the basic amask — AniDB exposes it via the `CREATOR`/category data, currently `studio => null`).
2. In `parseAnimeResponse()`, bump the `count($fields) < N` guard and add `'studio' => $decode($fields[M])` to the returned array.
3. In `mapToMetadataReturn()`, change `'studio' => null` to `'studio' => $anime['studio'] ?: null`.
4. Add `test_parses_studio_field()` using a `230 ANIME\n…|…` raw string via Reflection.
5. `vendor/bin/phpunit --testdox`.

**Result:** New data flows through the pipeline; the 16-key return shape is unchanged; `studio` is now populated; tests pass.

## Common Issues

- **`Undefined array key 19` (or similar) in parse:** the `count($fields) < 27` guard is missing or too low after you widened the amask. Recount the field order in the `parseAnimeResponse()` comment block (byte/field map) and raise the guard.
- **Test throws `RuntimeException: UDP socket not open`:** you are exercising `lookup()`/`sendCommand()` without `onEnable()`. Test the private parse/map helper directly via Reflection instead (see `test_parses_anime_response_correctly`).
- **Output rating is `853` not `8.53`:** AniDB stores ratings ×100. Divide by 100 and null-guard: `$r > 0 ? $r / 100 : null`.
- **Apostrophes show as backticks / titles contain `/`:** you skipped the `$decode` closure. AniDB escapes `'`→backtick and `|`→`/`; always run field text through `$decode()`.
- **`MetadataManager` errors or silently drops the result:** you altered, removed, or reordered a `lookup()` return key. Restore all 16 keys exactly as in `mapToMetadataReturn()`.
- **Intermittent AniDB `555`/`504` ban responses:** a new code path is calling `udpSend()` directly or looping `sendCommand()` too fast. Route every request through `sendCommand()` and avoid back-to-back calls.
- **`230` check fails on a valid response:** trim before matching — AniDB responses have trailing newline; use `str_starts_with(trim($response), '230')`.