<?php

namespace App\Support;

final class NetworkAddress
{
    private const UNSAFE_NETWORKS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    private const PRIVATE_NETWORKS = ['10.0.0.0/8', '100.64.0.0/10', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'];

    public static function isUnsafe(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (str_starts_with(strtolower($address), '::ffff:')) {
            return true;
        }

        return collect(self::UNSAFE_NETWORKS)->contains(fn (string $network): bool => self::inCidr($address, $network));
    }

    public static function isPrivate(string $address): bool
    {
        return collect(self::PRIVATE_NETWORKS)->contains(fn (string $network): bool => self::inCidr($address, $network));
    }

    public static function inCidr(string $address, string $cidr): bool
    {
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ip = @inet_pton($address);
        $net = @inet_pton($network);
        if ($ip === false || $net === false || strlen($ip) !== strlen($net)) {
            return false;
        }
        $bits = $bits === null ? strlen($ip) * 8 : (int) $bits;
        if ($bits < 0 || $bits > strlen($ip) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if (substr($ip, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }

        return $remainder === 0 || ((ord($ip[$bytes]) & (0xFF << (8 - $remainder))) === (ord($net[$bytes]) & (0xFF << (8 - $remainder))));
    }
}
