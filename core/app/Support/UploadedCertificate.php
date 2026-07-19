<?php

namespace App\Support;

use App\Models\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class UploadedCertificate
{
    public static function validate(Domain $domain, string $certificatePem, string $chainPem, string $privateKeyPem): array
    {
        self::bounded($certificatePem, $chainPem, $privateKeyPem);
        $leaf = openssl_x509_read($certificatePem);
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($leaf === false || $privateKey === false) {
            throw ValidationException::withMessages(['certificate' => 'The certificate and private key must be parseable PEM values.']);
        }
        $leafPublic = openssl_pkey_get_public($certificatePem);
        $leafDetails = $leafPublic === false ? false : openssl_pkey_get_details($leafPublic);
        $privateDetails = openssl_pkey_get_details($privateKey);
        if ($leafDetails === false || $privateDetails === false || ! hash_equals($leafDetails['key'], $privateDetails['key'])) {
            throw ValidationException::withMessages(['private_key' => 'The private key does not match the certificate public key.']);
        }
        self::validateAlgorithm($privateDetails);
        $parsed = openssl_x509_parse($leaf, false);
        if (! is_array($parsed) || ! isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            throw ValidationException::withMessages(['certificate' => 'The certificate validity window is missing.']);
        }
        $notBefore = CarbonImmutable::createFromTimestamp($parsed['validFrom_time_t']);
        $expiresAt = CarbonImmutable::createFromTimestamp($parsed['validTo_time_t']);
        if ($notBefore->isAfter(now()->addMinutes(5)) || ! $expiresAt->isFuture()) {
            throw ValidationException::withMessages(['certificate' => 'The certificate is not currently valid.']);
        }
        $names = self::names($parsed);
        $required = $domain->dnsRecords()->where('mode', 'proxied')->orderBy('name')->pluck('name')->map(fn (string $name): string => strtolower($name))->unique()->values()->all();
        if ($required === []) {
            throw ValidationException::withMessages(['certificate' => 'A custom certificate requires at least one proxied hostname.']);
        }
        foreach ($required as $hostname) {
            if (! collect($names)->contains(fn (string $name): bool => self::covers($name, $hostname))) {
                throw ValidationException::withMessages(['certificate' => "The certificate does not cover {$hostname}."]);
            }
        }
        self::validateChain($leaf, $chainPem);
        openssl_x509_export($leaf, $normalizedLeaf);

        return [
            'certificate_pem' => $normalizedLeaf, 'chain_pem' => trim($chainPem)."\n", 'private_key' => $privateKeyPem,
            'names' => $names, 'not_before' => $notBefore, 'expires_at' => $expiresAt,
            'fingerprint_sha256' => strtolower(openssl_x509_fingerprint($leaf, 'sha256', false)),
        ];
    }

    private static function bounded(string $certificate, string $chain, string $privateKey): void
    {
        if (strlen($certificate) > 16384 || strlen($privateKey) > 16384 || strlen($chain) > 65536) {
            throw ValidationException::withMessages(['certificate' => 'The certificate bundle exceeds the allowed size.']);
        }
    }

    private static function validateAlgorithm(array $details): void
    {
        $valid = ($details['type'] === OPENSSL_KEYTYPE_RSA && ($details['bits'] ?? 0) >= 2048 && ($details['bits'] ?? 0) <= 4096)
            || ($details['type'] === OPENSSL_KEYTYPE_EC && in_array($details['ec']['curve_name'] ?? '', ['prime256v1', 'secp384r1'], true));
        if (! $valid) {
            throw ValidationException::withMessages(['private_key' => 'Use RSA 2048–4096 or EC P-256/P-384.']);
        }
    }

    private static function names(array $parsed): array
    {
        $san = (string) ($parsed['extensions']['subjectAltName'] ?? '');
        $names = collect(explode(',', $san))->map(fn (string $entry): string => trim($entry))
            ->filter(fn (string $entry): bool => str_starts_with($entry, 'DNS:'))
            ->map(fn (string $entry): string => strtolower(rtrim(substr($entry, 4), '.')))
            ->filter(fn (string $name): bool => $name !== '' && strlen($name) <= 253)->unique()->values()->all();
        if ($names === []) {
            throw ValidationException::withMessages(['certificate' => 'The certificate must contain DNS subject alternative names.']);
        }

        return $names;
    }

    private static function covers(string $pattern, string $hostname): bool
    {
        if ($pattern === $hostname) {
            return true;
        }
        if (! str_starts_with($pattern, '*.')) {
            return false;
        }
        $suffix = substr($pattern, 2);

        return str_ends_with($hostname, '.'.$suffix) && substr_count($hostname, '.') === substr_count($suffix, '.') + 1;
    }

    private static function validateChain(\OpenSSLCertificate $leaf, string $chainPem): void
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $chainPem, $matches);
        if ($matches[0] === [] || count($matches[0]) > 10) {
            throw ValidationException::withMessages(['chain' => 'Provide a bounded PEM chain containing the issuing certificate and root.']);
        }
        $current = $leaf;
        foreach ($matches[0] as $pem) {
            $issuer = openssl_x509_read($pem);
            $public = $issuer === false ? false : openssl_pkey_get_public($issuer);
            if ($issuer === false || $public === false || openssl_x509_verify($current, $public) !== 1) {
                throw ValidationException::withMessages(['chain' => 'The certificate chain signature order is invalid.']);
            }
            $current = $issuer;
        }
        $rootPublic = openssl_pkey_get_public($current);
        if ($rootPublic === false || openssl_x509_verify($current, $rootPublic) !== 1) {
            throw ValidationException::withMessages(['chain' => 'The certificate chain must terminate in a self-signed root.']);
        }
    }
}
