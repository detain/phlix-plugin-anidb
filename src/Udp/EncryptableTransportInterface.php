<?php

/**
 * Optional transport capability: AniDB ENCRYPT (type 1) packet encryption.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * A {@see UdpClientInterface} transport that can encrypt every outgoing
 * datagram and decrypt every reply with the AniDB ENCRYPT (type 1) key.
 *
 * Encryption has to live in the transport, below the reply-framing loop,
 * because 001/002/005 framing frames and result codes are only visible after
 * decryption. Transports without this capability (test fakes) simply stay on
 * the cleartext path.
 *
 * @package Phlix\Anidb\Udp
 */
interface EncryptableTransportInterface
{
    /**
     * Install (or, with null, clear) the raw 16-byte AES-128 session key.
     *
     * While a key is set every datagram sent is encrypted and every datagram
     * received is decrypted (undecryptable datagrams are skipped).
     */
    public function setCipherKey(?string $rawKey): void;

    /**
     * Whether a cipher key is currently installed.
     */
    public function hasCipherKey(): bool;
}
