<?php

namespace Tests\Feature;

use App\Actions\ChangeAutomaticSecurityState;
use App\Jobs\DeliverNotification;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\NotificationChannel;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IncidentProfileTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Domain} */
    private function owner(string $name = 'incident.example'): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => $name, 'display_name' => $name, 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user->id);

        return [$user, $domain];
    }

    public function test_a_domain_user_turns_the_profile_on_for_a_chosen_time_and_it_rides_in_one_overlay(): void
    {
        Queue::fake();
        [$user, $domain] = $this->owner();
        $edge = Edge::query()->create(['name' => 'incident-pop', 'country_code' => 'DE', 'continent_code' => 'EU']);
        $cell = $edge->cells()->create(['slot' => 1, 'status' => 'ready']);
        DomainEdgeCell::query()->create(['domain_id' => $domain->id, 'edge_id' => $edge->id, 'replica' => 1, 'active_cell_id' => $cell->id, 'desired_revision' => 1, 'state' => 'active']);
        $revisionBefore = $domain->revision;

        $response = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/incident", ['duration_minutes' => 30])->assertCreated()
            ->assertJsonPath('data.active', true)->assertJsonPath('data.overlay_sequence', 1);
        $this->assertEqualsWithDelta(30, now()->diffInMinutes($response->json('data.expires_at'), absolute: true), 1);
        $domain->refresh();
        $this->assertSame('restricted', $domain->automatic_security_state);
        $this->assertSame($revisionBefore, $domain->revision, 'an overlay, never a configuration revision');

        $task = EdgeTask::query()->where('type', 'security_overlay')->firstOrFail();
        $this->assertSame('restricted', $task->payload['state']);
        $this->assertSame(['mode' => 'block', 'sensitivity' => 'high'], $task->payload['incident']['waf']);
        $this->assertTrue($task->payload['incident']['challenge']);
        $this->assertSame(['GET', 'HEAD', 'OPTIONS', 'POST'], $task->payload['incident']['methods']);
        $this->assertSame($domain->incident_expires_at->getTimestamp(), $task->payload['incident']['expires_at']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.incident_started']);
    }

    public function test_it_ends_early_in_one_call_and_leaves_a_readable_trail_of_two_overlays(): void
    {
        Queue::fake();
        [$user, $domain] = $this->owner();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/incident", ['duration_minutes' => 60])->assertCreated();
        $this->deleteJson("/api/domains/{$domain->id}/incident")->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.overlay_sequence', 2);
        $domain->refresh();
        $this->assertSame('normal', $domain->automatic_security_state);
        $this->assertNull($domain->incident_expires_at);
        $this->assertSame(['security.incident_started', 'security.incident_ended'], AuditLog::query()->whereIn('action', ['security.incident_started', 'security.incident_ended'])->orderBy('id')->pluck('action')->all());
        $this->assertSame('manual', AuditLog::query()->where('action', 'security.incident_ended')->firstOrFail()->metadata['reason']);
        $this->deleteJson("/api/domains/{$domain->id}/incident")->assertStatus(409);
    }

    public function test_it_expires_without_operator_action_and_the_quiet_period_never_ends_it_early(): void
    {
        Queue::fake();
        [$user, $domain] = $this->owner();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/incident", ['duration_minutes' => 5])->assertCreated();

        // Quiet for longer than the recovery window, yet the profile holds the state.
        $this->artisan('cdnf:security:reconcile-readiness')->assertSuccessful();
        $this->assertSame('restricted', $domain->refresh()->automatic_security_state);

        $this->travel(6)->minutes();
        $this->artisan('cdnf:security:expire-incidents')->assertSuccessful();
        $domain->refresh();
        $this->assertSame('normal', $domain->automatic_security_state);
        $this->assertNull($domain->incident_expires_at);
        $this->assertSame('expired', AuditLog::query()->where('action', 'security.incident_ended')->firstOrFail()->metadata['reason']);
        $this->assertSame(2, (int) $domain->security_overlay_sequence);
    }

    public function test_bounds_permissions_and_tenancy(): void
    {
        Queue::fake();
        [$alice, $mine] = $this->owner('alice-incident.example');
        [$bob, $theirs] = $this->owner('bob-incident.example');
        $this->actingAs($alice);
        foreach ([0, 4, 1441, 'x'] as $minutes) {
            $this->postJson("/api/domains/{$mine->id}/incident", ['duration_minutes' => $minutes])->assertUnprocessable();
        }
        $this->postJson("/api/domains/{$mine->id}/incident", [])->assertUnprocessable();
        $this->postJson("/api/domains/{$theirs->id}/incident", ['duration_minutes' => 30])->assertNotFound();
        $this->getJson("/api/domains/{$theirs->id}/incident")->assertNotFound();
        $this->postJson("/api/domains/{$mine->id}/incident", ['duration_minutes' => 30])->assertCreated();
        $this->postJson("/api/domains/{$mine->id}/incident", ['duration_minutes' => 30])->assertStatus(409);
        $this->actingAs($bob)->deleteJson("/api/domains/{$mine->id}/incident")->assertNotFound();
        $this->getJson("/api/domains/{$theirs->id}/incident")->assertOk()->assertJsonPath('data.active', false)->assertJsonCount(4, 'data.protections');
    }

    public function test_it_is_idempotent_and_a_domain_that_is_not_active_is_refused(): void
    {
        Queue::fake();
        [$user, $domain] = $this->owner();
        $headers = ['Idempotency-Key' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/incident", ['duration_minutes' => 30], $headers)->assertCreated();
        $this->postJson("/api/domains/{$domain->id}/incident", ['duration_minutes' => 30], $headers)->assertCreated();
        $this->postJson("/api/domains/{$domain->id}/incident", ['duration_minutes' => 45], $headers)->assertConflict();
        $this->assertSame(1, AuditLog::query()->where('action', 'security.incident_started')->count());

        [$other, $pending] = $this->owner('pending-incident.example');
        $pending->forceFill(['lifecycle_state' => 'pending_verification'])->save();
        $this->actingAs($other)->postJson("/api/domains/{$pending->id}/incident", ['duration_minutes' => 30])->assertStatus(409);
    }

    public function test_start_and_end_notify_assigned_users_and_administrators_once(): void
    {
        Queue::fake();
        $row = SystemSetting::query()->findOrFail('notifications');
        $row->update(['values' => [...$row->values, 'domain_user_channels' => true]]);
        app()->forgetInstance(PlatformSettings::class);
        [$user, $domain] = $this->owner();
        $channel = new NotificationChannel(['user_id' => $user->id, 'type' => 'telegram', 'name' => 'mine', 'destination_hint' => 'x', 'events' => ['incident.started', 'incident.ended'], 'status' => 'active']);
        $channel->storeSecrets(['bot_token' => 'x', 'chat_id' => '1']);
        $channel->save();

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/incident", ['duration_minutes' => 30])->assertCreated();
        $this->deleteJson("/api/domains/{$domain->id}/incident")->assertOk();
        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job): bool => $job->message['event'] === 'incident.started' && $job->channelId === $channel->id);
        Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job): bool => $job->message['event'] === 'incident.ended');
        Queue::assertNotPushed(DeliverNotification::class, fn (DeliverNotification $job): bool => $job->message['event'] === 'security.state_changed');
    }

    public function test_a_forced_overlay_sends_even_when_the_state_is_the_same(): void
    {
        Queue::fake();
        [, $domain] = $this->owner();
        ChangeAutomaticSecurityState::execute($domain, 'restricted', 'event_rate');
        ChangeAutomaticSecurityState::execute($domain, 'restricted', 'again');
        $this->assertSame(1, (int) $domain->refresh()->security_overlay_sequence);
        ChangeAutomaticSecurityState::execute($domain, 'restricted', 'incident_started', force: true);
        $this->assertSame(2, (int) $domain->refresh()->security_overlay_sequence);
    }
}
