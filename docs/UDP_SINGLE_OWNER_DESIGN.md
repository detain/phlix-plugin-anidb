# Single UDP owner / cross-process flood accounting (ANI-2 / ANI-S23)

Design note. Status: **architecture choice recorded; measurement leg
owner-gated.** No bind-behaviour change has landed under this note.

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

## Gated measurement (booked, not performed)

`deferred-gated:ANI-S23 measurement=two-process-same-port-udp-bind-receipt`

Run `scripts/udp-bind-probe.php` on the production box (owner-held host
access). It binds the same local port from two processes with and without
`SO_REUSEADDR`, and records which process receives a datagram. The decision to
drop (or keep) `SO_REUSEADDR` rides that result. Nothing asserting bind
behaviour is added until the measurement exists.
