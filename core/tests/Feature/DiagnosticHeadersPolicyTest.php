<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\EdgeRevision;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiagnosticHeadersPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function domain(string $name, ?array $proxy = null): Domain
    {
        $domain = Domain::query()->create(['name' => $name, 'display_name' => 'D', 'lifecycle_state' => 'active', 'revision' => 1, 'nameservers_verified_at' => now(), 'tls_mode' => 'disabled', 'proxy_settings' => $proxy]);
        $domain->dnsRecords()->create(['name' => $name, 'type' => 'A', 'content' => '8.8.8.8', 'content_hash' => hash('sha256', '8.8.8.8'), 'mode' => 'proxied', 'ttl' => 60,
            'origin' => ['host' => '8.8.8.8', 'port' => 80, 'scheme' => 'http', 'host_header' => $name, 'sni' => null, 'verify_tls' => false, 'connect_timeout_ms' => 1000, 'response_timeout_ms' => 5000, 'retry_count' => 0, 'websocket' => false, 'health_check' => null]]);

        return $domain;
    }

    /** @return array<string, mixed> the settings the cells receive for the domain */
    private function snapshotSettings(Domain $domain): array
    {
        // A platform change reaches every domain as a new revision, so the test bumps it the way the global reconcile does.
        $domain->increment('revision');
        (new ReconcileEdgeDomain($domain->id))->handle();

        return EdgeRevision::query()->where('domain_id', $domain->id)->latest('revision')->firstOrFail()->snapshot['settings'];
    }

    private function platform(string $group, array $values): void
    {
        $row = SystemSetting::query()->findOrFail($group);
        $row->update(['values' => [...$row->values, ...$values]]);
        app()->forgetInstance(PlatformSettings::class);
    }

    private function proxy(array $over = []): array
    {
        return ['enabled' => true, 'redirect_https' => false, 'http_versions' => ['1.1', '2'], 'retry_count' => 0, 'maintenance' => null, ...$over];
    }

    public function test_the_effective_decision_follows_force_off_then_the_domain_then_the_platform_default(): void
    {
        Queue::fake();
        $inherit = $this->domain('inherit.example');
        $on = $this->domain('on.example', $this->proxy(['diagnostics' => 'enabled']));
        $off = $this->domain('off.example', $this->proxy(['diagnostics' => 'disabled']));

        foreach ([[$inherit, false], [$on, true], [$off, false]] as [$domain, $expected]) {
            $settings = $this->snapshotSettings($domain);
            $this->assertSame($expected, $settings['public_headers']['diagnostics'], $domain->name);
            $this->assertSame($expected, $settings['diagnostic_headers'], 'a cell without public headers follows the same decision');
        }
        $this->assertSame('inherit', $this->snapshotSettings($inherit)['diagnostics'], 'a new domain inherits');

        $this->platform('proxy_defaults', ['diagnostic_response_headers' => true]);
        $this->assertTrue($this->snapshotSettings($inherit)['public_headers']['diagnostics'], 'an existing platform value that is on is kept as the default');
        $this->assertFalse($this->snapshotSettings($off)['public_headers']['diagnostics']);

        $this->platform('public_headers', ['diagnostics_force_off' => true]);
        foreach ([$inherit, $on, $off] as $domain) {
            $this->assertFalse($this->snapshotSettings($domain)['public_headers']['diagnostics'], 'force off overrides every domain');
        }
    }

    public function test_the_domain_choice_is_validated_authorized_kept_when_omitted_and_restored_by_rollback(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $domain = $this->domain('policy.example', $this->proxy());
        $domain->users()->attach($owner->id);
        $other = User::factory()->create();

        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson("/api/domains/{$domain->id}/proxy", $this->proxy(['diagnostics' => 'loud']))->assertUnprocessable();
        $this->actingAs($other)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson("/api/domains/{$domain->id}/proxy", $this->proxy(['diagnostics' => 'enabled']))->assertNotFound();

        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson("/api/domains/{$domain->id}/proxy", $this->proxy(['diagnostics' => 'enabled']))->assertStatus(202);
        $this->assertSame('enabled', $domain->refresh()->proxy_settings['diagnostics']);
        $this->assertTrue($this->snapshotSettings($domain)['public_headers']['diagnostics']);
        $enabledRevision = EdgeRevision::query()->where('domain_id', $domain->id)->latest('revision')->firstOrFail();
        $enabledRevision->update(['status' => 'validated']);

        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson("/api/domains/{$domain->id}/proxy", $this->proxy(['retry_count' => 1]))->assertStatus(202);
        $this->assertSame('enabled', $domain->refresh()->proxy_settings['diagnostics'], 'a request that omits the choice keeps it');

        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->patchJson("/api/domains/{$domain->id}/proxy", $this->proxy(['diagnostics' => 'disabled']))->assertStatus(202);
        $this->assertSame('disabled', $domain->refresh()->proxy_settings['diagnostics']);

        $this->actingAs($owner)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/domains/{$domain->id}/rollback", ['revision' => $enabledRevision->revision])->assertStatus(202);
        $this->assertSame('enabled', $domain->refresh()->proxy_settings['diagnostics'], 'rollback restores the previous value');
    }
}
