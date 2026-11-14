<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\SecurityConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function ownedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'shop.example', 'display_name' => 'shop.example', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);

        return [$user, $domain];
    }

    private function channel(): void
    {
        $channel = new NotificationChannel(['type' => 'telegram', 'name' => 'Ops', 'destination_hint' => 'Telegram chat 1']);
        $channel->storeSecrets(['bot_token' => '123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'chat_id' => '1']);
        $channel->save();
    }

    /** ClickHouse answers one row per page and resource, as the sync query groups them. */
    private function reports(Domain $domain, array $rows): void
    {
        $lines = array_map(fn (array $row): string => json_encode(['domain_id' => (string) $domain->id, 'kind' => $row[0], 'page' => $row[1], 'resource' => $row[2], 'first_seen' => $row[3], 'last_seen' => $row[3]]), $rows);
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(implode("\n", $lines))]);
    }

    public function test_the_opt_in_is_saved_revisioned_compiled_and_removed(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/security/client-side";

        $this->actingAs($user)->putJson($url, ['maximum_reports_per_minute' => 60, 'baseline_minutes' => 30])->assertAccepted();
        $domain->refresh();
        $this->assertSame(2, $domain->revision);
        $this->assertSame(60, $domain->client_security['maximum_reports_per_minute']);
        Queue::assertPushed(ReconcileEdgeDomain::class);
        $compiled = SecurityConfig::compile($domain)['client_security'];
        $this->assertTrue($compiled['enabled']);
        $this->assertSame('/.cdnf/csp-report', $compiled['report_path']);
        $this->assertStringContainsString('report-uri /.cdnf/csp-report', $compiled['policy']);
        $this->assertStringNotContainsString('default-src', $compiled['policy']);

        foreach ([['maximum_reports_per_minute' => 5], ['maximum_reports_per_minute' => 601], ['baseline_minutes' => -1], ['baseline_minutes' => 2000]] as $bad) {
            $this->actingAs($user)->putJson($url, $bad)->assertUnprocessable();
        }
        $this->actingAs(User::factory()->create())->putJson($url, [])->assertNotFound();
        $this->actingAs($user)->deleteJson($url)->assertAccepted();
        $this->assertNull($domain->refresh()->client_security);
        $this->assertSame(['enabled' => false], SecurityConfig::compile($domain)['client_security']);
    }

    public function test_the_first_scripts_are_the_baseline_and_a_later_new_one_sends_one_notification(): void
    {
        Queue::fake();
        Cache::flush();
        [$user, $domain] = $this->ownedDomain();
        $this->channel();
        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/security/client-side", ['baseline_minutes' => 60])->assertAccepted();
        $now = now('UTC');

        $this->reports($domain, [['script', '/checkout', 'https://cdn.shop.example', $now->format('Y-m-d H:i:s.v')]]);
        $this->artisan('cdnf:client-security:sync-scripts')->assertSuccessful();
        $this->assertSame('baseline', $domain->clientScriptSightings()->firstOrFail()->source);
        Queue::assertNotPushed(DeliverNotification::class);

        // The baseline window ends; a second script and a connection arrive together and are reported once.
        $domain->refresh()->update(['client_security' => [...$domain->client_security, 'enabled_at' => now()->subHours(3)->toIso8601String()]]);
        $later = $now->addMinutes(5)->format('Y-m-d H:i:s.v');
        $this->reports($domain, [['script', '/checkout', 'https://cdn.shop.example', $now->format('Y-m-d H:i:s.v')], ['script', '/checkout', 'https://evil.example', $later], ['connection', '/checkout', 'https://api.evil.example', $later]]);
        $this->artisan('cdnf:client-security:sync-scripts')->assertSuccessful();
        Queue::assertPushed(DeliverNotification::class, 1);
        $this->assertSame(3, $domain->clientScriptSightings()->count());
        $this->assertSame(2, $domain->clientScriptSightings()->whereNull('approved_at')->count());

        // The same reports again change nothing and send nothing more.
        $this->artisan('cdnf:client-security:sync-scripts')->assertSuccessful();
        Queue::assertPushed(DeliverNotification::class, 1);
        $this->assertSame(3, $domain->clientScriptSightings()->count());

        $list = $this->actingAs($user)->getJson("/api/domains/{$domain->id}/security/client-side/scripts?status=new&kind=script")->assertOk();
        $this->assertSame(['https://evil.example'], array_column($list->json('data.data'), 'resource'));
        $id = $list->json('data.data.0.id');
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/client-side/scripts/{$id}/approve")->assertOk()->assertJsonPath('data.approved', 1);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/client-side/scripts/approve")->assertOk()->assertJsonPath('data.approved', 1);
        $this->assertSame(0, $domain->clientScriptSightings()->whereNull('approved_at')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.client_scripts_approved', 'subject_id' => (string) $domain->id]);
    }

    public function test_sightings_are_private_to_the_domains_users_and_domains_without_the_opt_in_are_skipped(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $other = Domain::query()->create(['name' => 'other.example', 'display_name' => 'other.example', 'revision' => 1, 'lifecycle_state' => 'active']);
        $this->reports($other, [['script', '/', 'https://x.example', now('UTC')->format('Y-m-d H:i:s.v')]]);
        $this->artisan('cdnf:client-security:sync-scripts')->assertSuccessful();
        $this->assertSame(0, $other->clientScriptSightings()->count());
        $this->actingAs(User::factory()->create())->getJson("/api/domains/{$domain->id}/security/client-side/scripts")->assertNotFound();
        $this->actingAs(User::factory()->create())->postJson("/api/domains/{$domain->id}/security/client-side/scripts/approve")->assertNotFound();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/client-side/scripts/999/approve")->assertNotFound();
    }
}
