<?php

namespace App\Support;

final class CompressionPolicy
{
    public const PROFILES = [
        'off' => [
            'gzip' => false, 'brotli' => false, 'minimum_bytes' => 1024,
            'maximum_bytes' => 0, 'maximum_active_requests' => 0,
        ],
        'standard' => [
            'gzip' => true, 'brotli' => false, 'minimum_bytes' => 1024,
            'maximum_bytes' => 10485760, 'maximum_active_requests' => 32,
        ],
        'maximum_savings' => [
            'gzip' => true, 'brotli' => true, 'minimum_bytes' => 1024,
            'maximum_bytes' => 10485760, 'maximum_active_requests' => 16,
        ],
    ];

    public static function profile(string $name): array
    {
        return self::PROFILES[$name] ?? self::PROFILES['standard'];
    }

    public static function allowedForKind(string $profile, string $kind): bool
    {
        return $profile !== 'maximum_savings' || in_array($kind, ['reserved', 'dedicated'], true);
    }
}
