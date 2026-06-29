<?php

declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * Default, production {@see UdpClientInterface} implementation.
 *
 * Wraps the blocking `socket_*` lifecycle (create / bind / sendto / recvfrom /
 * close) that previously lived inline on {@see \Phlix\Anidb\AnidbMetadataProvider}
 * as `openSocket()` / `udpSend()` / `closeSocket()`. The behavior is preserved
 * byte-for-byte from the original methods:
 *
 * - AF_INET / SOCK_DGRAM / SOL_UDP socket, SO_REUSEADDR set;
 * - bound to a fixed local port above 1024 to avoid AniDB's multi-port ban;
 * - 10s send/receive timeouts;
 * - {@see send()} sendto's the datagram then recvfrom's up to 1400 bytes,
 *   returning the trimmed payload (or null on a failed send/recv);
 * - {@see close()} closes the socket if open.
 *
 * NO flood protection, async, or origin validation is performed here — those are
 * provider/later-step concerns. {@see send()} additionally captures the reply's
 * source host/port for the future origin-validation step.
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
     * Local UDP socket resource.
     *
     * @var resource|null
     */
    private $socket = null;

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
        $this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($this->socket === false) {
            $this->socket = null;
            throw new \RuntimeException('Failed to create UDP socket: ' . socket_strerror(socket_last_error()));
        }

        // Reuse local port to avoid triggering AniDB's multi-port ban detection
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        // Bind to a fixed local port above 1024
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
     * Preserves the original udpSend() behavior: sendto the datagram then
     * recvfrom up to 1400 bytes, returning the trimmed payload or null on a
     * failed send/recv. Additionally records the reply's source host/port.
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

        $recvBuf = '';
        $recvFrom = '';
        $port = 0;

        $recvResult = @socket_recvfrom($this->socket, $recvBuf, 1400, 0, $recvFrom, $port);

        if ($recvResult === false) {
            return null;
        }

        // Capture the reply origin for the later origin-validation step (S2/S3).
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
