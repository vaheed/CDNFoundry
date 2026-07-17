<?php

namespace App\Support;

use RuntimeException;

final class ArtifactSigner
{
    public static function sign(string $checksum): string
    {
        return sodium_bin2hex(sodium_crypto_sign_detached($checksum, self::secretKey()));
    }

    public static function publicKey(): string
    {
        return sodium_bin2hex(sodium_crypto_sign_publickey(self::keyPair()));
    }

    public static function encode(array $payload): string
    {
        return json_encode(self::sort($payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function keyPair(): string
    {
        $configured = (string) config('edge.artifact_signing_key');
        if ($configured === '') {
            throw new RuntimeException('EDGE_ARTIFACT_SIGNING_KEY or APP_KEY must be configured.');
        }
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            $configured = $decoded === false ? $configured : $decoded;
        }

        return sodium_crypto_sign_seed_keypair(hash('sha256', $configured, true));
    }

    private static function secretKey(): string
    {
        return sodium_crypto_sign_secretkey(self::keyPair());
    }

    private static function sort(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn ($item) => is_array($item) ? self::sort($item) : $item, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::sort($item);
            }
        }

        return $value;
    }
}
