<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Udp;

use Socket;

/**
 * Default, production {@see UdpClientInterface} implementation.
 *
 * Wraps the timeout-bounded `socket_*` lifecycle (create / bind / sendto / select +
 * recvfrom / close). Compared to the original inline implementation:
 *
 * - AF_INET / SOCK_DGRAM / SOL_UDP socket, SO_REUSEADDR set;
 * - bound to a local port above 1024 (a preferred port, sliding across a small
 *   window if taken) to keep a stable source port for AniDB;
 * - send/receive waits bounded to 10 s via `socket_select()`: the wait is
 *   bounded and readable, but it is a real wall-clock wait — the calling
 *   worker is occupied for up to 10 s when no reply arrives (select parks the
 *   process just as long as a blocking recvfrom with SO_RCVTIMEO would; the
 *   difference is the mechanism, not the occupation);
 * - {@see send()} sendto's the datagram then waits on `socket_select()` within
 *   ONE shared 10 s budget, skipping protocol framing frames (001 ACK / 002
 *   fragment / 005 RELEASE) and returning the first result-code payload (or
 *   null on timeout). Multi-packet 002 payload REASSEMBLY is not implemented —
 *   a fragment-split result ends as a bounded timeout failure, never a
 *   half-read reply (disclosed residual, see send());
 * - {@see close()} closes the socket if open and clears the bound port.
 *
 * The reply's source host/port are captured by {@see send()} for the caller —
 * {@see UdpClient} validates them against the expected AniDB endpoint
 * (UdpClient::isValidReplyOrigin()); this transport itself performs no flood
 * protection or origin validation.
 *
 * @package Phlix\Anidb\Udp
 * @since 0.3.0
 */
final class SocketUdpClient implements UdpClientInterface
{
    /**
     * Number of consecutive local ports open() may try starting at localPort.
     *
     * AniDB requires one stable source port per session — not one *specific*
     * port — so when the preferred port is already claimed (e.g. a second
     * instance on the host) a small forward window lets open() find a free
     * port instead of failing hard (review M-ANIDB-33).
     */
    private const BIND_PORT_WINDOW = 8;

    /**
     * Wall-clock budget for one send()/reply exchange, shared across every
     * datagram the read loop consumes (review anidb__srphp M3: with framing
     * frames to skip, the budget must bound the WHOLE exchange, not restart
     * per datagram — an endless stale stream can never extend the wait).
     */
    private const RESPONSE_BUDGET_SECONDS = 10;

    /**
     * Maximum datagram size the transport reads, matching the AniDB UDP MTU
     * contract.
     */
    private const MAX_DATAGRAM_BYTES = 1400;

    /**
     * AniDB UDP API server hostname (datagram destination).
     */
    private string $host;

    /**
     * AniDB UDP API server port (datagram destination).
     */
    private int $port;

    /**
     * Fixed local port to bind above 1024 (avoids AniDB multi-port ban).
     *
     * Preferred start of the bind window — see BIND_PORT_WINDOW.
     */
    private int $localPort;

    /**
     * The local port actually bound by open() (null until bound).
     */
    private ?int $boundPort = null;

    /**
     * Local UDP socket.
     *
     * PHP 8's socket_create() returns a Socket instance (not a resource) —
     * the previous `@var resource|null` fiction was what five phpstan
     * Socket-vs-resource ignores papered over (review M-ANIDB-20).
     */
    private ?Socket $socket = null;

    /**
     * Source host of the most recently received reply (null until first reply).
     */
    private ?string $lastReplyHost = null;

    /**
     * Source port of the most recently received reply (null until first reply).
     */
    private ?int $lastReplyPort = null;

    /**
     * @param string $host      AniDB UDP API hostname (default api.anidb.net).
     * @param int    $port      AniDB UDP API port (default 9000).
     * @param int    $localPort Preferred local port to bind above 1024 (default 9001);
     *                          open() slides forward across a small window if it is taken.
     */
    public function __construct(
        string $host = 'api.anidb.net',
        int $port = 9000,
        int $localPort = 9001
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->localPort = $localPort;
    }

    /**
     * {@inheritDoc}
     *
     * Creates the datagram socket, sets SO_REUSEADDR, binds to a local port
     * above 1024 (the preferred port, sliding forward across a small window if
     * it is already claimed), and applies 10s send/receive timeouts.
     */
    public function open(): void
    {
        // socket_create() returns Socket|false on PHP 8; the failure is handled
        // through a local so `false` is never assigned to the ?Socket property
        // (the old @var resource|null fiction is what the phpstan ignores hid).
        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new \RuntimeException('Failed to create UDP socket: ' . socket_strerror(socket_last_error()));
        }
        $this->socket = $socket;

        // Reuse local port to avoid triggering AniDB's multi-port ban detection
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        // Bind to a local port above 1024 (AniDB requires one stable source
        // port per session; per-packet ports trip its multi-port ban
        // detection). If the preferred port is taken, slide forward across a
        // small window rather than fail — the rule is "a stable port", not
        // "that exact port" (review M-ANIDB-33).
        //
        // Why the 0.0.0.0 wildcard is acceptable (review anidb__secaudit m4,
        // recorded there as hygiene with the compensation verified): the
        // wildcard announces this UDP port on every interface, so on a
        // multi-homed box external hosts can send datagrams at it — but they
        // can never be ACCEPTED as replies. Every read captures its origin
        // only after a successful <=1400-byte socket_recvfrom (send(), below),
        // and UdpClient::udpSend() drops any response that fails
        // isValidReplyOrigin() (not a resolved IP of the configured API host
        // and port). Pinning a route-specific source address instead would
        // require a connect-probe dance against api.anidb.net at open() time —
        // the exposure is a listening port, never attacker-controlled input.
        $bound = false;
        $lastError = '';
        for ($attempt = 0; $attempt < self::BIND_PORT_WINDOW; $attempt++) {
            $candidate = $this->localPort + $attempt;
            if (@socket_bind($this->socket, '0.0.0.0', $candidate)) {
                $this->boundPort = $candidate;
                $bound = true;
                break;
            }
            $lastError = socket_strerror(socket_last_error($this->socket));
        }

        if (!$bound) {
            $windowEnd = $this->localPort + self::BIND_PORT_WINDOW - 1;
            $err = $lastError;
            $this->close();
            throw new \RuntimeException(
                "Failed to bind UDP socket to any port in window {$this->localPort}-{$windowEnd}: {$err}"
            );
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 10, 'usec' => 0]);
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 10, 'usec' => 0]);
    }

    /**
     * {@inheritDoc}
     *
     * Waits for data availability with `socket_select()` (10 s budget) before
     * the single `socket_recvfrom`, replacing the bare blocking read. Honest
     * budget: a silent AniDB parks this call — and with it the worker task
     * that made it — for the full ~10 s; only the syscall the process sits in
     * differs from the old blocking read. (Full async UDP I/O is the open
     * M-ANIDB-16 architecture question, not a transport detail.)
     */
    public function send(string $data): ?string
    {
        if ($this->socket === null) {
            throw new \RuntimeException('UDP socket not open');
        }

        $bytesSent = @socket_sendto(
            $this->socket,
            $data,
            strlen($data),
            0,
            $this->host,
            $this->port
        );

        if ($bytesSent === false) {
            return null;
        }

        // One shared 10 s wall-clock budget bounds the WHOLE exchange (a
        // bounded, readable wait — still an occupation, see header prose law).
        // The loop exists for AniDB's framing frames (review anidb__srphp M3):
        // the documented protocol can put a 001 command-accepted ACK before
        // the result and leave a 005 RELEASE (or a 002 continuation fragment)
        // in the receive buffer. Those are protocol overhead, never a reply:
        // reading exactly one datagram — as this transport used to — returned
        // an ACK as the AUTH result and poisoned the NEXT command with the
        // previous one's trailing frame. Skipping frames inside one bounded
        // loop fixes both shapes without a pre-send drain: any stale framing
        // frame is consumed by the same loop that then waits for the result.
        $deadline = microtime(true) + self::RESPONSE_BUDGET_SECONDS;

        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                // Budget exhausted — no result frame arrived (or only overhead
                // did). Honest null; callers already handle timeout-or-error.
                return null;
            }

            $read = [$this->socket];
            $write = null;
            $except = null;

            $selected = @socket_select(
                $read,
                $write,
                $except,
                (int) $remaining,
                (int) (($remaining - (int) $remaining) * 1_000_000),
            );

            if ($selected === false || $selected === 0) {
                return null;
            }

            $recvBuf = '';
            $recvFrom = '';
            $port = 0;

            $recvResult = @socket_recvfrom($this->socket, $recvBuf, self::MAX_DATAGRAM_BYTES, 0, $recvFrom, $port);

            if ($recvResult === false) {
                return null;
            }

            $frame = trim($recvBuf);

            if (self::isFramingFrame($frame)) {
                // Protocol overhead (001 ACK / 002 fragment / 005 RELEASE):
                // consumed so it can never be mistaken for a reply — the
                // desync poison the review named. NOTE (disclosed residual):
                // MULTI-PACKET RESULT REASSEMBLY across 002 fragments is NOT
                // implemented — fragments are skipped, so a split reply ends
                // as a bounded timeout failure rather than a half-read string.
                // Correct reassembly needs a credentialed live capture of the
                // fragment wire shape (the reviewer's own uncertainty gate);
                // every response shape this plugin exercises (230/231/240/250
                // for its amask and command set) fits one <=1400-byte packet,
                // and single-packet behavior is byte-identical to before.
                continue;
            }

            // Capture the reply origin; UdpClient::isValidReplyOrigin() validates it.
            $this->lastReplyHost = $recvFrom !== '' ? $recvFrom : null;
            $this->lastReplyPort = $port !== 0 ? $port : null;

            return $frame;
        }
    }

    /**
     * Is this datagram AniDB protocol framing overhead rather than a result?
     *
     * Per the UDP API definition the server's overhead frames are "001"
     * (command accepted / multi-packet header), "002" (continuation fragment)
     * and "005" (release). Every genuine reply starts with a THREE-digit
     * result code (200-599) — never "001"/"002"/"005" — so a strict
     * `00[125]` + whitespace match classifies overhead without ever eating a
     * result (lookalikes like "0011" or "001NO-SPACE" are results-side data
     * and must NOT be skipped).
     *
     * @param string $frame Trimmed datagram payload.
     *
     * @return bool True when the frame is protocol overhead.
     */
    public static function isFramingFrame(string $frame): bool
    {
        return (bool) preg_match('/^00[125]\s/', $frame);
    }

    /**
     * {@inheritDoc}
     */
    public function close(): void
    {
        if ($this->socket !== null) {
            @socket_close($this->socket);
            $this->socket = null;
        }
        $this->boundPort = null;
    }

    /**
     * The local port actually bound by the last successful open(), or null.
     */
    public function boundPort(): ?int
    {
        return $this->boundPort;
    }

    /**
     * {@inheritDoc}
     */
    public function lastReplyHost(): ?string
    {
        return $this->lastReplyHost;
    }

    /**
     * {@inheritDoc}
     */
    public function lastReplyPort(): ?int
    {
        return $this->lastReplyPort;
    }
}
