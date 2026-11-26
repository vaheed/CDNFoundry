<?php

namespace Tests\Feature;

use App\Http\Controllers\EdgeAgentController;
use App\Models\ClientReputation as ClientReputationRow;
use App\Models\ClientReputationReport;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\User;
use App\Support\ClientReputation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ClientReputationShadowTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '198.51.100.7';

    public function test_a_client_reported_by_two_cells_with_mostly_events_is_banded_and_a_single_cell_is_not(): void
    {
        [$first, $second] = $this->edges();
        $domain = $this->domain(true);
        ClientReputation::record($first->id, 'cell-01', [$this->entry($domain, 130, 140)]);
        $this->assertSame(0, ClientReputation::aggregate(), 'one cell alone is not shared evidence');

        ClientReputation::record($second->id, 'cell-01', [$this->entry($domain, 80, 90)]);
        $this->assertSame(1, ClientReputation::aggregate());
        $row = ClientReputationRow::query()->firstOrFail();
        $this->assertSame([self::CLIENT, 'elevated', 210, 2, 2], [$row->client_key, $row->band, $row->score, $row->cell_count, $row->edge_count]);
    }

    public function test_a_shared_address_with_a_few_attackers_among_many_browsers_is_not_banded(): void
    {
        [$first, $second] = $this->edges();
        $domain = $this->domain(true);
        ClientReputation::record($first->id, 'cell-01', [$this->entry($domain, 60, 1200)]);
        ClientReputation::record($second->id, 'cell-01', [$this->entry($domain, 60, 1200)]);

        $this->assertSame(0, ClientReputation::aggregate());
    }

    public function test_reports_of_a_domain_that_did_not_opt_in_are_never_stored_and_leaving_removes_what_was_kept(): void
    {
        [$first] = $this->edges();
        $out = $this->domain(false);
        $this->assertSame(0, ClientReputation::record($first->id, 'cell-01', [$this->entry($out, 100, 100)]));
        $this->assertSame(0, ClientReputationReport::query()->count());

        $in = $this->domain(true, 'in.test');
        $this->assertSame(1, ClientReputation::record($first->id, 'cell-01', [$this->entry($in, 100, 100)]));
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->putJson("/api/domains/{$in->id}/reputation-sharing", ['enabled' => false])->assertOk()->assertJsonPath('data.effect', 'none');
        $this->assertSame(0, ClientReputationReport::query()->count());
    }

    public function test_a_band_fades_by_half_every_hour_and_ends_below_the_floor_without_new_reports(): void
    {
        [$first, $second] = $this->edges();
        $domain = $this->domain(true);
        ClientReputation::record($first->id, 'cell-01', [$this->entry($domain, 700, 700)]);
        ClientReputation::record($second->id, 'cell-01', [$this->entry($domain, 700, 700)]);
        ClientReputation::aggregate();
        $this->assertSame('high', ClientReputationRow::query()->firstOrFail()->band);

        ClientReputationReport::query()->update(['reported_at' => now()->subMinutes(10)]);
        $this->travel(61)->minutes();
        ClientReputation::aggregate();
        $row = ClientReputationRow::query()->firstOrFail();
        $this->assertEqualsWithDelta(700, $row->score, 15, 'half of 1400 after an hour');
        $this->assertSame('elevated', $row->band);

        $this->travel(5)->hours();
        ClientReputation::aggregate();
        $this->assertSame(0, ClientReputationRow::query()->count());
    }

    public function test_reports_and_bands_are_bounded_and_malformed_entries_are_dropped(): void
    {
        [$first] = $this->edges();
        $domain = $this->domain(true);
        $entries = [['domain_id' => $domain->id, 'client' => 'not-an-address', 'events' => 5, 'requests' => 5], ['domain_id' => $domain->id, 'client' => '2001:db8:1:2::/64', 'events' => 0, 'requests' => 5],
            'junk', ['domain_id' => $domain->id, 'client' => '2001:db8:1:2::/64', 'events' => 9, 'requests' => 9], ['domain_id' => $domain->id, 'client' => '10.0.0.1/99', 'events' => 9, 'requests' => 9]];
        $this->assertSame(1, ClientReputation::record($first->id, 'cell-01', $entries));

        $many = [];
        for ($index = 0; $index < ClientReputation::MAXIMUM_REPORTED_CLIENTS + 30; $index++) {
            $many[] = ['domain_id' => $domain->id, 'client' => '203.0.113.'.($index % 250), 'events' => 3, 'requests' => 3];
        }
        $this->assertSame(ClientReputation::MAXIMUM_REPORTED_CLIENTS, ClientReputation::record($first->id, 'cell-02', $many));
    }

    public function test_the_heartbeat_hands_reports_over_and_only_an_administrator_reads_the_bands_without_a_domain_name(): void
    {
        [$first, $second] = $this->edges();
        $domain = $this->domain(true);
        foreach ([$first, $second] as $edge) {
            $edge->cells()->update(['name' => 'cell-01']);
            $request = Request::create('/edge/v1/heartbeat', 'POST', [
                'agent_version' => '1.2.0', 'listener_ready' => true, 'active_sequence' => 0,
                'cells' => [['name' => 'cell-01', 'status' => 'ready', 'capacity' => ['active_connections' => 0]]],
                'client_offenders' => [['cell' => 'cell-01', 'domain_id' => $domain->id, 'client' => self::CLIENT, 'events' => 80, 'requests' => 90],
                    ['cell' => 'cell-09', 'domain_id' => $domain->id, 'client' => self::CLIENT, 'events' => 999, 'requests' => 999], 'junk'],
            ]);
            $request->attributes->set('edge', $edge);
            $this->assertSame(200, app(EdgeAgentController::class)->heartbeat($request)->getStatusCode());
        }
        $this->assertSame(2, ClientReputationReport::query()->count());
        $this->artisan('cdnf:client-reputation:aggregate')->expectsOutput('clients_banded=1')->assertSuccessful();

        $owner = User::factory()->create();
        $owner->domains()->attach($domain->id);
        $this->actingAs($owner)->getJson('/api/admin/client-reputation')->assertForbidden();
        $this->actingAs($owner)->getJson("/api/domains/{$domain->id}/reputation-sharing")->assertOk()->assertJsonPath('data.enabled', true);

        $response = $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/client-reputation')->assertOk()
            ->assertJsonPath('data.data.0.client', self::CLIENT)->assertJsonPath('data.data.0.band', 'watch')->assertJsonPath('stage', 'shadow');
        $this->assertStringNotContainsString($domain->name, $response->getContent());
    }

    public function test_a_user_cannot_change_or_read_the_opt_in_of_a_domain_that_is_not_theirs(): void
    {
        $domain = $this->domain(false);
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->putJson("/api/domains/{$domain->id}/reputation-sharing", ['enabled' => true])->assertNotFound();
        $this->assertFalse($domain->refresh()->reputation_sharing);
        $this->actingAs(User::factory()->admin()->create())->putJson("/api/domains/{$domain->id}/reputation-sharing", ['enabled' => 'maybe'])->assertUnprocessable();
    }

    /** @return array{0: Edge, 1: Edge} */
    private function edges(): array
    {
        return array_map(function (string $name): Edge {
            $edge = Edge::query()->create(['name' => $name, 'country_code' => 'DE', 'continent_code' => 'EU']);
            $edge->cells()->create(['slot' => 1, 'status' => 'ready']);

            return $edge;
        }, ['first', 'second']);
    }

    private function domain(bool $enrolled, string $name = 'rep.test'): Domain
    {
        $domain = Domain::query()->create(['name' => $name, 'display_name' => $name, 'lifecycle_state' => 'active', 'revision' => 1, 'nameservers_verified_at' => now()]);
        $domain->forceFill(['reputation_sharing' => $enrolled])->save();

        return $domain;
    }

    /** @return array<string, mixed> */
    private function entry(Domain $domain, int $events, int $requests, string $client = self::CLIENT): array
    {
        return ['domain_id' => $domain->id, 'client' => $client, 'events' => $events, 'requests' => $requests];
    }
}
