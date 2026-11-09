<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use RuntimeException;

final class EdgeCertificateAuthority
{
    private static ?array $testingAuthority = null;

    public static function sign(string $csrPem, string $edgeId): array
    {
        $subject = openssl_csr_get_subject($csrPem, false);
        if ($subject === false || ($subject['commonName'] ?? null) !== $edgeId) {
            throw new RuntimeException('The edge CSR common name must match the edge ID.');
        }
        [$certificateAuthority, $privateKey] = self::authority();
        self::assertUsable($certificateAuthority, $privateKey);
        while (openssl_error_string() !== false) {
        }
        $serial = random_int(1, PHP_INT_MAX);
        $certificate = openssl_csr_sign($csrPem, $certificateAuthority, $privateKey, (int) config('edge.identity_validity_days'), [
            'digest_alg' => 'sha256',
            'config' => resource_path('crypto/edge-identity.cnf'),
            'x509_extensions' => 'edge_identity',
        ], $serial);
        if ($certificate === false || ! openssl_x509_export($certificate, $pem)) {
            $detail = self::opensslErrors();
            Log::error('Edge identity certificate signing failed.', ['edge_id' => $edgeId, 'openssl' => $detail]);

            throw new RuntimeException('Unable to sign the edge identity certificate'.($detail !== '' ? ': '.$detail : '; see the core log for the OpenSSL error').'.');
        }
        $parsed = openssl_x509_parse($certificate);
        if ($parsed === false) {
            throw new RuntimeException('Unable to inspect the edge identity certificate.');
        }

        return [
            'certificate' => $pem,
            'serial' => strtoupper($parsed['serialNumberHex']),
            'expires_at' => date(DATE_ATOM, $parsed['validTo_time_t']),
        ];
    }

    /** Name the exact CA problem (wrong passphrase, key not matching the certificate) instead of a bare signing failure. */
    private static function assertUsable(string $certificateAuthority, array|string $privateKey): void
    {
        $key = is_array($privateKey) ? openssl_pkey_get_private($privateKey[0], $privateKey[1]) : openssl_pkey_get_private($privateKey);
        if ($key === false) {
            Log::error('Edge identity CA private key cannot be read.', ['openssl' => self::opensslErrors()]);

            throw new RuntimeException('The edge identity CA private key cannot be read; check its format and EDGE_IDENTITY_CA_PRIVATE_KEY_PASSPHRASE.');
        }
        if (openssl_x509_read($certificateAuthority) === false) {
            throw new RuntimeException('The edge identity CA certificate is not a valid PEM certificate.');
        }
        if (! openssl_x509_check_private_key($certificateAuthority, $key)) {
            throw new RuntimeException('The edge identity CA private key does not match the edge identity CA certificate; restore the matching pair.');
        }
    }

    private static function opensslErrors(): string
    {
        $errors = [];
        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return implode(' | ', array_slice($errors, 0, 4));
    }

    private static function authority(): array
    {
        if (app()->environment('testing')) {
            return self::$testingAuthority ??= self::testingAuthority();
        }
        $certificate = @file_get_contents((string) config('edge.identity_ca_certificate'));
        $privateKey = @file_get_contents((string) config('edge.identity_ca_private_key'));
        if ($certificate === false || $privateKey === false) {
            throw new RuntimeException('The edge identity certificate authority is unavailable.');
        }

        return [$certificate, [$privateKey, (string) config('edge.identity_ca_private_key_passphrase')]];
    }

    private static function testingAuthority(): array
    {
        $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => 'CDNFoundry Test Edge CA'], $privateKey, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 2, ['digest_alg' => 'sha256'], 1);
        if ($certificate === false || ! openssl_x509_export($certificate, $certificatePem) || ! openssl_pkey_export($privateKey, $privateKeyPem)) {
            throw new RuntimeException('Unable to create the testing edge certificate authority.');
        }

        return [$certificatePem, $privateKeyPem];
    }
}
