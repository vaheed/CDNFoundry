<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class EdgeIdentityConfigurationTest extends TestCase
{
    /** @return array{0: string, 1: string} */
    private function authority(bool $withKeyIdentifier): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $config = tempnam(sys_get_temp_dir(), 'ca');
        file_put_contents($config, "[req]\ndistinguished_name=dn\nx509_extensions=ca\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign\n"
            .($withKeyIdentifier ? "subjectKeyIdentifier=hash\n" : ''));
        $request = openssl_csr_new(['commonName' => 'Test Edge Identity CA'], $key, ['digest_alg' => 'sha256', 'config' => $config]);
        $certificate = openssl_csr_sign($request, null, $key, 2, ['digest_alg' => 'sha256', 'config' => $config, 'x509_extensions' => 'ca'], 1);
        unlink($config);
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        return [$certificatePem, $keyPem];
    }

    /** A CA certificate without a Subject Key Identifier must still sign edge identities. */
    public function test_edge_identities_are_signed_by_a_ca_with_and_without_a_key_identifier(): void
    {
        foreach ([true, false] as $withKeyIdentifier) {
            [$caPem, $caKeyPem] = $this->authority($withKeyIdentifier);
            $edgeKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $request = openssl_csr_new(['commonName' => 'edge-id'], $edgeKey, ['digest_alg' => 'sha256']);
            $certificate = openssl_csr_sign($request, $caPem, $caKeyPem, 2, [
                'digest_alg' => 'sha256',
                'config' => dirname(__DIR__, 2).'/resources/crypto/edge-identity.cnf',
                'x509_extensions' => 'edge_identity',
            ], 7);
            $this->assertNotFalse($certificate, 'signing failed for a CA '.($withKeyIdentifier ? 'with' : 'without').' a key identifier');
            $this->assertSame(1, openssl_x509_verify($certificate, openssl_pkey_get_public($caPem)));
        }
    }
}
