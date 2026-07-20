<?php

namespace Tests\Feature;

use App\Enums\DomainLifecycleState;
use App\Models\Domain;
use App\Models\UsageRollup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelemetryBrowserExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_usage_exports_use_session_auth_and_preserve_scope(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create(['name' => 'analytics.example.test', 'display_name' => 'Analytics', 'lifecycle_state' => DomainLifecycleState::Active, 'revision' => 1]);
        $domain->users()->attach($user, ['created_at' => now()]);
        UsageRollup::query()->create([
            'domain_id' => $domain->id,
            'interval_start' => CarbonImmutable::parse('2026-07-20T08:00:00Z'),
            'interval_end' => CarbonImmutable::parse('2026-07-20T09:00:00Z'),
            'requests' => 7,
            'bytes_in' => 70,
            'bytes_out' => 700,
            'cache_hits' => 5,
            'dns_queries' => 11,
            'status' => 'finalized',
        ]);

        $domainUrl = route('app.analytics.usage.csv', $domain, false);
        $adminUrl = route('admin.telemetry.usage.csv', [], false);
        $this->get($domainUrl)->assertRedirect('/');
        $this->actingAs($stranger)->get($domainUrl)->assertForbidden();
        $domainResponse = $this->actingAs($user)->get($domainUrl)->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString("usage-domain-{$domain->id}.csv", $domainResponse->headers->get('content-disposition'));
        $this->assertStringContainsString(',7,70,700,5,11,finalized', $domainResponse->streamedContent());

        $this->actingAs($user)->get($adminUrl)->assertForbidden();
        $adminCsv = $this->actingAs($admin)->get($adminUrl)->assertOk()->streamedContent();
        $this->assertStringContainsString('contract_version,domain_id,interval_start', $adminCsv);
        $this->assertStringContainsString(',7,70,700,5,11,finalized', $adminCsv);
    }
}
