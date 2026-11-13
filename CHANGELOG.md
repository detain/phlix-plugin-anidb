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

### Changed
- AUTH now follows a live-verified ENCRYPT handshake (`ENCRYPT user=..&type=1`
  -> `209 <salt> ENCRYPTION ENABLED`, key `md5(api_key . salt)`, AES-128-ECB) so
  the API key never travels in the clear; new `AnidbCipher`,
  `EncryptableTransportInterface`, `ext-openssl` requirement. The guessed
  `201 <chal> CHALLENGE` / `auth=md5(...)` leg (ANI-S21) is removed: a live
  capture showed no such reply exists. Wire capture recorded (redacted) in
  `docs/captures/anidb-udp-wire-2026-11-13.md` (ANI-S19/ANI-S21).
- `SocketUdpClient` now drains stale 2xx result frames: while waiting for the
  expected result code, a 2xx frame that provably belongs to an earlier command
  (e.g. a late `ANIME` reply arriving while `ANIMEDESC` is pending) is discarded
  inside the same bounded wait budget instead of being returned as the answer
  (ANI-S20). Whether the real multi-entry 231 continuation needs demux by
  session key is uncaptured (needs an authenticated session).
- PING is now wire-verified: the reply is `300 PONG` (a result frame, not
  ACK-only), so no result-wait exemption is needed; `PING`/`ENCRYPT` join the
  expected-result-code table and the fabricated `200 PONG` fixture is replaced
  (ANI-S19, review ANI-6). Prose polish: HTTPS capitalisation, wording nits.
- Audit gate (MAL-17): `scripts/security-audit-check.php` messages no longer print the slash-stem plurals "advisory/ies" / "repository/ies"; they pluralise correctly (1 advisory, 2 advisories). Estate family wave; test mirrors updated in lockstep.

### Changed
- `fetchAnimeMetadata()` now memoises its mapped result per AID for 60 s
  (bounded to 256 entries, per-instance, successes only) so the adapter's
  search/getDetails/getImages triad costs one UDP round trip instead of
  three (ANI-S14, review ANI-7). A resident worker may therefore serve a
  record up to 60 s stale.
- The title-dump over-long-line refusal notice now reads `REFUSING <n> dump
  line(s) longer than <max> bytes` so the count agrees in number (ANI-S14,
  folded ANI-S13 minor); the refusal itself (ANI-S13) was previously
  unrecorded here.
- **Behavior change — a non-https `title_dump_url` is now refused by default**
  (ANI-S9, review ANI-9). The title dump is the integrity source for every
  worker's title→AID index, and a cleartext leg has no certificate to verify,
  so an on-path attacker could serve a forged dump. The M-ANIDB-10 posture
  only logged a loud warning and fetched anyway; each download attempt for an
  `http://` URL now logs `TitleDumpIndexer: REFUSING download — title_dump_url
  uses cleartext HTTP (http://<host>) …` (host only, never the full URL) and
  fails without contacting the server. **Upgrade note:** an install whose
  `title_dump_url` points at a plain-http third-party mirror stops refreshing
  its offline title index until either the setting is changed to an https
  mirror or the operator opts in by setting the environment variable
  `PHLIX_ANIDB_ALLOW_HTTP_DUMP_MIRROR=1` (exactly `1`; `true`, `yes`, `0` and
  padded values are not an opt-in) in the server's environment. With the
  opt-in the fetch proceeds and still logs the loud cleartext warning on every
  attempt. A stored `http://…anidb.net/…` URL is unaffected — it is still
  migrated to https at plugin construction. The previously cached index keeps
  serving searches while refreshes are refused. Pinned red-first in
  `tests/Unit/TitleDump/TitleDumpCleartextPolicyTest.php` (refuse arm,
  exact-value opt-in, opt-in arm, https untouched); indexer test fixtures that
  exercise a successful fetch moved to https URLs.

### Fixed
- The credential-guard and AUTH-500 messages now name the settings by their
  exact form labels (ANI-S8, review ANI-11). The guard used to say `username
  and API key settings are required (missing or empty: API key)` while the
  form field reads "AniDB UDP API Key", and its docblock claimed a mapping onto
  the form labels it did not print. It now reads `AniDB: the "AniDB Username"
  and "AniDB UDP API Key" settings are required (missing or empty: "AniDB UDP
  API Key")`, and a `500 LOGIN FAILED` reads `invalid "AniDB Username" or
  "AniDB UDP API Key" (the key is the API password set in your AniDB profile,
  not your login password)` — "API password" survives only as the direction
  to where the key is set in the AniDB profile. A test reads the labels from
  `plugin.json` so a relabel there cannot drift silently. Log matchers keyed on
  `username and API key settings are required` or `invalid username or API
  password` need updating.
- The 504 and 555 AUTH-failure messages no longer embed the raw datagram
  tail (ANI-S7, review ANI-17): like the generic arm, they now pass the
  server detail through the M-ANIDB-29 scrubber (session keys redacted,
  whitespace collapsed, length bounded), and a reply with no detail yields
  the bare label with no dangling `—`. The 504 text now reads `this client
  version is banned (your account is not) — <detail>` (review ANI-24), so an
  operator does not chase an account ban that does not exist. Log matchers
  keyed on `client version banned` need updating.
- `AnidbMetadataProvider::onDisable()` now resets the lazy-connect guard
  alongside closing the UDP session (ANI-S6, review ANI-3). It used to close
  the socket but leave `connected` true, so after a disable→enable cycle in
  the same process every lookup skipped `ensureConnected()`'s `open()` and
  sent into a closed transport, leaving the provider poisoned until restart.
  The first lookup after re-enable now re-opens the transport, and
  `onEnable()` stays wiring-only.
- The title-dump `STALLED` notice (in-flight marker path blocked by a
  directory or symlink) no longer ends in a copy-paste `rm -rf "<basename>"`
  that could not be acted on (ANI-S5, review ANI-4): under the M-ANIDB-19
  default the "command" named only `title_index.download.lock`, which is
  wrong from any working directory but the cache dir. The line now states
  the action — delete the blocker, recursively if it is a directory — and
  renders that one marker path in full even without `PHLIX_ANIDB_DEBUG`,
  because it is the line's actionable payload; the cache directory and a
  symlink target stay basename-only by default. No destructive command text
  is logged. Log matchers keyed on `Recover with: rm -rf` need updating.
- The title-dump AID refusal notice now reads `REFUSING <n> dump lines
  whose AID column is malformed or out-of-range (ceiling <max>)` (ANI-S4,
  review ANI-26): the old wording quoted further down this section ("with
  malformed or out-of-range AID") paired a plural line count with a single
  AID. That entry is kept as it was written; this entry records the
  correction. Log matchers keyed on the old phrase need updating; the count
  and ceiling are unchanged and no line content is echoed.
- Rejected UDP reply origins name themselves, and the trusted-origin
  allowlist refreshes itself (review anidb__srphp #9[i]; program w15 L4,
  all tests measured red on the parent first — departed-lane evidence
  `/tmp/opencode/w15L4-red-parent.txt`, takeover re-reproduction
  `/tmp/opencode/w15l4v2-red-parent.txt`, forensics
  `.reviews/2026-11-08/FIX-anidb-w15L4-takeover.md`):
  - `resolveValidOriginHosts()` memoized its A-record set for the whole
    process lifetime, so an AniDB DNS rotation turned every genuine reply
    into a silent timeout inside a resident worker — with no log line
    naming the cause. The memo now expires after
    `ORIGIN_HOSTS_TTL_SEC` (1 hour) and re-resolves on the next
    validation, so a rotated set lands and the departed IP drops out; a
    failed re-resolve keeps the unchanged hostname-only posture. A
    constructor-injected list stays the trusted set: verbatim, never
    expired, never resolved.
  - A rejected reply origin now also writes one `error_log` line naming
    `host:port` (never the payload), throttled to one line per
    `ORIGIN_REJECT_LOG_INTERVAL_SEC` (5 minutes) so a rotation or
    spoofing storm cannot flood the log. The caller-visible outcome is
    unchanged: null reply, same retry law.
- Every echo of a configured `title_dump_url` routes through one scrubber
  (review anidb__secaudit #2[m]; program w15 L4):
  `TitleDumpIndexer::urlForLog()` redacts userinfo to `***` while keeping
  host/path/query/fragment visible, withholds scheme-less-`@` and
  unparseable inputs entirely as `UNLOGGABLE_URL`, and passes
  credential-free URLs byte-identically. The two full-URL echoes — the
  provider constructor's migration log and `httpClientConfig()`'s
  no-host `RuntimeException`, the latter under a false "credential-free
  by contract" claim — now go through it. The scheme guard's refusal and
  the cleartext host-only warning were already honest and stay untouched.
- The `isStale()` `clearstatcache` leg gained its demanded differential
  pin (review w14 L7 follow-up, `.reviews/2026-11-07/REVIEW-w14L7anidb.md`
  [Minor]): seed the stat cache with `is_file()` before a future-dated
  `touch()`, then assert `isStale()` decides on disk truth. Deleting the
  leg — which survived six suite runs at the time — now reddens exactly
  that one test (mutation-proven).
- Title-dump ingestion and settings hardening (reviews anidb__srphp #3, #4,
  #5, #6, #8; anidb__secaudit #1, #3 — program w14 L7, six findings, all
  measured red on the parent first, register evidence
  `/tmp/opencode/w14L7-red-parent.txt`):
  - `isStale()` read a future-dated `title_index.json` as "fresh forever"
    (a negative `time() - mtime` never exceeds the 24 h cap) and an
    unstatable file as "0 seconds old" — the daily refresh froze silently
    for the duration of any clock skew, the exact marker-path failure
    `markerIsLive()` already refuses. Both arms now mean STALE, mirroring
    the marker rule; the freshness half moved into a named predicate so the
    boundary and the arms are testable without a stat race. The decision
    now reads through `clearstatcache(true, $indexFile)`: PHP does not
    invalidate the stat cache on `touch()`, so a cached mtime could mask
    both new arms with an outcome that depends on which earlier code path
    stat'd the file (surfaced as CI-green/local-red order-dependence on the
    first push of this batch). Consequently the M5 reload-on-moved fixture
    no longer fakes a forward move with a future `touch` — an index dated
    ahead of the clock is stale by the very law above, so the test drives
    the same reload law with two past-dated stamps (hour-old → ten-seconds-
    old), deterministic on every runner.
  - `writeIndex()` switched from an in-place `file_put_contents()` to a
    uniquely-named temp file plus `rename()`: readers can no longer decode
    a torn 0-byte body mid-truncate, a planted symlink at the index path is
    refused loudly instead of followed (the write would have gone through
    the link into an attacker-chosen target), and an index left read-only
    by an owner-mismatch self-heals instead of blocking every refresh.
    Rename yields a new inode and mtime, so the manager's
    reload-on-moved provenance law keeps firing unchanged. The documented
    but never-enforced "cache_dir MUST NOT be world-writable" contract
    (plugin.json/README) is now refused at the same construction choke
    point that rejects relative paths — mode and remedy named, the
    `PHLIX_ANIDB_CACHE_DIR` twin included; group-writable stays lawful.
  - The parser's `is_18plus` used a raw `(int)` cast, so leading-digit
    garbage ("1abc", "+1", " 1") flipped the flag, and `temp_rating`
    divided unconditionally, giving int `0` where the `?float` DTO
    declaration (and `rating`'s own null sentinel) mean "no value".
    `is_18plus` now rides `boundedInt`, admitting only the literal "1";
    `temp_rating` is null whenever the column carries no usable value.
  - `parse()` boundary-parses the dump's AID column exactly like the UDP
    path (M-ANIDB-16): digit-only, length-gated (the int cast saturates,
    it does not wrap), inside the shared `MAX_AID` ceiling — which moved to
    `AnimeResponseParser::MAX_AID` as public single source of truth,
    aliased privately in the indexer. Violating LINES are dropped (the
    dump's established per-line law) and the refusal is loud: one
    `REFUSING <n> dump lines with malformed or out-of-range AID` count
    without echoing any violating line content; the whole-dump zero-entries
    refusal (review M2) stands over it.
  - Settings are normalized once in the constructor instead of stored raw
    — the old shape made `use_title_dump` reads in `onEnable()`/
    `findAidByTitle()` and `UdpClient::authenticate()`'s credential
    subscripts warn once per lookup inside a resident worker on partial or
    wrongly-typed host maps, and a stored string "false" read TRUTHY (a
    disabled dump silently re-enabled). Declared keys now exist with their
    plugin.json types from construction on; absent credentials stay silent
    — `requireCredentials()` remains the descriptive AUTH-time net — and
    present-but-wrong types are reported once, value-free, then defaulted.
    (Review anidb__srphp #8[m].)

- Deleted `EpisodeExtractor::isMoviePattern()` and its `MOVIE_PATTERNS`
  table: nothing in `src/` ever called it (only its own tests did), and its
  naive substring matching — "Anime S01E01" is clean, but "The Special
  Edition 01" would have flagged every filename containing " sp" —
  contradicted the real, type-based movie detection in
  `AnimeResponseParser::detectIsMovie()`. Dead code with a live-looking
  name is a wrong-answer magnet for the next contributor; the seven
  pattern tests went with it. (Review anidb__srphp #7[m].)


- The flood interval now gates EVERY packet (review anidb__srphp #1). The
  keep-alive leg sent its PING and the command back-to-back — the gate ran
  once before the ping and refreshed its stamp there — and the 506 re-auth
  AUTH left with no interval check at all behind the command that had just
  expired the session: two datagrams milliseconds apart against AniDB's
  one-packet-per-four-seconds policy, risking a rate-limit ban of the shared
  session for exactly the two senders the waiter seam existed to discipline.
  The gate moved into `udpSend()` — the single choke point every datagram
  leaves through — so commands, PING, AUTH and LOGOUT all pay the interval;
  the boot AUTH still leaves immediately because there is no previous send
  to space behind it. The `authenticate()` docblock's "first packet" bypass
  justification (true at boot, false on re-auth) was rewritten to current
  truth. Three new timeline tests pin the invariant through a transport fake
  recording send/wait events with no real sleeps: the keep-alive and re-auth
  legs measured red on the parent, the boot characterization is green on
  both sides by design.

- Copy-currency batch (review anidb__copywriter #6, #7, #9–#11): the README
  "Data Returned" sample showed every fixed key except the always-set
  `description_fetch_failed` flag — the one that distinguishes "no synopsis
  exists" from "synopsis fetch failed" — and the row was added; the
  AGENTS.md/CLAUDE.md settings list gained the omitted `cache_dir`, the
  setting whose misconfiguration produces the loudest operator errors; the
  AUTH-failure messages unified on lowercase after the colon (matching the
  rest of the message family) and the two near-colliding ban labels were
  disambiguated against the AniDB UDP API definition — 504 is "client
  version banned" (that client software is banned, not the user account),
  555 is "temporarily banned" (the server's developer-aid message for a
  temporary ban, typically from API misuse such as flooding) — with their
  test pins rotated from a measured red in the same commit; the manifest's
  "daily anime titles dump" became "daily anime title dump" (one adjective
  form across the surfaces); and the fork instruction now says "set
  `version` to `0.1.0` for your fork" — "bump" describes the opposite
  direction for what is a reset.
- Copy-currency + docs-truth batch (wave 3 L9; review anidb__copywriter #1–#5,
  anidb__srphp #6[i]): the README install URL pointed at `main/` — the default
  branch is `master` and the pasted URL 404s, so install step 3 was inert; the
  tagline link `detain/phlix` (README, AGENTS.md, CLAUDE.md) is a 404 repo,
  repointed to `detain/phlix-server`; install step 5 said "API Password" where
  the form renders "AniDB UDP API Key" — the field is now named as labeled; the
  `title_dump_url` description's colon-spliced sentence was reworded to the
  clean README phrasing (em-dash, fixed articles); the credentials-guard
  message now reads "AniDB: username and API key settings are required
  (missing or empty: …)" with human-style field names, replacing the lowercase
  `anidb:` prefix and raw `username/api_key` keys (its test pins rotated from a
  measured red in the same commit). The indexer-lifecycle docblocks — the
  `$downloadInFlight` property and the SM-0.3a fail-closed box — now describe
  the post-M5 split (the flag covers same-object re-entry, the marker the
  worker fleet) with the pre-M5 measured history preserved verbatim in frame
  (50 fresh indexers → 50 downloads, fail-closed → 0).

- Anime status now consults AniDB's `dateflags` bitmask (M-ANIDB-17, review
  anidb__srphp M7): the amask field-1 column (1 = ongoing, 2 = finished) was
  mapped positionally and DISCARDED, so `mapAnimeStatus()` decided on dates
  alone — every end_date-less movie with a past start read as "Currently
  Airing" forever, and a stale end date could bury an authoritative ongoing
  flag. The parser and `AnimeDto` now carry `dateflags`; the mapper is
  flag-first (finished before ongoing), falls through the date heuristic on
  "other"/no signal, and adds an `is_movie` fallback for flagless movies.

- The "daily" title dump is finally daily inside a live process
  (M-ANIDB-15, review anidb__srphp M5): `ensureLoaded()` memoized the index
  permanently — including the empty `[]` of a failed cold download — and the
  indexer ran exactly once per provider instance, so a resident worker served
  boot-day aliases forever (or nothing at all) while `MAX_AGE_SECONDS` and
  the marker retry floor were dead logic. `search()` now re-enters
  `downloadAndIndex()` through a throttled per-window freshness pass
  (one stat between windows), reloads when the file is replaced under the
  memo (async completion / sibling worker), and recovers an empty memo when
  a valid file appears. The manager retains ONE scheduler so the
  short-circuits actually guard a single attempt stream.

- `ensureConnected()` no longer zombied the provider on a transient socket
  failure (M-ANIDB-14, review anidb__srphp M4): the old set-then-open order
  marked the session connected BEFORE `open()` ran, so a single bind/create
  failure stuck the flag forever — every later command died on "UDP socket
  not open" and no retry was ever attempted. `connected` now flips only
  after a successful open, and the title-dump branch runs first, so a
  UDP-less provider still schedules its offline index instead of losing
  both halves to one skipped branch.

- UDP transport now tolerates AniDB's framing frames (M-ANIDB-13, review
  anidb__srphp M3): `send()` read exactly one datagram per command, so a
  leading 001 ACK became the AUTH "reply" (regex fail) and a trailing
  005 RELEASE poisoned the NEXT command's response. The read loop now skips
  001/002/005 frames inside ONE shared 10 s budget and returns the first
  result-code frame; single-packet behavior is byte-identical. Multi-packet
  002 payload reassembly stays a disclosed residual (needs a credentialed
  live capture — the reviewer's own uncertainty gate); a split reply now
  fails as a bounded timeout instead of a half-read string.

- Title dump: an EMPTY parse result is a failed attempt, not a success
  (M-ANIDB-12, review anidb__srphp M2): `parse()` yielding zero entries was
  written as `[]`, reported `true`, released the in-flight marker, and the
  fresh mtime then pinned an empty offline index for a full 24 h — the
  "2-byte title_index.json" symptom. A non-encodable index (invalid UTF-8
  from a corrupt mirror) likewise slipped through `json_encode` returning
  false because `file_put_contents(mixed)` wrote an empty file whose `0`
  read back as success. Both paths now fail loud, persist nothing, and keep
  the marker held as the retry floor.

- 230 ANIME `synonyms` now reads its OWN column (M-ANIDB-11, review
  anidb__srphp M1): the parser mapped amask field 10 (`short_names`) into
  `synonyms` and never read field 11 — short names persisted as synonyms and
  AniDB's real synonym list never reached the database. Parser and `AnimeDto`
  now carry BOTH lists separately; the titles corpus merges them (visible
  title content preserved), the `synonyms` return key finally means synonyms.
  Three tests that encoded the old mapping were flipped to the wire-truth
  shape (field-indexed fixtures in `AnidbMapperDtoTest` now distinguish the
  two columns).
- Title-dump fetch refuses redirects and makes cleartext loud (M-ANIDB-10,
  review anidb__secaudit M3): `dumpRequestOptions()` pins
  `allow_redirects.max = 0` — a 301 can no longer walk an https download down
  to a plaintext leg the M-ANIDB-08 context cannot verify (the 3xx then fails
  the existing gzip REFUSING arm loudly); `fetch()` logs one loud cleartext
  warning naming the host (never credentials). Operator http:// mirrors are
  still accepted (documented mirror support — hard-refusal left as a
  disclosed owner call). 4 new pins, hermetic, in
  `tests/Unit/TitleDump/TitleDumpCleartextPolicyTest.php`.
- Title-dump download now VERIFIES TLS (M-ANIDB-08, review anidb__secaudit M1):
  `TitleDumpIndexer::httpClientConfig()` passes a strict `context.ssl`
  (verify_peer/verify_peer_name true, allow_self_signed false, explicit
  peer_name) to the Workerman client — the vendored pool's base context accepts
  ANY certificate without it, letting an on-path attacker poison every
  worker's title index. Optional `PHLIX_ANIDB_CA_BUNDLE` trust-root pin fails
  loud when unusable. Guarded by 7 new pins in
  `tests/Unit/TitleDump/TitleDumpHttpClientConfigTest.php` (config shape +
  wiring source-scan — the S440 gate files stay zero-diff).
- Misleading "non-blocking"/"never parked" claims removed (M-ANIDB-07, review
  anidb__docmaint M7): `SocketUdpClient`'s header/send docs now state the 10 s
  select wait is a bounded WALL-CLOCK occupation of the calling worker (the
  mechanism differs from a blocking recvfrom; the parking does not), the
  indexer's `usleep(1000)` cooperative-wait docblock admits the busy-poll
  occupies the worker for up to its 60 s budget while crediting that no
  synchronous socket read happens in-thread, and `UdpClient::sendCommand` no
  longer calls itself "non-blocking". Full async I/O remains the M-ANIDB-16
  architecture question.
- `lookup()` return-shape contract stated truthfully everywhere (M-ANIDB-06,
  review anidb__docmaint M6): `episode_number` is the ONE optional key (present
  only when the filename yields one — absent, never null). The docblock shape,
  README sample annotation, AGENTS.md and CLAUDE.md fixed-key-set claims were
  all corrected to the 21-fixed-keys + optional-key reality (commit `1ae91ce`).
- Boot-timing docblock lies cured (M-ANIDB-05, review anidb__docmaint M5): the
  `$udpSession` property docblock no longer claims onEnable() opens the socket
  (first-lookup lazy connect per item-5c3), and `TitleDumpManager::ensureCacheDir()`
  no longer claims the index "runs once on plugin enable" — both now state the
  wiring-only boot / lazy-ensureConnected truth pinned by `AnidbBootAndOriginConsequenceTest`.
- `docs/HOST_INTEGRATION.md` "Outstanding server-side work" is rewritten to the
  LANDED server truth (M-ANIDB-04, review anidb__docmaint M4): `PluginLoader`
  registers `MetadataSourceInterface` instances into `SourceRegistry`, the
  movie/series resolvers consult plugin sources (`includePluginSources`),
  `PluginMetadataEnricher`/`BackgroundEnrichmentSubscriber` gap-fill queued items
  (omdb / anidb / myanimelist) off the scan path, and the `Application.php`
  throwaway `MetadataManager` is gone. Stale cross-repo line coordinates were
  replaced with file+symbol anchors.
- `docs/HOST_INTEGRATION.md` no longer claims registration carries `['anime','series']`
  (M-ANIDB-03, review anidb__docmaint M3): the media-type ghost is replaced with the
  registered truth — `supportedMediaTypes()` returns `['series', 'movie']`, and "anime"
  is not a host media-type.
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
- Review-campaign minors cluster (W-min6, five items — the last queued
  anidb minors batch; secaudit/srphp lenses): `AnimeResponseParser` now
  validates the AID digit column at the boundary (digits only, 1..10,000,000,
  whole-parse rejection) so `url` is never built from raw or overflowed wire
  bytes, and every derived numeric goes through a bounded cast that
  collapses non-digit/oversized columns to 0 instead of overflowing to
  `PHP_INT_MAX` (M-ANIDB-16). `AnimeDto` became THE parser→mapper boundary:
  it carries `description`, `is_movie` and `description_fetch_failed`, and
  `mapToMetadataReturn()`/`mapAnimeStatus()` read only typed DTO properties
  — the blind `array<string,mixed>` key lookups are gone (M-ANIDB-21).
  ANIMEDESC now distinguishes "we could not ask" from "the server answered:
  none": timeouts/malformed 233 payloads are retried exactly once and, if
  still failing, surface as `description_fetch_failed: true` on the lookup
  return, while definite answers ('503', well-formed-empty) are never
  retried — the text-only `fetchAnimeDescription()` seam retired into the
  classifying `describeAnime()` (M-ANIDB-26). The generic `parseAuthFailure`
  arm no longer interpolates the raw datagram: session keys inside
  unexpected `200/201 <key>` frames are redacted and the message body
  bounded to 120 bytes (M-ANIDB-29). `SocketUdpClient::open()` binds its
  preferred port and slides across an 8-port window on collision — a second
  instance on one host no longer deterministically trips the M4
  permanent-death path — with the actually-bound port exposed via
  `boundPort()` (M-ANIDB-33). Suite 397→419 tests, 1214→1296 assertions
  (22 new pins, three re-cuts).

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
