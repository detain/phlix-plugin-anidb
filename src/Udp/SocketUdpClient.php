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
 * Wraps the non-blocking `socket_*` lifecycle (create / bind / sendto / select +
 * recvfrom / close). Compared to the original inline implementation:
 *
 * - AF_INET / SOCK_DGRAM / SOL_UDP socket, SO_REUSEADDR set;
 * - bound to a fixed local port above 1024 to avoid AniDB's multi-port ban;
 * - 10s send/receive timeouts via `socket_select()` (non-blocking poll) so the
 *   calling worker is never parked on `socket_recvfrom`;
 * - {@see send()} sendto's the datagram then uses `socket_select()` to wait
 *   for data availability before reading up to 1400 bytes, returning the trimmed
 *   payload (or null on timeout);
 * - {@see close()} closes the socket if open.
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
     * AniDB UDP API server hostname (datagram destination).
     */
    private string $host;

    /**
     * AniDB UDP API server port (datagram destination).
     */
    private int $port;

    /**
     * Fixed local port to bind above 1024 (avoids AniDB multi-port ban).
     */
    private int $localPort;

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
     * @param int    $localPort Fixed local port to bind above 1024 (default 9001).
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
     * Preserves the original openSocket() behavior: create the datagram socket,
     * set SO_REUSEADDR, bind to a fixed local port above 1024, and apply 10s
     * send/receive timeouts.
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

        // Bind to a fixed local port above 1024 (AniDB requires one stable
        // source port; per-packet ports trip its multi-port ban detection).
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
        if (!@socket_bind($this->socket, '0.0.0.0', $this->localPort)) {
            $err = socket_strerror(socket_last_error($this->socket));
            $this->close();
            throw new \RuntimeException("Failed to bind UDP socket to port {$this->localPort}: {$err}");
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 10, 'usec' => 0]);
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, ['sec' => 10, 'usec' => 0]);
    }

    /**
     * {@inheritDoc}
     *
     * Uses non-blocking I/O via `socket_select()` to wait for data availability
     * before reading. This replaces the original blocking `socket_recvfrom` call
     * and ensures the calling worker is never parked for the full 10s timeout.
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

        // Use socket_select() for non-blocking wait on data availability.
        // This replaces the blocking socket_recvfrom that previously parked
        // the worker for up to 10 seconds.
        $read = [$this->socket];
        $write = null;
        $except = null;
        $timeoutSec = 10;

        $selected = @socket_select($read, $write, $except, $timeoutSec);

        if ($selected === false) {
            return null;
        }

        if ($selected === 0) {
            // Timeout — no data available within the 10s window
            return null;
        }

        $recvBuf = '';
        $recvFrom = '';
        $port = 0;

        $recvResult = @socket_recvfrom($this->socket, $recvBuf, 1400, 0, $recvFrom, $port);

        if ($recvResult === false) {
            return null;
        }

        // Capture the reply origin; UdpClient::isValidReplyOrigin() validates it.
        $this->lastReplyHost = $recvFrom !== '' ? $recvFrom : null;
        $this->lastReplyPort = $port !== 0 ? $port : null;

        return trim($recvBuf);
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
