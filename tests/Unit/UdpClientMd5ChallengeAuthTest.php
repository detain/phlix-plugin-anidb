<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use Phlix\Anidb\Udp\WaiterInterface;
use PHPUnit\Framework\TestCase;

/**
 * ANI-S21 seam tests: md5 challenge leg and the pass= fallback leg of AUTH.
 */
final class UdpClientMd5ChallengeAuthTest extends TestCase
{
    /**
     * @param list<string|null> $responses
     * @return array{UdpClient, object}
     */
    private function make(array $responses): array
    {
        $t = new class ($responses) implements UdpClientInterface {
            /** @var list<string> */
            public array $sent = [];

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
        };
        $w = new class implements WaiterInterface {
            public function wait(float $seconds): void
            {
            }
        };

        return [new UdpClient(['username' => 'user', 'api_key' => 'secret'], $t, $w), $t];
    }

    public function test_challenge_is_answered_with_md5_and_password_never_sent(): void
    {
        [$client, $t] = $this->make(['201 CHAL123 CHALLENGE', '200 SESS LOGIN ACCEPTED']);
        $client->authenticate();

        $this->assertCount(2, $t->sent);
        $this->assertStringContainsString('auth=' . md5('CHAL123secret'), $t->sent[1]);
        $this->assertStringNotContainsString('pass=', $t->sent[1]);
        $this->assertStringNotContainsString('secret', $t->sent[1]);
    }

    public function test_no_challenge_falls_back_to_single_pass_datagram(): void
    {
        [$client, $t] = $this->make(['201 KEY LOGIN ACCEPTED - NEW VERSION AVAILABLE']);
        $client->authenticate();

        $this->assertCount(1, $t->sent);
        $this->assertStringContainsString('pass=secret', $t->sent[0]);
    }

    public function test_challenge_answer_timeout_throws(): void
    {
        [$client] = $this->make(['201 CHAL CHALLENGE', null]);
        $this->expectException(\RuntimeException::class);
        $client->authenticate();
    }
}
