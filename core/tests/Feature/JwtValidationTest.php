<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\User;
use App\Support\JwtValidationData;
use App\Support\SecurityConfig;
use App\Support\SecurityRuleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class JwtValidationTest extends TestCase
{
    use RefreshDatabase;

    private function owned(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'jwt.example', 'display_name' => 'jwt.example', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user->id);

        return [$user, $domain];
    }

    private function publicKey(string $type = 'rsa', int $bits = 2048, string $curve = 'prime256v1'): string
    {
        $options = $type === 'rsa' ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits] : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve];
        $key = openssl_pkey_new($options);

        return openssl_pkey_get_details($key)['key'];
    }

    private function privateKey(): string
    {
        openssl_pkey_export(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]), $pem);

        return $pem;
    }

    private function settings(array $overrides = []): array
    {
        return [...[
            'paths' => ['/api', '/v2'], 'issuer' => 'https://issuer.test', 'audience' => 'orders',
            'keys' => [['kid' => 'rsa-1', 'alg' => 'RS256', 'pem' => $this->publicKey()], ['kid' => 'ec-1', 'alg' => 'ES256', 'pem' => $this->publicKey('ec'), 'expires_at' => '2030-01-01T00:00:00Z']],
        ], ...$overrides];
    }

    public function test_settings_are_validated_stored_signed_and_sent_to_the_cell_with_key_ends_as_unix_times(): void
    {
        Queue::fake();
        [$user, $domain] = $this->owned();
        $url = "/api/domains/{$domain->id}/security/jwt-validation";

        $this->actingAs($user)->putJson($url, $this->settings(['paths' => ['/v2', '/api', '/api']]))->assertAccepted();
        $domain->refresh();
        $this->assertSame(2, $domain->revision);
        $this->assertSame(['/api', '/v2'], $domain->jwt_validation['paths']);
        $this->assertSame(JwtValidationData::DEFAULT_LEEWAY, $domain->jwt_validation['leeway_seconds']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.jwt_validation_updated', 'subject_id' => (string) $domain->id]);
        Queue::assertPushed(ReconcileEdgeDomain::class);

        $compiled = SecurityConfig::compile($domain)['jwt_validation'];
        $this->assertSame(1893456000, $compiled['keys'][1]['expires_at']);
        $this->assertNull($compiled['keys'][0]['expires_at']);
        $this->assertSame('https://issuer.test', $compiled['issuer']);

        $shown = $this->actingAs($user)->getJson($url)->assertOk()->json('data.jwt_validation');
        $this->assertArrayNotHasKey('pem', $shown['keys'][0], 'the key text is not echoed, only its fingerprint');
        $this->assertSame(64, strlen($shown['keys'][0]['fingerprint']));

        $this->actingAs($user)->deleteJson($url)->assertAccepted();
        $this->assertNull(SecurityConfig::compile($domain->refresh())['jwt_validation']);
    }

    public function test_unsafe_or_malformed_keys_and_settings_are_refused_and_the_previous_settings_stay(): void
    {
        Queue::fake();
        [$user, $domain] = $this->owned();
        $url = "/api/domains/{$domain->id}/security/jwt-validation";
        $this->actingAs($user)->putJson($url, $this->settings())->assertAccepted();
        $good = $domain->refresh()->jwt_validation;

        $cases = [
            'keys.0.alg' => $this->settings(['keys' => [['alg' => 'HS256', 'pem' => $this->publicKey()]]]),
            'keys.0.pem' => $this->settings(['keys' => [['alg' => 'RS256', 'pem' => $this->privateKey()]]]),
            'a weak key' => $this->settings(['keys' => [['alg' => 'RS256', 'pem' => $this->publicKey('rsa', 1024)]]]),
            'wrong curve' => $this->settings(['keys' => [['alg' => 'ES256', 'pem' => $this->publicKey('ec', 0, 'secp384r1')]]]),
            'algorithm and key mismatch' => $this->settings(['keys' => [['alg' => 'ES256', 'pem' => $this->publicKey()]]]),
            'keys.1.kid' => $this->settings(['keys' => [['kid' => 'a', 'alg' => 'RS256', 'pem' => $this->publicKey()], ['kid' => 'a', 'alg' => 'RS256', 'pem' => $this->publicKey()]]]),
            'no keys' => $this->settings(['keys' => []]),
            'too many keys' => $this->settings(['keys' => array_fill(0, JwtValidationData::MAXIMUM_KEYS + 1, ['alg' => 'RS256', 'pem' => $this->publicKey()])]),
            'paths' => $this->settings(['paths' => ['api']]),
            'no paths' => $this->settings(['paths' => []]),
            'leeway_seconds' => $this->settings(['leeway_seconds' => 9999]),
            'issuer' => $this->settings(['issuer' => str_repeat('a', 300)]),
            'keys.0.expires_at' => $this->settings(['keys' => [['alg' => 'RS256', 'pem' => $this->publicKey(), 'expires_at' => 'next tuesday-ish?']]]),
        ];
        foreach ($cases as $label => $payload) {
            $this->actingAs($user)->putJson($url, $payload)->assertUnprocessable();
        }
        $this->assertSame($good, $domain->refresh()->jwt_validation);
        $this->assertSame(2, $domain->revision);
    }

    public function test_a_stranger_cannot_read_or_change_the_settings_and_the_field_and_key_are_rule_inputs(): void
    {
        [, $domain] = $this->owned();
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson("/api/domains/{$domain->id}/security/jwt-validation")->assertNotFound();
        $this->actingAs($stranger)->putJson("/api/domains/{$domain->id}/security/jwt-validation", $this->settings())->assertNotFound();
        $this->assertArrayHasKey('jwt_valid', SecurityRuleData::FIELDS);
        $this->assertArrayHasKey('jwt_subject', SecurityRuleData::RATE_KEYS);
        $this->assertContains('jwt_invalid', SecurityConfig::reasonCodes());
    }

    public function test_a_rule_on_the_field_needs_the_setting_and_the_setting_cannot_be_removed_while_a_rule_uses_it(): void
    {
        Queue::fake();
        [$user, $domain] = $this->owned();
        $rule = ['name' => 'invalid tokens', 'action' => 'block', 'priority' => 1, 'conditions' => [['field' => 'jwt_valid', 'operator' => 'equals', 'value' => false]]];
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $rule)->assertUnprocessable();
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/security/jwt-validation", $this->settings())->assertAccepted();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $rule)->assertCreated();
        $this->actingAs($user)->deleteJson("/api/domains/{$domain->id}/security/jwt-validation")->assertStatus(409);
    }
}
