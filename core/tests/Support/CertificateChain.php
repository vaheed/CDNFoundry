<?php

namespace Tests\Support;

final class CertificateChain
{
    public static function make(string $constraint): array
    {
        $issue = function (string $name, ?\OpenSSLCertificate $issuer, ?\OpenSSLAsymmetricKey $issuerKey, int $days, string $extensions) use ($constraint): array {
            $weak = ($constraint === 'weak_issuer_key' && $name === 'Synthetic Test Issuer')
                || ($constraint === 'weak_root_key' && $name === 'Synthetic Test Root');
            $digest = (($constraint === 'weak_leaf_signature' && $name === 'www.example.test')
                || ($constraint === 'weak_issuer_signature' && $name === 'Synthetic Test Issuer')) ? 'sha1' : 'sha256';
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $weak ? 1024 : 2048]);
            $configuration = tempnam(sys_get_temp_dir(), 'cdnf-chain-fixture-');
            try {
                file_put_contents($configuration, "[ req ]\ndistinguished_name = dn\nprompt = no\n[ dn ]\nCN = {$name}\n[ extensions ]\n{$extensions}\n");
                $request = openssl_csr_new(['commonName' => $name], $key, ['digest_alg' => 'sha256', 'config' => $configuration]);
                $certificate = openssl_csr_sign($request, $issuer, $issuerKey ?? $key, $days,
                    ['digest_alg' => $digest, 'config' => $configuration, 'x509_extensions' => 'extensions'], random_int(1, 1000000));
                if ($certificate === false) {
                    throw new \RuntimeException('Synthetic certificate signing failed.');
                }
                openssl_x509_export($certificate, $pem);

                return [$certificate, $key, $pem];
            } finally {
                unlink($configuration);
            }
        };
        $identifiers = "subjectKeyIdentifier = hash\nauthorityKeyIdentifier = keyid:always";
        [$root, $rootKey, $rootPem] = $issue('Synthetic Test Root', null, null, $constraint === 'expired_root' ? 0 : 30,
            'basicConstraints = critical,CA:true,pathlen:'.($constraint === 'path_length' ? '0' : '2')."\nkeyUsage = critical,keyCertSign,cRLSign\n{$identifiers}");
        $issuerCa = $constraint === 'issuer_not_ca' ? 'CA:false' : 'CA:true,pathlen:0';
        $issuerUsage = in_array($constraint, ['issuer_not_ca', 'issuer_key_usage'], true) ? 'digitalSignature' : 'keyCertSign,cRLSign';
        $constraints = $constraint === 'name_constraint' ? "\nnameConstraints = critical,permitted;DNS:allowed.test" : '';
        [$issuer, $issuerKey, $issuerPem] = $issue('Synthetic Test Issuer', $root, $rootKey, $constraint === 'expired_issuer' ? 0 : 20,
            "basicConstraints = critical,{$issuerCa}\nkeyUsage = critical,{$issuerUsage}\n{$identifiers}{$constraints}");
        $purpose = $constraint === 'server_purpose' ? 'clientAuth' : 'serverAuth';
        $unknown = $constraint === 'critical_extension' ? "\n1.2.3.4 = critical,ASN1:UTF8String:synthetic-test" : '';
        [, $key, $leafPem] = $issue('www.example.test', $issuer, $issuerKey, 10,
            "basicConstraints = critical,CA:false\nkeyUsage = critical,digitalSignature,keyEncipherment\nextendedKeyUsage = {$purpose}\nsubjectAltName = DNS:www.example.test\n{$identifiers}{$unknown}");
        if (in_array($constraint, ['expired_root', 'expired_issuer'], true)) {
            sleep(1); // A zero-day issuer must be expired before the real OpenSSL check.
        }
        openssl_pkey_export($key, $keyPem);

        return ['certificate' => $leafPem, 'chain' => $issuerPem.$rootPem, 'private_key' => $keyPem];
    }
}
