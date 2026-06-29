<?php

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
 * extraction is BEHAVIOR-PRESERVING — no flood-protection, async, or origin-
 * validation behavior is introduced here. Those land in later steps (S1b async,
 * B2 506 re-auth retry, S2/S3 origin validation) which consume this seam.
 *
 * ## Origin accessors
 *
 * {@see lastReplyHost()} / {@see lastReplyPort()} expose the source host/port of
 * the most recent datagram received by {@see send()}. They are captured but NOT
 * yet validated — the S2/S3 origin-validation step will compare them against the
 * expected AniDB endpoint. Before any reply has been received they return `null`.
 *
 * @package Phlix\Anidb\Udp
 * @since 0.3.0
 */
interface UdpClientInterface
{
    /**
     * Open (create + bind + configure) the UDP socket.
     *
     * Idempotency and re-open semantics match the previous inline
     * {@see \Phlix\Anidb\AnidbMetadataProvider::openSocket()} behavior.
     *
     * @return void
     *
     * @throws \RuntimeException If socket creation or binding fails.
     */
    public function open(): void;

    /**
     * Send a datagram and return the trimmed response, or null on timeout/error.
     *
     * Records the origin host/port of the reply for later retrieval via
     * {@see lastReplyHost()} / {@see lastReplyPort()}.
     *
     * @param string $data Raw command string to transmit.
     *
     * @return string|null Trimmed response payload, or null when no reply arrived.
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
     * Captured for the later origin-validation step (S2/S3); not validated here.
     *
     * @return string|null
     */
    public function lastReplyHost(): ?string;

    /**
     * Source port of the most recently received reply, or null if none yet.
     *
     * Captured for the later origin-validation step (S2/S3); not validated here.
     *
     * @return int|null
     */
    public function lastReplyPort(): ?int;
}
