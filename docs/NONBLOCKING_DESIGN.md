# Non-blocking contract in the resident host (ANI-1 / ANI-S22)

Design note. Status: **options laid out; the choice is owner-gated.** No
behaviour change has landed under this note, and nothing here is a cure by
itself: the M-ANIDB-16 precedent is that honest comments alone do not satisfy
the `MetadataSourceInterface` non-blocking law (phlix-shared,
`src/Metadata/MetadataSourceInterface.php`, "Implementations must be
non-blocking in a resident-memory (Workerman) host").

## Current state (verified at the dispatch tip)

- `Udp\ProductionWaiter::wait()` is a blocking `usleep` (capped at 5 s). It is
  the default waiter in both `AnidbMetadataProvider` and `Udp\UdpClient`.
- `UdpClient` flood pacing (>= 4 s between packets after the first 5) and the
  reply budget go through that waiter, so a throttled lookup occupies the
  worker for the pacing window plus the socket read budget.
- `Udp\SocketUdpClient` performs blocking-style socket reads bounded by a
  timeout.
- The `WaiterInterface` seam exists, so a cooperative waiter can be injected,
  but nothing in the default registration path injects one.

## Options

| Option | What it does | Cross-repo? |
|--------|--------------|-------------|
| (a) async-native default | Default waiter and transport are cooperative (event-loop timer + non-blocking datagram socket); `ProductionWaiter` becomes CLI/test-only | Likely: needs a host-provided loop/timer handle (phlix-shared seam, host injection in server/hub) |
| (b) refuse registration | `onEnable()` refuses to register the source until the host injects non-blocking waiter + transport seams | Yes: host must inject the seams; contract text may need a phlix-shared change |
| (c) honest disclosure | Registry-visible statement of the worker-occupation window (worst case ~ pacing + read budget per lookup) | No, but explicitly not a cure per M-ANIDB-16 |

## Constraints for whichever option is chosen

- `vendor/` is never edited in place; any interface-text change is a
  phlix-shared lane plus a re-vendor.
- Acceptance requires seam tests proving the default registration path has no
  busy-wait / blocking-sleep arm, plus full phpunit and phpstan.
- Lands before ANI-S23 (single UDP owner / cross-process flood accounting),
  which shares the same transport core.

## Open decision

Owner to choose (a), (b) or (c). Until then the plugin remains on the
blocking default and the disclosure above stands as the only statement of the
contract gap.
