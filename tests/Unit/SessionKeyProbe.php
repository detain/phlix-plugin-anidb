<?php

declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit;

use Phlix\Anidb\Udp\UdpClient;

/**
 * Test-only access to UdpClient's private session key, so production code
 * does not need to expose the AniDB session credential through a public getter.
 */
trait SessionKeyProbe
{
    private function sessionKeyOf(UdpClient $client): ?string
    {
        $prop = new \ReflectionProperty(UdpClient::class, 'sessionKey');
        $value = $prop->getValue($client);

        return is_string($value) ? $value : null;
    }
}
