<?php

/**
 * AniDB UDP API ENCRYPT (type 1) cipher helpers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * AES-128-ECB / PKCS#7 packet cipher of the AniDB UDP API "ENCRYPT type=1".
 *
 * Wire-verified (docs/captures/anidb-udp-wire-2026-11-13.md): after
 * `ENCRYPT user=<name>&type=1` the server answers `209 <salt> ENCRYPTION
 * ENABLED`; the session key is the RAW md5 of the user's API key concatenated
 * with the salt; an encrypted `PING` datagram was answered by an encrypted
 * `300 PONG` that decrypts under the same key with standard PKCS#7 padding.
 *
 * @package Phlix\Anidb\Udp
 */
final class AnidbCipher
{
    private const METHOD = 'aes-128-ecb';

    /**
     * Derive the raw 16-byte session key: md5(apiKey . salt).
     */
    public static function deriveKey(string $apiKey, string $salt): string
    {
        return md5($apiKey . $salt, true);
    }

    /**
     * Encrypt one datagram payload.
     */
    public static function encrypt(string $plain, string $rawKey): string
    {
        $out = openssl_encrypt($plain, self::METHOD, $rawKey, OPENSSL_RAW_DATA);

        if ($out === false) {
            throw new \RuntimeException('AniDB cipher: encryption failed');
        }

        return $out;
    }

    /**
     * Decrypt one datagram payload; null when it is not a valid ciphertext.
     */
    public static function decrypt(string $cipher, string $rawKey): ?string
    {
        if ($cipher === '' || strlen($cipher) % 16 !== 0) {
            return null;
        }

        $out = @openssl_decrypt($cipher, self::METHOD, $rawKey, OPENSSL_RAW_DATA);

        return $out === false ? null : $out;
    }
}
