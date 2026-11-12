<?php

/**
 * Pins that the transport dial and reply-origin validation share one endpoint.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\SocketUdpClient;
use Phlix\Anidb\Udp\UdpClient;
use Phlix\Anidb\Udp\UdpClientInterface;
use PHPUnit\Framework\TestCase;

final class AnidbApiEndpointSingleSourceTest extends TestCase
{
    public function testDefaultDialEqualsOriginValidationEndpoint(): void
    {
        $ctor = (new \ReflectionClass(SocketUdpClient::class))->getConstructor();
        self::assertNotNull($ctor);
        $params = $ctor->getParameters();
        self::assertSame(UdpClient::API_HOST, $params[0]->getDefaultValue());
        self::assertSame(UdpClient::API_PORT, $params[1]->getDefaultValue());

        $client = new SocketUdpClient();
        $ref = new \ReflectionClass($client);
        self::assertSame(UdpClient::API_HOST, $ref->getProperty('host')->getValue($client));
        self::assertSame(UdpClient::API_PORT, $ref->getProperty('port')->getValue($client));
    }

    public function testValidationAcceptsExactlyTheDialedPort(): void
    {
        $transport = $this->createStub(UdpClientInterface::class);
        $udp = new UdpClient([], $transport, null, [UdpClient::API_HOST]);
        $m = new \ReflectionMethod($udp, 'isValidReplyOrigin');
        self::assertTrue($m->invoke($udp, UdpClient::API_HOST, UdpClient::API_PORT));
        self::assertFalse($m->invoke($udp, UdpClient::API_HOST, UdpClient::API_PORT + 1));
    }
}
