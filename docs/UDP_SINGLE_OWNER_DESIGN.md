# Single UDP owner / cross-process flood accounting (ANI-2 / ANI-S23)

Design note. Status: **implemented and measured** (owner ruling 2026-11-13
waived the production-box requirement; see "Bind measurement" for the exact
environment). `SO_REUSEADDR` was dropped on the strength of that measurement.

## The problem

`UdpClient` paces packets (>= 4 s apart after the first 5) using
`lastSendTimestamp`, which is **per object, per process**. `onEnable()` runs in
every resident worker (about 14 in production), and each worker builds its own
`UdpClient` + `SocketUdpClient`. So:

- Pacing is not shared: N workers can each send at 0.25 pkt/s, i.e. up to
  N x that toward AniDB, against a limit that AniDB counts **per client IP**.
  14 x 0.5 pkt/s is the ban-math failure mode. The per-AID memo (ANI-S14)
  reduces call volume; it is mitigation, not a cure.
- `SocketUdpClient::open()` sets `SO_REUSEADDR` **before** the bind window, and
  workers slide across the same port window. Whether two processes actually
  share one source port, or silently land on different ports (which trips the
  multi-port ban rule), depends on kernel semantics for UDP + `SO_REUSEADDR`
  and has not been measured on the production host.

## Architecture choice (non-gated, recorded)

Exactly one process owns the AniDB UDP socket and the pacing clock; all other
workers go through it (owner-election by an exclusive lock, e.g. a `flock` on a
well-known file or a local IPC endpoint), or the plugin refuses to talk to
AniDB from non-owner workers. Shape of the seams:

1. `OwnerElectionInterface` (try-acquire / release) - testable with a fake.
2. A shared pacing ledger (last-send timestamp visible to all workers) so
   joint pacing is `max(own, shared)` rather than per-process.
3. Non-owners either delegate the command to the owner or return the cached /
   unavailable result; they never open a UDP socket.

Acceptance tests (seam tests, no network): election yields exactly one owner
among N contenders; joint pacing never emits two packets closer than the
interval across simulated workers; non-owner never calls `open()`.

This shares the transport core with ANI-S22 (non-blocking contract, option
choice still owner-gated, see `NONBLOCKING_DESIGN.md`); the two must be
sequenced, not stacked blind.

## Implemented (ANI-S23)

- `UdpCoordinatorInterface` / `FileUdpCoordinator`: non-blocking `flock` owner
  election on `<cache_dir>/anidb-udp-owner.lock` (kernel-released on owner
  death, so a surviving worker takes over on its next call) and a shared
  pacing ledger `<cache_dir>/anidb-udp-ledger` reserved under an exclusive
  flock: every packet waits `max(local gate, ledger slot)`.
- `UdpClient` takes the coordinator; the transport is opened only by the owner,
  and `sendCommand()` returns `null` in non-owners (callers fall back to the
  title dump / cache). Without a coordinator behaviour is unchanged.
- `AnidbMetadataProvider` wires a `FileUdpCoordinator` on its cache dir when it
  builds the default transport (injected transports stay standalone). If the
  lock file cannot be created the coordinator degrades to standalone with one
  log line rather than disabling AniDB.
- Seam tests: `tests/Unit/UdpSingleOwnerTest.php`.

## Bind measurement (done, local Linux 6.8)

`scripts/udp-bind-probe.php`, run twice (2026-11-13): once at implementation
time and again as the owner-ruled re-measurement. Environment, stated
honestly: the development/orchestration box itself (Ubuntu 24.04.5 LTS, kernel
6.8.0-138-generic, PHP 8.3.6 NTS, ext-sockets), **not** the production worker
host; two separate PHP processes bound to `0.0.0.0:19431`, datagrams sent from
a third process over `127.0.0.1`; AniDB never contacted. Re-run result: 8
datagrams sent sequentially to two listeners (each listener exits after its
first receipt):

| SO_REUSEADDR | 2nd bind | delivery |
|--------------|----------|----------|
| off | `Address already in use` | only the first listener receives |
| on  | succeeds (same port shared) | datagrams are split across both processes |

Linux UDP delivery semantics for this case are kernel-level, not host-specific,
but a different kernel or a non-Linux production OS could differ; re-run the
harness there if the deployment target changes.

Conclusion: with `SO_REUSEADDR` two workers silently share one source port and
the kernel hands a reply to whichever process it picks, so a worker can lose
its own reply. `SocketUdpClient::open()` therefore no longer sets
`SO_REUSEADDR`; a second process fails loudly or slides to another port, and
the owner election above is what guarantees a single stable port. UDP has no
TIME_WAIT, so a restart can rebind immediately without the option.
