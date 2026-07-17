<?php

namespace App\Support;

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
        $serial = random_int(1, PHP_INT_MAX);
        $certificate = openssl_csr_sign($csrPem, $certificateAuthority, $privateKey, 365, [
            'digest_alg' => 'sha256',
        ], $serial);
        if ($certificate === false || ! openssl_x509_export($certificate, $pem)) {
            throw new RuntimeException('Unable to sign the edge identity certificate.');
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
