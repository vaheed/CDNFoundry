<?php

namespace Tests\Feature;

use App\Support\ArtifactSigner;
use Tests\TestCase;

class SigningKeyRotationTest extends TestCase
{
    public function test_no_rotation_is_advertised_outside_a_rotation(): void
    {
        config(['edge.artifact_signing_next_key' => null]);

        $this->assertNull(ArtifactSigner::rotation());
    }

    public function test_the_next_key_is_endorsed_by_the_current_key_and_never_by_itself(): void
    {
        config(['edge.artifact_signing_key' => 'current-seed', 'edge.artifact_signing_next_key' => 'next-seed']);
        $rotation = ArtifactSigner::rotation();
        $current = hex2bin(ArtifactSigner::publicKey());

        $this->assertNotSame(ArtifactSigner::publicKey(), $rotation['public_key']);
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            hex2bin($rotation['endorsement']), 'rotate|'.$rotation['public_key'], $current,
        ), 'an agent that trusts the current key must be able to verify the endorsement');
        $this->assertFalse(sodium_crypto_sign_verify_detached(
            hex2bin($rotation['endorsement']), 'rotate|'.$rotation['public_key'], hex2bin($rotation['public_key']),
        ), 'the next key must not have endorsed itself');
    }

    public function test_after_the_swap_the_new_key_signs_and_its_public_key_is_the_advertised_one(): void
    {
        config(['edge.artifact_signing_key' => 'current-seed', 'edge.artifact_signing_next_key' => 'next-seed']);
        $advertised = ArtifactSigner::rotation()['public_key'];

        config(['edge.artifact_signing_key' => 'next-seed', 'edge.artifact_signing_next_key' => null]);

        $this->assertSame($advertised, ArtifactSigner::publicKey());
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            hex2bin(ArtifactSigner::sign('payload')), 'payload', hex2bin($advertised),
        ));
    }
}
