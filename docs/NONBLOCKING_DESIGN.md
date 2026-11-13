# Non-blocking contract in the resident host (ANI-1 / ANI-S22)

Design note. Status: **implemented — option (a), async-native default** (owner
ruling). The contract being met is `MetadataSourceInterface` (phlix-shared,
`src/Metadata/MetadataSourceInterface.php`): "Implementations must be
non-blocking in a resident-memory (Workerman) host". The M-ANIDB-16 precedent
stands: honest comments alone do not satisfy it, so the default path itself is
cooperative and pinned by seam tests.

## The problem (pre-ANI-S22)

- `Udp\ProductionWaiter::wait()` is a blocking `usleep` (capped at 5 s) and was
  the default waiter in `AnidbMetadataProvider` and `Udp\UdpClient`.
- `UdpClient` flood pacing (>= 4 s between packets after the first 5) and the
  reply budget go through that waiter, so a throttled lookup parked the whole
  worker for the pacing window plus the socket read budget.
- `Udp\SocketUdpClient` parked in one blocking `socket_select()` (10 s).

## The design

| Piece | Behaviour |
|-------|-----------|
| `Udp\CooperativeWaiter` (default) | Inside a Fiber, suspends the Fiber and resumes it from the event loop; the worker serves other requests meanwhile |
| Loop discovery | Never a declared dependency. `Revolt\EventLoop` (`getSuspension()` + `delay()`) when present; else `Workerman\Timer::add($s, $resume, [], false)` (one-shot: the 4th argument defaults to persistent = true) + `Fiber::suspend()`; else none |
| Host injection | `new CooperativeWaiter($closure)` accepts a host-supplied "suspend the current Fiber for N seconds" closure, bypassing discovery |
| No loop reachable (CLI, tests, a dedicated blocking worker, or outside a Fiber) | Bounded `usleep` (<= 5 s) and `canYield()` reports `false` |
| `Udp\SocketUdpClient::send()` | Polls the reply with non-blocking `socket_select()` and yields to the waiter between empty polls, instead of one 10 s blocking select; when `canYield()` is false it keeps the blocking-select path |
| `Udp\ProductionWaiter` | Remains as the explicit blocking choice (CLI / tests) |

Rejected alternatives: (b) refusing registration until the host injects
non-blocking seams (breaks every host that has not been taught to inject), and
(c) disclosure only (not a cure per M-ANIDB-16).

## Evidence

- `tests/Unit/CooperativeWaiterTest.php`: an injected closure is used inside a
  Fiber with no `usleep`; the outside-a-Fiber fallback; the default
  registration path builds a `CooperativeWaiter`; a silent peer is polled
  non-blockingly with a yield on every empty poll.
- `tests/Unit/CooperativeWaiterAutodetectTest.php`: the discovery closures
  themselves, against process-isolated doubles
  (`tests/Fixture/RevoltEventLoopSpy.php`, `tests/Fixture/WorkermanTimerSpy.php`,
  which declare the real FQCNs and so must never load in the shared PHPUnit
  process): Revolt is detected and parks the Fiber until its delay fires;
  `Workerman\Timer` is detected and armed one-shot; Revolt wins when both exist;
  the Workerman closure is a no-op outside a Fiber; with neither present the
  waiter degrades to the bounded blocking fallback.

## Interaction with ANI-S23

The same transport core is shared with the single-UDP-owner work; see
`UDP_SINGLE_OWNER_DESIGN.md`. A non-owner worker never opens a socket and so
never waits at all.
