<?php

namespace App\Support;

use RuntimeException;

/**
 * Seals a certificate private key to one edge's identity key (ECDH P-256,
 * HKDF-SHA256, AES-256-GCM) so the key is protected beyond the mTLS channel.
 */
final class EdgeKeyEnvelope
{
    public static function seal(string $privateKeyPem, string $edgeCertificatePem, string $edgeId, string $certificateId): array
    {
        $peer = openssl_pkey_get_public($edgeCertificatePem);
        $ephemeral = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = $ephemeral === false ? false : openssl_pkey_get_details($ephemeral);
        if ($peer === false || $details === false || ! isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Unable to prepare the edge key envelope.');
        }
        $shared = openssl_pkey_derive($peer, $ephemeral);
        if ($shared === false) {
            throw new RuntimeException('The edge identity key cannot receive certificate keys.');
        }
        $context = self::context($edgeId, $certificateId);
        $key = hash_hkdf('sha256', $shared, 32, $context);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($privateKeyPem, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to seal the certificate key.');
        }
        $point = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        return [
            'certificate_id' => $certificateId, 'algorithm' => 'ecdh-p256-hkdf-sha256-aes256gcm',
            'ephemeral_public_key' => base64_encode($point), 'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext.$tag),
        ];
    }

    public static function context(string $edgeId, string $certificateId): string
    {
        return "cdnfoundry-edge-key-v1|{$edgeId}|{$certificateId}";
    }
}
