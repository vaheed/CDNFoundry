<?php

namespace Tests\Feature;

use App\Models\ChallengeKey;
use App\Models\Edge;
use App\Models\User;
use App\Support\ChallengeKeys;
use App\Support\EdgeCertificateAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChallengeKeysTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_use_creates_one_active_key_and_the_secret_is_encrypted_at_rest(): void
    {
        $active = ChallengeKeys::ensureActive();

        $this->assertSame('active', $active->state);
        $this->assertSame(64, strlen($active->secret));
        $this->assertNotSame($active->secret, DB::table('challenge_keys')->value('secret_ciphertext'));
        $this->assertSame($active->id, ChallengeKeys::ensureActive()->id, 'a second call creates nothing');
    }

    public function test_a_rotation_keeps_the_old_key_verifying_for_the_overlap_and_signs_with_the_new_one(): void
    {
        $first = ChallengeKeys::ensureActive();
        $before = ChallengeKeys::delivery()['version'];
        $second = ChallengeKeys::rotate();

        $keys = collect(ChallengeKeys::delivery()['keys'])->keyBy('id');
        $this->assertTrue($keys[$second->key_id]['signing']);
        $this->assertNull($keys[$second->key_id]['not_after']);
        $this->assertFalse($keys[$first->key_id]['signing']);
        $this->assertEqualsWithDelta(now()->addSeconds(ChallengeKeys::OVERLAP_SECONDS)->timestamp, $keys[$first->key_id]['not_after'], 5);
        $this->assertGreaterThan($before, ChallengeKeys::delivery()['version']);
        $this->assertCount(2, $keys);
    }

    public function test_a_third_key_retires_the_oldest_so_only_two_are_ever_valid(): void
    {
        $first = ChallengeKeys::ensureActive();
        ChallengeKeys::rotate();
        ChallengeKeys::rotate();

        $this->assertCount(2, ChallengeKeys::delivery()['keys']);
        $this->assertSame('retired', $first->fresh()->state);
        $this->assertSame('superseded', $first->fresh()->retired_reason);
    }

    public function test_revoking_the_active_key_replaces_it_first_and_revoking_a_previous_key_removes_it_at_once(): void
    {
        $first = ChallengeKeys::ensureActive();
        ChallengeKeys::revoke($first->key_id);
        $delivery = ChallengeKeys::delivery();

        $this->assertCount(1, $delivery['keys'], 'the revoked key is gone and a new one signs');
        $this->assertTrue($delivery['keys'][0]['signing']);
        $this->assertNotSame($first->key_id, $delivery['keys'][0]['id']);
        $this->assertSame('revoked', $first->fresh()->retired_reason);

        $second = ChallengeKey::query()->where('state', 'active')->first();
        $third = ChallengeKeys::rotate();
        ChallengeKeys::revoke($second->key_id);
        $this->assertSame([$third->key_id], array_column(ChallengeKeys::delivery()['keys'], 'id'));
    }

    public function test_maintenance_rotates_an_old_key_retires_an_expired_overlap_and_prunes_old_retired_keys(): void
    {
        $old = ChallengeKeys::ensureActive();
        $old->update(['activated_at' => now()->subDays(ChallengeKeys::ROTATE_AFTER_DAYS + 1)]);

        $this->assertTrue(ChallengeKeys::maintain()['rotated']);
        $this->assertSame('previous', $old->fresh()->state);

        Carbon::setTestNow(now()->addSeconds(ChallengeKeys::OVERLAP_SECONDS + 1));
        $this->assertSame(1, ChallengeKeys::maintain()['retired']);
        $this->assertSame('expired', $old->fresh()->retired_reason);
        $this->assertCount(1, ChallengeKeys::delivery()['keys']);

        Carbon::setTestNow(now()->addDays(ChallengeKeys::RETIRED_KEPT_DAYS + 1));
        $this->assertSame(1, ChallengeKeys::maintain()['pruned']);
        Carbon::setTestNow();
    }

    public function test_an_edge_receives_the_keys_sealed_to_it_and_never_in_the_clear(): void
    {
        $this->getJson('/edge/v1/challenge-keys')->assertUnauthorized();
        $headers = $this->enrolledHeaders();
        $key = ChallengeKeys::ensureActive();

        $response = $this->withHeaders($headers)->getJson('/edge/v1/challenge-keys')->assertOk();

        $this->assertSame(ChallengeKeys::delivery()['version'], $response->json('data.version'));
        $this->assertSame('challenge-keys', $response->json('data.keys.certificate_id'));
        $this->assertStringNotContainsString($key->secret, $response->getContent());
    }

    public function test_only_an_administrator_manages_keys_and_no_response_carries_a_secret(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/admin/challenge-keys')->assertForbidden();

        $this->actingAs(User::factory()->admin()->create());
        $list = $this->getJson('/api/admin/challenge-keys')->assertOk()->assertJsonCount(1, 'data');
        $created = $this->postJson('/api/admin/challenge-keys/rotate', [], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame('active', $created->json('data.state'));
        $previous = $list->json('data.0.id');
        $this->postJson("/api/admin/challenge-keys/{$previous}/revoke", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.retired_reason', 'revoked');

        $secrets = ChallengeKey::query()->get()->map(fn (ChallengeKey $key): string => $key->secret)->all();
        foreach ([$list->getContent(), $created->getContent()] as $body) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
        $this->assertDatabaseHas('audit_logs', ['action' => 'challenge_key.rotated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'challenge_key.revoked']);
    }

    private function enrolledHeaders(): array
    {
        $edge = Edge::query()->create(['name' => 'key-edge', 'country_code' => 'DE', 'continent_code' => 'EU', 'enabled' => true, 'registered_at' => now()]);
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_csr_export(openssl_csr_new(['commonName' => $edge->id], $key, ['digest_alg' => 'sha256']), $csr);
        $signed = EdgeCertificateAuthority::sign($csr, $edge->id);
        $edge->update(['identity_certificate' => $signed['certificate'], 'identity_certificate_serial' => $signed['serial'], 'identity_certificate_expires_at' => $signed['expires_at']]);

        return ['X-Edge-Proxy-Secret' => config('edge.proxy_secret'), 'X-Edge-Certificate-Verify' => 'SUCCESS', 'X-Edge-Certificate-Serial' => $signed['serial'], 'X-Edge-Certificate-Pem' => rawurlencode($signed['certificate'])];
    }
}
