<?php

namespace App\Support;

use RuntimeException;

final class ArtifactSigner
{
    public static function sign(string $checksum): string
    {
        return sodium_bin2hex(sodium_crypto_sign_detached($checksum, self::secretKey()));
    }

    public const SNAPSHOT_SCOPE = 'snapshot';

    public const ORIGIN_POLICY_SCOPE = 'origin-policy';

    public const CONTROL_ADDRESSES_SCOPE = 'control-addresses';

    /** What a delivery is for. The agent rebuilds this from the manifest entry it is applying. */
    public static function artifactScope(string $kind, int $domainId, int $revision): string
    {
        return "artifact:{$kind}:{$domainId}:{$revision}";
    }

    /**
     * Sign one delivery: its scope (kind, domain, revision, or the snapshot), the payload checksum,
     * the sequence, and the expiry, so none of them can be changed in transit.
     */
    public static function signDelivery(string $scope, string $checksum, int $sequence, int $expiresAt): string
    {
        return self::sign("{$scope}|{$checksum}|{$sequence}|{$expiresAt}");
    }

    public static function publicKey(): string
    {
        return sodium_bin2hex(sodium_crypto_sign_publickey(self::keyPair()));
    }

    public static function encode(array $payload): string
    {
        return json_encode(self::sort($payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * During a rotation, the next signing key and an endorsement of it signed by the current key.
     * An agent that trusts the current key learns the next one from this before it is used, which
     * is the overlap window; null outside a rotation.
     *
     * @return array{public_key: string, endorsement: string}|null
     */
    public static function rotation(): ?array
    {
        $next = (string) config('edge.artifact_signing_next_key');
        if ($next === '') {
            return null;
        }
        $public = sodium_bin2hex(sodium_crypto_sign_publickey(self::keyPair($next)));

        return ['public_key' => $public, 'endorsement' => self::sign(self::ROTATION_PREFIX.$public)];
    }

    public const ROTATION_PREFIX = 'rotate|';

    private static function keyPair(?string $seed = null): string
    {
        $configured = $seed ?? (string) config('edge.artifact_signing_key');
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
