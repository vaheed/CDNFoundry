<?php

namespace App\Support;

/** The network an address belongs to for sign-in comparison: its /24 for IPv4 and /48 for IPv6. */
final class ClientNetwork
{
    public static function key(?string $address): ?string
    {
        $packed = $address === null ? false : @inet_pton($address);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 4) {
            return bin2hex(substr($packed, 0, 3)).'/24';
        }

        return bin2hex(substr($packed, 0, 6)).'/48';
    }
}
