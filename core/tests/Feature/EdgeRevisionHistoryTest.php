<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgeRevision;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EdgeRevisionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_revision_describes_which_parts_of_the_configuration_changed(): void
    {
        $base = ['settings' => ['a' => 1], 'security' => ['limits' => ['requests_per_second' => 100]], 'waf' => ['profile' => 'off'], 'hostnames' => [['hostname' => 'a.test']]];

        $this->assertSame('First configuration', EdgeRevision::describeChange(null, $base));
        $this->assertSame('No configuration change', EdgeRevision::describeChange($base, [...$base, 'revision' => 9]));
        $this->assertSame('Changed security', EdgeRevision::describeChange($base, [...$base, 'security' => ['limits' => ['requests_per_second' => 20]]]));
        $this->assertSame(
            'Changed hostnames and origins, proxy settings, WAF',
            EdgeRevision::describeChange($base, [...$base, 'hostnames' => [], 'settings' => ['a' => 2], 'waf' => ['profile' => 'strict']]),
        );
    }

    public function test_revision_change_time_tracks_revision_mutations_only(): void
    {
        $domain = Domain::query()->create([
            'name' => 'revision-time.example.test',
            'display_name' => 'Revision time',
            'revision' => 1,
        ]);
        $initial = $domain->revision_changed_at;
        $this->assertNotNull($initial);

        $this->travelTo(now()->addMinute()->startOfSecond());
        $domain->update(['display_name' => 'Cosmetic change']);
        $this->assertTrue($domain->refresh()->revision_changed_at->equalTo($initial));

        $changedAt = now();
        $domain->update(['revision' => 2]);
        $this->assertTrue($domain->refresh()->revision_changed_at->equalTo($changedAt));
        $this->travelBack();
    }

    public function test_revision_listing_exposes_an_explicit_field_list_and_never_key_material(): void
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'revision-leak.example.test', 'display_name' => 'Leak', 'revision' => 1]);
        $domain->users()->attach($user);
        $key = "-----BEGIN PRIVATE KEY-----\nSECRETKEYMATERIAL\n-----END PRIVATE KEY-----";
        EdgeRevision::query()->create([
            'domain_id' => $domain->id, 'revision' => 1, 'checksum' => str_repeat('a', 64), 'status' => 'validated',
            'snapshot' => ['hostnames' => [['name' => 'www']], 'pools' => ['shared-default'], 'tls' => ['mode' => 'custom', 'certificate' => ['private_key_pem' => $key]]],
        ]);

        $response = $this->actingAs($user)->getJson("/api/domains/{$domain->id}/revisions")->assertOk()
            ->assertJsonPath('data.0.revision', 1)->assertJsonPath('data.0.summary.hostnames', 1)
            ->assertJsonPath('data.0.summary.tls_mode', 'custom');
        $this->assertSame(['revision', 'status', 'checksum', 'summary', 'change_summary', 'created_by', 'created_at'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('SECRETKEYMATERIAL', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent());
    }

    public function test_expired_history_is_bounded_without_reusing_numbers_or_removing_active_recent_or_rollback_revisions(): void
    {
        $settings = SystemSetting::query()->findOrFail('revision_history');
        $settings->update(['values' => ['retention_days' => 1, 'minimum_revisions_per_domain' => 10]]);
        $domain = Domain::query()->create([
            'name' => 'revision-history.example.test',
            'display_name' => 'Revision history',
            'revision' => 20,
            'active_edge_revision' => 8,
        ]);
        $edge = Edge::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'revision-history-edge',
            'country_code' => 'IR',
            'continent_code' => 'AS',
        ]);
        foreach (range(1, 20) as $revision) {
            $createdAt = $revision === 3 ? now()->subHours(12) : now()->subDays(2);
            EdgeRevision::query()->create([
                'domain_id' => $domain->id,
                'revision' => $revision,
                'snapshot' => ['revision' => $revision],
                'checksum' => hash('sha256', "revision-{$revision}"),
                'status' => 'validated',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            EdgeArtifact::query()->create([
                'edge_id' => $edge->id,
                'domain_id' => $domain->id,
                'revision' => $revision,
                'kind' => 'domain',
                'payload' => ['revision' => $revision],
                'checksum' => hash('sha256', "artifact-{$revision}"),
                'signature' => str_repeat('a', 64),
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $this->artisan('cdnf:edge:prune-revisions')->expectsOutput('Pruned 8 edge revision(s) and 8 derived artifact(s).')->assertSuccessful();

        $this->assertSame(20, $domain->refresh()->revision);
        $this->assertSame([3, 8, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20], EdgeRevision::query()->orderBy('revision')->pluck('revision')->all());
        $this->assertSame([3, 8, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20], EdgeArtifact::query()->orderBy('revision')->pluck('revision')->all());
    }
}
