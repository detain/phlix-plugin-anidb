# AniDB Metadata Provider Plugin — Implementation Plan

## Overview

This plan covers implementing `phlix-plugin-anidb`, a metadata-provider plugin for Phlix Media Server that integrates with AniDB's UDP API and daily title dump to supply anime metadata (titles, descriptions, episodes, ratings, etc.).

**Use case:** When a media file is scanned, the plugin resolves its anime identity via AniDB and returns structured metadata that Phlix stores on the media item.

---

## 1. Architecture

> **Note:** The architecture below reflects the original implementation plan.
> The actual file layout may differ from what was originally planned.

```
phlix-plugin-anidb/
├── composer.json
├── plugin.json
├── src/
│   ├── AnidbMetadataProvider.php      # Implements LifecycleInterface + lookup()
│   ├── AnidbMetadataProviderAdapter.php  # Adapter for MetadataManager interface
│   ├── Udp/
│   │   ├── UdpClient.php              # High-level UDP session client (AUTH, flood protection, 506 retry)
│   │   ├── UdpClientInterface.php     # Transport seam interface
│   │   ├── SocketUdpClient.php        # Raw UDP socket client (socket_* based)
│   │   ├── ProductionWaiter.php       # Blocking waiter (usleep-based)
│   │   └── WaiterInterface.php       # Waiter seam for non-blocking flood protection
│   ├── Parser/
│   │   ├── AnimeResponseParser.php    # Parses ANIME 230 responses
│   │   └── FilenameTitleExtractor.php # Extracts anime title from file paths
│   ├── TitleDump/
│   │   ├── TitleDumpManager.php       # Downloads and manages title dump lifecycle
│   │   └── TitleDumpIndexer.php       # Builds and queries the title index
│   └── Dto/
│       └── AnimeDto.php               # Internal DTO for anime data
└── tests/
    └── Unit/
        ├── AnidbMetadataProviderTest.php
        ├── AnidbUdpSeamTest.php
        ├── AnidbUdpRetryAndOriginTest.php
        ├── AnidbMetadataProviderAdapterTest.php
        ├── FilenameTitleExtractorTest.php
        ├── AnimeDtoTest.php
        ├── TitleIndexSchemaValidationTest.php
        └── TitleDump/
            └── TitleDumpIndexerTest.php
```

### Plugin type: `metadata-provider`
- Entry class: `Phlix\Anidb\AnidbMetadataProvider`
- Settings:
  - `username` (string, required) — AniDB username
  - `api_key` (string, required, secret) — AniDB API password (NOT the website password; see API docs)
  - `use_title_dump` (bool, default: true) — whether to download/use the title dump for search
  - `title_dump_url` (string, default: `http://anidb.net/api/anime-titles.dat.gz`) — title dump URL

---

## 2. AniDB Protocol Reference

### 2.1 Connection Details

| Parameter | Value |
|-----------|-------|
| Server | `api.anidb.net` |
| Port | `9000/UDP` |
| Protocol | UDP (one packet = one command = one response) |
| Encoding | UTF-8 (server-side encoding via `enc=` param) |

### 2.2 Flood Protection (strict!)

| Rule | Limit |
|------|-------|
| Short-term | ≤ 0.5 pkt/sec after first 5 packets |
| Long-term | ≥ 4 seconds between packets |
| Ban trigger | Too many different UDP ports from one IP within ~1 hour |

**Implementation:** `UdpClient` MUST enforce a 4-second minimum delay between commands. It MUST reuse the same local UDP port.

### 2.3 Session Lifecycle

```
1. AUTH user=X&pass=X&protover=3&client=phlix&clientver=1
   → 200 SESSION_KEY LOGIN ACCEPTED
   → 201 SESSION_KEY LOGIN ACCEPTED - NEW VERSION AVAILABLE
   → 500 LOGIN FAILED
   → 504 CLIENT BANNED

2. [Send commands with &s=SESSION_KEY]

3. PING every ~30 min OR logout after 35 min idle

4. LOGOUT s=SESSION_KEY
   → 203 LOGGED OUT
```

**Session validity:** 35 minutes of inactivity. Keep-alive via `PING` command.

### 2.4 Key Commands

#### ANIME — Fetch anime details

```
ANIME aid={int4}&amask={hexstr}   # by AID
ANIME aname={str}&amask={hexstr} # by name (returns first match)

Response 230:
{int4 aid}|{int4 eps}|{int4 ep count}|{int4 special cnt}|{int4 rating}|{int4 votes}|
{int4 tmprating}|{int4 tmpvotes}|{int4 review rating avg}|{int4 reviews}|
{str year}|{str type}|{str romaji}|{str kanji}|{str english}|{str other}|
{str short names}|{str synonyms}|{str category list}

Example (amask=00f0f0f0000000):
230 ANIME
1|1999-1999|TV Series|Space,Future,Plot Continuity,SciFi...|Seikai no Monshou|星界の紋章|Crest of the Stars||
```

**amask bits (byte1-byte7):**
- Byte 1: aid, dateflags, year, type, related_aid_list, related_aid_type, (2 retired)
- Byte 2: romaji, kanji, english, other, short_names, synonyms, (2 retired)
- Byte 3: episodes, highest_ep, specials, air_date, end_date, url, picname
- Byte 4: rating, vote_count, temp_rating, temp_vote_count, avg_review_rating, review_count, award_list, is_18+
- Byte 5: (retired), ANN_id, AllCinema_id, AnimeNfo_id, tag_name_list, tag_id_list, tag_weight_list, date_record_updated
- Byte 6: character_id_list
- Byte 7: specials_count, credits_count, other_count, trailer_count, parody_count

**Recommended minimal mask:** `00f0f0f0000000` — gets bytes 1-4 (basic info + names + episodes + ratings)

#### ANIMEDESC — Fetch description (separate command)

```
ANIMEDESC aid={int4}&part={int4 partno}  # part=0 first, then part=1, etc.

Response 233:
{int4 current part}|{int4 max parts}|{str description}
```

#### EPISODE — Fetch episode details

```
EPISODE aid={int4}&epno={int4}

Response 240:
{int4 eid}|{int4 aid}|{int4 length}|{int4 rating}|{int4 votes}|{str epno}|
{str eng}|{str romaji}|{str kanji}|{int aired}|{int type}
```

#### UPDATED — Find recently changed anime (for cache invalidation)

```
UPDATED entity=1&age={int4 days}

Response 243:
{int4 entity}|{int4 total count}|{int4 last update date}|{list aid}
```

### 2.5 Title Dump (`anime-titles.dat.gz`)

**URL:** `http://anidb.net/api/anime-titles.dat.gz`
**Update frequency:** Daily (download max once per day)
**Format:** pipe-delimited, gzip-compressed:

```
# aid|type| язы|value
1|main|x-jat|Bokura no Leader
1|main|ja|僕らのリーダー
1|official|en|The Leader of Our Group
1|synonym|x-jat|Bokura no Leader
2|main|x-jat|Utena
...
```

**Title types:**
- `main` — primary title (one per AID, usually romanized)
- `official` — official translated/alternative titles
- `synonym` — synonyms
- `short` — short titles/abbreviations

**Parser tasks:**
1. Download gzip file (if not cached today)
2. Parse line-by-line: `explode('|', $line)` → [aid, type, lang, value]
3. Build in-memory index: `title → aid` and `aid → [titles]`
4. Persist parsed index to local file for fast reload

---

## 3. Implementation Phases

### Phase 1: Skeleton and Plugin Infrastructure

**Goal:** Get the plugin loading, wiring to LifecycleInterface, and basic settings.

- [ ] Rename namespace in `src/HelloMetadataProvider.php` → `Phlix\Anidb\AnidbMetadataProvider`
- [ ] Update `plugin.json`: name, entry FQCN, settings schema
- [ ] Update `composer.json`: autoload PSR-4, `type: phlix-plugin`, dependency on `detain/phlix-shared`
- [ ] Implement `onEnable()`: store container, initialize UDP client (lazy)
- [ ] Implement `onDisable()`: close session, flush logs
- [ ] Implement `subscribedEvents()`: return empty array (no PSR-14 events needed for Phase A)

### Phase 2: UDP Client with Flood Protection

**Goal:** Reliable UDP communication respecting AniDB's rate limits.

- [ ] `UdpClient` class:
  - Fixed local port (>1024, reused) to avoid multi-port ban
  - `send(string $data): string|false` — sends one packet, returns response
  - Internal rate limiter: 4-second rolling window, track last-send timestamp
  - `sendWithRetry(string $data, int $retries = 3): string|false` — on timeout (604), exponential backoff
  - Parse response: `"{code} {message}\n{data}"` pattern
- [ ] `Session` class:
  - `auth(string $user, string $key): bool` — sends AUTH, stores session key
  - `logout(): void` — sends LOGOUT, clears session
  - `ping(): bool` — sends PING to keep session alive
  - `keepAlive(): void` — called internally if session is ~30 min old
  - `getSessionKey(): ?string` — returns current session or null
- [ ] Connection test: verify login works with provided credentials

### Phase 3: Title Dump Integration

**Goal:** Enable fast title→AID lookups without hitting the UDP API.

- [ ] `TitleDumpParser` class:
  - `parseGzip(string $filepath): \Generator` — streams gzip lines
  - Produces `iterable<array{ aid: int, type: string, lang: string, title: string }>`
- [ ] `TitleIndex` class:
  - `buildFromGenerator(iterable $titles): void` — builds inverted index
  - `buildFromFile(string $gzPath): void` — convenience wrapper
  - `search(string $query, int $limit = 20): array<int, array{ aid: int, title: string, type: string }>` — prefix/infix match
  - `findByAids(array<int> $aids): array<int, array>` — reverse lookup
  - Persists index to `var/plugins/phlix-plugin-anidb/title_index.json`
  - Checks `If-Modified-Since` / ETag before re-downloading
- [ ] `TitleDumpUpdater` class:
  - `shouldUpdate(): bool` — checks if dump is >24h old
  - `download(string $url): string` — downloads to temp location
  - `update(): bool` — atomic replace if download succeeds
- [ ] Integration: call `TitleIndex::search()` in `lookup()` before falling back to API

### Phase 4: ANIME Command and Response Parsing

**Goal:** Fetch structured anime data from AniDB.

- [ ] `AnimeCommand` class:
  - `byAid(int $aid, string $amask = self::DEFAULT_MASK): ?string` — raw response
  - `byName(string $name, string $amask = self::DEFAULT_MASK): ?string` — first match
  - `DEFAULT_MASK = '00f0f0f0000000'` — bytes 1-4
- [ ] `AnimeParser` class:
  - `parse(string $raw): AnidbAnime` — parses pipe-delimited response
  - `parseDescription(string $raw): string` — handles ANIMEDESC response
  - Builds `AnidbAnime` DTO with all fields
- [ ] `AnidbAnime` DTO:
  ```php
  readonly class AnidbAnime {
    public function __construct(
      public readonly int $aid,
      public readonly string $romaji,       // main romanized title
      public readonly string $english,      // official english title
      public readonly string $kanji,        // japanese title
      public readonly array  $synonyms,     // short names + synonyms
      public readonly int    $episodes,     // total episode count
      public readonly int    $specials,
      public readonly string $year,         // "1999-2000" format
      public readonly string $type,         // "TV Series", "Movie", etc.
      public readonly float  $rating,
      public readonly int    $voteCount,
      public readonly float  $tempRating,
      public readonly int    $tempVoteCount,
      public readonly string $startDate,    // unix timestamp or 0
      public readonly string $endDate,
      public readonly string $url,          // anidb.net URL
      public readonly string $picname,      // image filename
      public readonly array  $categories,   // genre tags
      public readonly ?string $description,
    ) {}
  }
  ```
- [ ] `getDetails()` implementation in `AnidbMetadataProvider`:
  1. Parse anime ID from file path or filename heuristic
  2. Call `Session->command(AnimeCommand::byAid($aid))`
  3. Parse response with `AnimeParser`
  4. If description needed, call `ANIMEDESC` separately
  5. Return formatted array matching `MetadataValue` shape

### Phase 5: Episode Data

**Goal:** Support episode-level metadata.

- [ ] `EpisodeCommand` class:
  - `byAidEpno(int $aid, int $epno): ?string`
- [ ] `EpisodeParser` class:
  - `parse(string $raw): array` — returns `['eid', 'aid', 'title', 'epno', 'rating', ...]`
- [ ] `getEpisodes(int $aid): array<int, array>` — fetch all episodes for an anime

### Phase 6: Full `lookup()` Integration

**Goal:** Connect all pieces into the plugin entry point.

- [ ] `AnidbMetadataProvider::lookup(string $filePath): array`
  - Input: absolute path to media file
  - Heuristics to extract anime name/episode from filename (parse `S01E02`, `(2020)`, group tags, etc.)
  - Flow:
    1. Extract anime name/episode info from filename
    2. Search title index for matches
    3. If no title dump match, fall back to `ANIME aname=...` via UDP
    4. Fetch anime details + description
    5. Map to return shape:
    ```php
    [
      'title'         => $anime->romaji,
      'original_name' => $anime->english ?: $anime->kanji,
      'overview'      => $anime->description,
      'year'          => (int) substr($anime->year, 0, 4),  // "1999-2000" → 1999
      'genres'        => $anime->categories,
      'rating'        => $anime->rating / 100,  // AniDB uses 1 decimal precision
      'poster_url'    => 'https://api.anidb.net/images/' . $anime->picname,
      'episodes'      => $anime->episodes,
      'type'          => $anime->type,
      'anidb_id'      => $anime->aid,
      'titles'        => [$anime->romaji, $anime->english, $anime->kanji, ...$anime->synonyms],
    ]
    ```
  - Return `[]` if nothing found

### Phase 7: Error Handling and Edge Cases

- [ ] Handle 500 (login failed) — surface to user as "Invalid AniDB credentials"
- [ ] Handle 504 (client banned) — log and return empty; do not retry within cooldown
- [ ] Handle 601/602 (server busy) — retry with exponential backoff up to 5 minutes
- [ ] Handle 330 (no such anime) on ANIME — return `[]`
- [ ] Handle timeout/disconnect — reconnect automatically
- [ ] Handle title dump download failure — fall back to API-only search, warn in logs
- [ ] Validate rate limiting on every command send

### Phase 8: Testing

- [ ] Unit tests for `AnimeParser` with mocked API responses
- [ ] Unit tests for `TitleDumpParser` with sample `.dat` lines
- [ ] Unit tests for `UdpClient` rate limiting (mock socket)
- [ ] Integration test: full `lookup()` with real (test) AniDB credentials
- [ ] CI workflow: run on push to `main`, PHP 8.3+, PHPUnit

---

## 4. Return Value Schema

The `lookup()` method returns an array conforming to what `MetadataManager` and `MetadataValue` consumers expect:

```php
[
    'title'          => string,      // Primary display title (romaji)
    'original_name'  => string|null, // English or Japanese title
    'overview'       => string|null, // Description/synopsis
    'year'           => int|null,    // Release year (first year if range)
    'genres'         => array<int, string>,  // Category tags
    'rating'         => float|null,  // 0.0 - 10.0 scale
    'vote_count'     => int|null,
    'poster_url'     => string|null, // Full URL to poster image
    'fanart_url'     => string|null, // Background image URL
    'episodes'       => int|null,    // Total episode count
    'type'           => string|null,  // "TV Series", "Movie", "OVA", etc.
    'anidb_id'       => int,         // AniDB AID
    'titles'         => array<int, string>,  // All known titles
    'status'         => string|null,  // "Finished", "Currently Airing", etc.
    'runtime_ticks'  => int|null,    // Episode length in ticks (100ns units)
    'studio'         => string|null, // Production studio
]
```

---

## 5. Rate Limiting Strategy

```
UdpClient:
  - private int $lastSendTimestamp = 0
  - private const MIN_INTERVAL_SEC = 4.0

  send(string $data):
    $elapsed = microtime(true) - $this->lastSendTimestamp
    if $elapsed < self::MIN_INTERVAL_SEC:
        usleep((self::MIN_INTERVAL_SEC - $elapsed) * 1_000_000)
    $this->lastSendTimestamp = microtime(true)
    return $this->socket->send($data)
```

For burst operations (e.g., fetching episodes for a series), add 4-second delays between each.

---

## 6. Session Management

```
Session:
  - private ?string $sessionKey = null
  - private ?int $lastActivityTime = null
  - private const SESSION_TIMEOUT_SEC = 35 * 60  // 35 minutes
  - private const PING_INTERVAL_SEC = 30 * 60     // 30 minutes

  command(string $cmd):
    if $this->needsKeepAlive():
        $this->ping()
    $this->sessionKey !== null
        ? "$cmd&s=$this->sessionKey"
        : $cmd
    → send via UdpClient
    → update lastActivityTime
    → return response

  needsKeepAlive(): bool
    return $this->lastActivityTime !== null
        && (time() - $this->lastActivityTime) > self::PING_INTERVAL_SEC
```

---

## 7. Title Dump Refresh Strategy

```
TitleDumpUpdater:
  - private string $cachePath  # var/plugins/phlix-plugin-anidb/anime-titles.dat.gz
  - private string $indexPath # var/plugins/phlix-plugin-anidb/title_index.json

  needsRefresh(): bool
    if not file_exists($this->cachePath): return true
    $mtime = filemtime($this->cachePath)
    return (time() - $mtime) > 86400  # 24 hours

  refresh(): bool
    if not $this->needsRefresh(): return false
    $tmp = $this->cachePath . '.tmp.' . getmypid()
    download($url, $tmp)
    if not verifyGzip($tmp): unlink($tmp); return false
    rename($tmp, $this->cachePath)
    TitleIndex::buildFromFile($this->cachePath)
    return true
```

---

## 8. External Dependencies

| Package | Purpose |
|---------|---------|
| `detain/phlix-shared` ^0.6 | `LifecycleInterface`, `Manifest`, `ManifestType`, events |
| `psr/container` ^1.1\|^2.0 | Container interface |
| `psr/log` ^1.0\|^2.0\|^3.0 | StructuredLogger injection |

**No additional Composer dependencies required.** The UDP socket is built into PHP's `socket_*` extension (or we can use `stream_socket_client` with `SOCK_DGRAM`).

---

## 9. Configuration Flow

```
plugin.json settings
    ↓ (loaded by Phlix plugin loader)
AnidbMetadataProvider::__construct(array $settings)
    ↓
Settings: username, api_key, use_title_dump, title_dump_url
    ↓
onEnable(ContainerInterface $container):
    → Initialize UdpClient (host: api.anidb.net, port: 9000)
    → Initialize Session (NOT logged in yet)
    → If use_title_dump: initialize TitleIndex (lazy-load from disk)
    → If title dump stale: schedule background refresh
```

---

## 10. Open Questions / Future Enhancements (Out of Scope for v0.1.0)

- [ ] **File-level lookup**: Use `FILE` command with `ed2k` hash to identify anime directly from file hash (no filename parsing needed)
- [ ] **Episode images**: Fetch episode thumbnails via separate API or scraping
- [ ] **Group/release info**: Map file's group to AniDB group data
- [ ] **Caching**: Redis or file-based cache for API responses with 24h TTL
- [ ] **Push notifications**: Register for `PUSH` to receive new-file notifications from AniDB
- [ ] **Offline mode**: If title dump is available and API is down, allow searching locally
- [ ] **anisearch fallback**: If AniDB API is rate-limited, fall back to `http://anisearch.outrance.pl/` for title search

---

## 11. References

- [AniDB UDP API Definition](https://wiki.anidb.net/UDP_API_Definition)
- [AniDB API Overview](https://wiki.anidb.net/API)
- [AniDB Anime Titles Dump](http://anidb.net/api/anime-titles.dat.gz)
- [anisearch.outrance.pl](http://anisearch.outrance.pl/) — HTTP wrapper for title search
- [phlix-plugin-example](https://github.com/detain/phlix-plugin-example) — reference plugin
- [phlix-server MetadataManager](file://src/Media/Metadata/MetadataManager.php) — host integration
- [MetadataProviderInterface](file://src/Media/Metadata/MetadataProviderInterface.php) — provider contract
