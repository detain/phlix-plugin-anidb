<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\AnidbCipher;
use Phlix\Anidb\Udp\EncryptableTransportInterface;
use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
use PHPUnit\Framework\TestCase;

/**
 * ENCRYPT-before-AUTH handshake (ANI-S19/S21). Reply shapes follow the live
 * capture in docs/captures/anidb-udp-wire-2026-11-13.md (placeholders only).
 */
final class UdpClientEncryptHandshakeTest extends TestCase
{
    /**
     * @param list<string|null> $responses
     * @return array{UdpClient, object}
     */
    private function make(array $responses): array
    {
        $t = new class ($responses) implements UdpClientInterface, EncryptableTransportInterface {
            /** @var list<string> */
            public array $sent = [];
            public ?string $key = null;

            /** @param list<string|null> $responses */
            public function __construct(public array $responses)
            {
            }

            public function open(): void
            {
            }

            public function send(string $data): ?string
            {
                $this->sent[] = $data;
                return array_shift($this->responses);
            }

            public function close(): void
            {
            }

            public function lastReplyHost(): ?string
            {
                return 'api.anidb.net';
            }

            public function lastReplyPort(): ?int
            {
                return 9000;
            }

            public function setCipherKey(?string $rawKey): void
            {
                $this->key = $rawKey;
            }

            public function hasCipherKey(): bool
            {
                return $this->key !== null;
            }
        };
        $w = new class implements WaiterInterface {
            public function wait(float $seconds): void
            {
            }
        };

        return [new UdpClient(['username' => 'user', 'api_key' => 'secret'], $t, $w, ['api.anidb.net']), $t];
    }

    public function test_encrypt_precedes_auth_and_installs_derived_key(): void
    {
        [$client, $t] = $this->make(['209 SaLt1234 ENCRYPTION ENABLED', '200 SESS LOGIN ACCEPTED']);
        $client->authenticate();

        $this->assertSame('ENCRYPT user=user&type=1', $t->sent[0]);
        $this->assertStringStartsWith('AUTH user=user&pass=secret', $t->sent[1]);
        $this->assertSame(AnidbCipher::deriveKey('secret', 'SaLt1234'), $t->key);
        $this->assertTrue($client->isAuthenticated());
    }

    public function test_reauth_renegotiates_from_a_cleared_key(): void
    {
        [$client, $t] = $this->make([
            '209 AAAAAAAA ENCRYPTION ENABLED', '200 S1 LOGIN ACCEPTED',
            '209 BBBBBBBB ENCRYPTION ENABLED', '200 S2 LOGIN ACCEPTED',
        ]);
        $client->authenticate();
        $client->authenticate();

        $this->assertSame(AnidbCipher::deriveKey('secret', 'BBBBBBBB'), $t->key);
    }

    public function test_refused_encrypt_falls_back_to_cleartext_auth(): void
    {
        [$client, $t] = $this->make(['509 NO SUCH ENCRYPTION TYPE', '200 SESS LOGIN ACCEPTED']);
        $prev = ini_set('error_log', '/dev/null');
        try {
            $client->authenticate();
        } finally {
            ini_set('error_log', (string) $prev);
        }

        $this->assertNull($t->key);
        $this->assertStringContainsString('pass=secret', $t->sent[1]);
    }

    public function test_banned_encrypt_reply_throws_without_auth(): void
    {
        [$client, $t] = $this->make(['555 BANNED']);
        try {
            $client->authenticate();
            $this->fail('expected ban to throw');
        } catch (\RuntimeException $e) {
            $this->assertCount(1, $t->sent);
        }
    }

    public function test_encrypt_timeout_throws(): void
    {
        [$client] = $this->make([null]);
        $this->expectException(\RuntimeException::class);
        $client->authenticate();
    }
}
