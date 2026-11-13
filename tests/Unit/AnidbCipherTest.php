<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\AnidbCipher;
use Phlix\Anidb\Udp\SocketUdpClient;
use PHPUnit\Framework\TestCase;

/**
 * ENCRYPT type 1 cipher (ANI-S19). Wire shape verified live 2026-11-13; the
 * key, salt and user here are placeholders, never the real credentials.
 */
final class AnidbCipherTest extends TestCase
{
    public function test_key_is_raw_md5_of_api_key_then_salt(): void
    {
        $key = AnidbCipher::deriveKey('PLACEHOLDER_KEY', 'SaLt1234');

        $this->assertSame(16, strlen($key));
        $this->assertSame(md5('PLACEHOLDER_KEYSaLt1234', true), $key);
    }

    public function test_round_trip_and_block_alignment(): void
    {
        $key = AnidbCipher::deriveKey('PLACEHOLDER_KEY', 'SaLt1234');
        $ct = AnidbCipher::encrypt('PING', $key);

        $this->assertSame(16, strlen($ct)); // PKCS#7 pads a 4-byte PING to one block
        $this->assertSame('PING', AnidbCipher::decrypt($ct, $key));
    }

    public function test_decrypt_rejects_garbage(): void
    {
        $key = AnidbCipher::deriveKey('PLACEHOLDER_KEY', 'SaLt1234');

        $this->assertNull(AnidbCipher::decrypt('', $key));
        $this->assertNull(AnidbCipher::decrypt('300 PONG', $key)); // not block-aligned
    }

    public function test_socket_transport_encrypts_out_and_decrypts_replies(): void
    {
        $server = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_bind($server, '127.0.0.1', 0);
        socket_getsockname($server, $addr, $serverPort);

        $client = new SocketUdpClient('127.0.0.1', $serverPort, 39100);
        $client->open();
        $key = AnidbCipher::deriveKey('PLACEHOLDER_KEY', 'SaLt1234');
        $client->setCipherKey($key);
        $this->assertTrue($client->hasCipherKey());

        // Pre-queue the encrypted reply (a garbage datagram first, which must be skipped).
        $port = $client->boundPort();
        $this->assertNotNull($port);
        socket_sendto($server, 'junk', 4, 0, '127.0.0.1', $port);
        $reply = AnidbCipher::encrypt("300 PONG\n", $key);
        socket_sendto($server, $reply, strlen($reply), 0, '127.0.0.1', $port);

        $this->assertSame('300 PONG', $client->send('PING'));

        $buf = '';
        socket_recvfrom($server, $buf, 1400, 0, $from, $fromPort);
        $this->assertNotSame('PING', $buf);
        $this->assertSame('PING', AnidbCipher::decrypt($buf, $key));

        $client->close();
        $this->assertFalse($client->hasCipherKey());
        socket_close($server);
    }

    public function test_expected_codes_cover_ping_and_encrypt(): void
    {
        $this->assertSame([300], SocketUdpClient::expectedResultCodes('PING'));
        $this->assertSame([209], SocketUdpClient::expectedResultCodes('ENCRYPT user=u&type=1'));
    }
}
