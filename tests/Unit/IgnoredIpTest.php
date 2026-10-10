<?php

namespace Tests\Unit;

use Gogol\VpsManagerCLI\Helpers\Fail2ban;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IgnoredIpTest extends TestCase
{
    private const IGNORED = ['127.0.0.1/8', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7', '89.221.214.62', '2a02:2b88:2:33e::1'];

    public static function addresses(): array
    {
        return [
            'router behind proxy' => ['192.168.1.1', true],
            'vpn' => ['10.8.0.5', true],
            'end of 172.16/12' => ['172.31.255.255', true],
            'after 172.16/12' => ['172.32.0.1', false],
            'localhost' => ['127.0.0.5', true],
            'own address' => ['89.221.214.62', true],
            'neighbour of own address' => ['89.221.214.63', false],
            'own ipv6' => ['2a02:2b88:2:33e::1', true],
            'neighbour of own ipv6' => ['2a02:2b88:2:33e::2', false],
            'unique local ipv6' => ['fd00::1', true],
            'scanner' => ['20.92.77.159', false],
            'scanner ipv6' => ['2a01:4f8::1', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_own_addresses_and_private_networks_are_never_banned(string $ip, bool $ignored): void
    {
        $this->assertSame($ignored, (new Fail2ban)->isIgnoredIp($ip, self::IGNORED));
    }
}
