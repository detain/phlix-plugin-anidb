<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * Transport seam for the AniDB UDP API.
 *
 * Abstracts the raw socket create/bind/sendto/recvfrom/close lifecycle behind a
 * small interface so {@see \Phlix\Anidb\AnidbMetadataProvider} can be exercised
 * with a fake transport returning canned responses (AUTH / command parse / retry
 * logic) without touching the network.
 *
 * The default production implementation is {@see SocketUdpClient}, which wraps the
 * blocking `socket_*` lifecycle that previously lived inline on the provider. The
 * extraction was BEHAVIOR-PRESERVING; the later steps that consume this seam have
 * since shipped: the async waiter (S1b, {@see WaiterInterface}), the 506 re-auth
 * retry, and origin validation all live in {@see UdpClient}, which drives this
 * interface as its transport.
 *
 * ## Origin accessors
 *
 * {@see lastReplyHost()} / {@see lastReplyPort()} expose the source host/port of
 * the most recent datagram received by {@see send()}. {@see UdpClient} consumes
 * them to validate each reply against the expected AniDB endpoint (see
 * UdpClient::isValidReplyOrigin()); transports themselves only capture, they do
 * not validate. Before any reply has been received they return `null`.
 *
 * @package Phlix\Anidb\Udp
 * @since 0.3.0
 */
interface UdpClientInterface
{
    /**
     * Open (create + bind + configure) the UDP socket.
     *
     * Idempotency and re-open semantics match the provider's original inline
     * socket-open behavior from before this seam was extracted.
     *
     * @return void
     *
     * @throws \RuntimeException If socket creation or binding fails.
     */
    public function open(): void;

    /**
     * Send a datagram and return the trimmed result response, or null on timeout/error.
     *
     * Records the origin host/port of the reply for later retrieval via
     * {@see lastReplyHost()} / {@see lastReplyPort()}. A conforming transport
     * skips protocol framing frames (AniDB 001/002/005 overhead) within a
     * single bounded wait and returns the first result-code frame — see
     * {@see SocketUdpClient::send()} (review anidb__srphp M3).
     *
     * @throws \RuntimeException If the socket is not open.
     */
    public function send(string $data): ?string;

    /**
     * Close the UDP socket if open. Safe to call when already closed.
     *
     * @return void
     */
    public function close(): void;

    /**
     * Source host of the most recently received reply, or null if none yet.
     *
     * Captured for origin validation; the consuming {@see UdpClient} compares
     * it against the expected AniDB endpoint. Transports do not validate here.
     *
     * @return string|null
     */
    public function lastReplyHost(): ?string;

    /**
     * Source port of the most recently received reply, or null if none yet.
     *
     * Captured for origin validation; the consuming {@see UdpClient} compares
     * it against the expected AniDB endpoint. Transports do not validate here.
     *
     * @return int|null
     */
    public function lastReplyPort(): ?int;
}
