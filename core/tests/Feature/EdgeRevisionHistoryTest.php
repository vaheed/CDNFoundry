<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgeRevision;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EdgeRevisionHistoryTest extends TestCase
{
    use RefreshDatabase;

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
            'ipv4' => '203.0.113.40',
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

        $this->artisan('edge:prune-revisions')->expectsOutput('Pruned 8 edge revision(s) and 8 derived artifact(s).')->assertSuccessful();

        $this->assertSame(20, $domain->refresh()->revision);
        $this->assertSame([3, 8, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20], EdgeRevision::query()->orderBy('revision')->pluck('revision')->all());
        $this->assertSame([3, 8, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20], EdgeArtifact::query()->orderBy('revision')->pluck('revision')->all());
    }
}
