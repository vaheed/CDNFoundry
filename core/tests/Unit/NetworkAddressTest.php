<?php

namespace Tests\Unit;

use App\Support\NetworkAddress;
use PHPUnit\Framework\TestCase;

class NetworkAddressTest extends TestCase
{
    public function test_destination_safety_distinguishes_allowlistable_private_space_from_hard_blocks(): void
    {
        $this->assertTrue(NetworkAddress::isUnsafe('10.20.30.40'));
        $this->assertTrue(NetworkAddress::isPrivate('10.20.30.40'));
        $this->assertTrue(NetworkAddress::inCidr('10.20.30.40', '10.0.0.0/8'));

        foreach (['127.0.0.1', '169.254.169.254', '::1', '::ffff:127.0.0.1', '64:ff9b::7f00:1'] as $address) {
            $this->assertTrue(NetworkAddress::isUnsafe($address), "$address must be blocked");
            $this->assertFalse(NetworkAddress::isPrivate($address), "$address must never be private-allowlist eligible");
        }

        $this->assertFalse(NetworkAddress::isUnsafe('8.8.8.8'));
        $this->assertFalse(NetworkAddress::isUnsafe('2606:4700:4700::1111'));
    }
}
