<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A domain's JWT validation: the path prefixes it covers and the public keys, issuer and audience a token must match. Only public
 * keys are accepted (RS256 and ES256); there is no shared-secret algorithm and the cell never fetches a key. Each key may end
 * at a time the cell enforces by its own clock, so a retired key stops working even while the control plane is unreachable.
 * Nothing here blocks by itself: the cell sets the `jwt_valid` rule field and a rule decides.
 */
final class JwtValidationData
{
    public const MAXIMUM_KEYS = 8;

    public const MAXIMUM_PATHS = 8;

    public const MAXIMUM_PEM_BYTES = 4096;

    public const ALGORITHMS = ['RS256' => 'RSA with SHA-256 (2048 bits or more)', 'ES256' => 'ECDSA P-256 with SHA-256'];

    public const DEFAULT_LEEWAY = 30;

    public const MAXIMUM_LEEWAY = 300;

    /**
     * @param  array<string, mixed>  $input
     * @return array{paths: list<string>, issuer: string|null, audience: string|null, leeway_seconds: int, keys: list<array{kid: string|null, alg: string, pem: string, expires_at: string|null}>}
     */
    public static function validate(array $input): array
    {
        $paths = $input['paths'] ?? null;
        if (! is_array($paths) || ! array_is_list($paths) || $paths === [] || count($paths) > self::MAXIMUM_PATHS) {
            throw self::refuse('paths', 'List between 1 and '.self::MAXIMUM_PATHS.' path prefixes.');
        }
        $clean = [];
        foreach ($paths as $path) {
            if (! is_string($path) || ! preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]{0,199}$#', $path) || str_contains($path, '//')) {
                throw self::refuse('paths', 'Each path prefix must start with / and hold no query string or spaces.');
            }
            $clean[$path] = $path;
        }
        ksort($clean, SORT_STRING);

        $keys = $input['keys'] ?? null;
        if (! is_array($keys) || ! array_is_list($keys) || $keys === [] || count($keys) > self::MAXIMUM_KEYS) {
            throw self::refuse('keys', 'Upload between 1 and '.self::MAXIMUM_KEYS.' public keys.');
        }
        $compiled = [];
        $seen = [];
        foreach ($keys as $index => $key) {
            $compiled[] = self::key($index, is_array($key) ? $key : [], $seen);
        }

        foreach (['issuer', 'audience'] as $claim) {
            $value = $input[$claim] ?? null;
            if ($value !== null && $value !== '' && (! is_string($value) || strlen($value) > 253 || preg_match('/[\x00-\x1f\x7f]/', $value))) {
                throw self::refuse($claim, "The $claim must be text of at most 253 characters.");
            }
        }
        $leeway = $input['leeway_seconds'] ?? self::DEFAULT_LEEWAY;
        if (! is_numeric($leeway) || (int) $leeway != $leeway || (int) $leeway < 0 || (int) $leeway > self::MAXIMUM_LEEWAY) {
            throw self::refuse('leeway_seconds', 'The clock allowance must be between 0 and '.self::MAXIMUM_LEEWAY.' seconds.');
        }

        return [
            'paths' => array_values($clean), 'issuer' => ($input['issuer'] ?? '') === '' ? null : $input['issuer'],
            'audience' => ($input['audience'] ?? '') === '' ? null : $input['audience'], 'leeway_seconds' => (int) $leeway, 'keys' => $compiled,
        ];
    }

    /**
     * @param  array<string, mixed>  $key
     * @param  array<string, bool>  $seen
     * @return array{kid: string|null, alg: string, pem: string, expires_at: string|null}
     */
    private static function key(int $index, array $key, array &$seen): array
    {
        $where = 'keys.'.$index;
        $algorithm = $key['alg'] ?? null;
        if (! is_string($algorithm) || ! isset(self::ALGORITHMS[$algorithm])) {
            throw self::refuse("$where.alg", 'The algorithm must be RS256 or ES256. Shared-secret algorithms and "none" are not accepted.');
        }
        $pem = is_string($key['pem'] ?? null) ? trim(str_replace("\r\n", "\n", $key['pem'])) : '';
        if ($pem === '' || strlen($pem) > self::MAXIMUM_PEM_BYTES) {
            throw self::refuse("$where.pem", 'Paste a PEM public key of at most '.self::MAXIMUM_PEM_BYTES.' bytes.');
        }
        if (str_contains($pem, 'PRIVATE KEY') || ! str_starts_with($pem, '-----BEGIN PUBLIC KEY-----') || ! str_ends_with($pem, '-----END PUBLIC KEY-----')) {
            throw self::refuse("$where.pem", 'Upload a public key in PEM form ("BEGIN PUBLIC KEY"). A private key is never accepted.');
        }
        $resource = @openssl_pkey_get_public($pem);
        $details = $resource === false ? false : openssl_pkey_get_details($resource);
        if ($details === false) {
            throw self::refuse("$where.pem", 'The public key could not be read.');
        }
        if ($algorithm === 'RS256' && ($details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048 || $details['bits'] > 8192)) {
            throw self::refuse("$where.pem", 'RS256 needs an RSA key of 2048 to 8192 bits.');
        }
        if ($algorithm === 'ES256' && ($details['type'] !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? '') !== 'prime256v1')) {
            throw self::refuse("$where.pem", 'ES256 needs an EC key on the prime256v1 (P-256) curve.');
        }
        $kid = $key['kid'] ?? null;
        if ($kid !== null && $kid !== '' && (! is_string($kid) || ! preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $kid))) {
            throw self::refuse("$where.kid", 'A key ID uses letters, digits and . _ : - only, up to 64 characters.');
        }
        $kid = $kid === '' ? null : $kid;
        if ($kid !== null) {
            if (isset($seen[$kid])) {
                throw self::refuse("$where.kid", 'This key ID is used twice.');
            }
            $seen[$kid] = true;
        }
        $expires = $key['expires_at'] ?? null;
        if ($expires !== null && $expires !== '') {
            try {
                $expires = CarbonImmutable::parse((string) $expires)->utc()->toIso8601String();
            } catch (\Throwable) {
                throw self::refuse("$where.expires_at", 'The end time is not a valid date and time.');
            }
        } else {
            $expires = null;
        }

        return ['kid' => $kid, 'alg' => $algorithm, 'pem' => $pem."\n", 'expires_at' => $expires];
    }

    /** What the cell receives: the settings with each key's end as a Unix time. Keys already past their end are left out. */
    public static function forEdge(?array $stored): ?array
    {
        if (! is_array($stored) || ($stored['keys'] ?? []) === []) {
            return null;
        }
        $keys = [];
        foreach ($stored['keys'] as $key) {
            $end = $key['expires_at'] === null ? null : CarbonImmutable::parse($key['expires_at'])->timestamp;
            $keys[] = ['kid' => $key['kid'], 'alg' => $key['alg'], 'pem' => $key['pem'], 'expires_at' => $end];
        }

        return ['paths' => $stored['paths'], 'issuer' => $stored['issuer'], 'audience' => $stored['audience'], 'leeway_seconds' => $stored['leeway_seconds'], 'keys' => $keys];
    }

    private static function refuse(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
