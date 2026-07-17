<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use App\Support\DnsRecordData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DnsRecordApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_phase_two_types_normalize_and_validate_with_ipv4_ipv6_parity(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $records = [
            ['type' => 'A', 'name' => '@', 'content' => '192.0.2.1', 'ttl' => 300],
            ['type' => 'AAAA', 'name' => '@', 'content' => '2001:db8::1', 'ttl' => 300],
            ['type' => 'CNAME', 'name' => 'www', 'content' => 'target.example.net.', 'ttl' => 300],
            ['type' => 'MX', 'name' => '@', 'content' => 'mail', 'ttl' => 300, 'priority' => 10],
            ['type' => 'TXT', 'name' => '@', 'content' => 'verification=value', 'ttl' => 300],
            ['type' => 'NS', 'name' => 'child', 'content' => 'ns1.example.net.', 'ttl' => 300],
            ['type' => 'CAA', 'name' => '@', 'content' => '0 issue "letsencrypt.org"', 'ttl' => 300],
            ['type' => 'SRV', 'name' => '_sip._tcp', 'content' => 'sip', 'ttl' => 300, 'priority' => 10, 'weight' => 5, 'port' => 5060],
        ];

        foreach ($records as $record) {
            $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $record)->assertCreated();
        }

        $this->assertDatabaseCount('dns_records', count($records));
        $this->assertSame(1 + count($records), $domain->refresh()->revision);
        $this->assertDatabaseHas('dns_records', ['type' => 'MX', 'name' => 'example.com', 'content' => 'mail.example.com.', 'priority' => 10]);
    }

    public function test_ptr_is_restricted_to_managed_ipv4_and_ipv6_reverse_zones(): void
    {
        [$user, $forward] = $this->ownedDomain();
        $ptr = ['type' => 'PTR', 'name' => '20', 'content' => 'host.example.net.', 'ttl' => 300];
        $this->actingAs($user)->postJson("/api/domains/{$forward->id}/dns/records", $ptr)->assertUnprocessable();

        foreach (['2.0.192.in-addr.arpa', '8.b.d.0.1.0.0.2.ip6.arpa'] as $zone) {
            [, $reverse] = $this->ownedDomain($zone);
            $reverse->users()->syncWithoutDetaching([$user->id]);
            $this->actingAs($user)->postJson("/api/domains/{$reverse->id}/dns/records", $ptr)->assertCreated();
        }
    }

    public function test_record_routes_enforce_domain_assignment_and_record_boundary(): void
    {
        [$owner, $domain] = $this->ownedDomain();
        [$other, $otherDomain] = $this->ownedDomain('example.net');
        $record = $domain->dnsRecords()->create($this->record('A', '@', '192.0.2.10', $domain));

        $this->actingAs($other)->getJson("/api/domains/{$domain->id}/dns/records")->assertForbidden();
        $this->actingAs($owner)->getJson("/api/domains/{$otherDomain->id}/dns/records/{$record->id}")->assertForbidden();
        $this->actingAs($owner)->getJson("/api/domains/{$domain->id}/dns/records/{$record->id}")->assertOk();
    }

    public function test_duplicates_and_illegal_cname_coexistence_are_rejected(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $a = ['type' => 'A', 'name' => 'www', 'content' => '192.0.2.1', 'ttl' => 300];
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $a)->assertCreated();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", $a)->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", [
            'type' => 'CNAME', 'name' => 'www', 'content' => 'target.example.net.', 'ttl' => 300,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('dns_records', 1);
        $this->assertSame(2, $domain->refresh()->revision);
    }

    public function test_bulk_is_atomic_and_increments_one_revision(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $existing = $domain->dnsRecords()->create($this->record('A', 'old', '192.0.2.10', $domain));
        $before = $domain->revision;
        $actions = [
            ['action' => 'update', 'id' => $existing->id, 'record' => ['content' => '192.0.2.11']],
            ['action' => 'create', 'record' => ['type' => 'AAAA', 'name' => 'new', 'content' => '2001:db8::10', 'ttl' => 60]],
            ['action' => 'create', 'record' => ['type' => 'TXT', 'name' => '@', 'content' => 'bulk', 'ttl' => 60]],
        ];

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records/bulk", ['actions' => $actions])
            ->assertOk()->assertJsonPath('data.revision', $before + 1)->assertJsonPath('data.changed', 3);
        $this->assertDatabaseCount('dns_records', 3);
        $this->assertDatabaseHas('dns_records', ['id' => $existing->id, 'content' => '192.0.2.11']);

        $invalid = [
            ['action' => 'delete', 'id' => $existing->id],
            ['action' => 'create', 'record' => ['type' => 'A', 'name' => 'bad', 'content' => 'not-an-ip', 'ttl' => 60]],
        ];
        $revision = $domain->refresh()->revision;
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records/bulk", ['actions' => $invalid])->assertUnprocessable();
        $this->assertDatabaseHas('dns_records', ['id' => $existing->id]);
        $this->assertSame($revision, $domain->refresh()->revision);
    }

    public function test_update_delete_and_bulk_bounds_have_stable_behavior(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $created = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records", [
            'type' => 'AAAA', 'name' => 'api', 'content' => '2001:db8::1', 'ttl' => 300,
        ])->assertCreated();
        $id = $created->json('data.id');
        $revision = $domain->refresh()->revision;
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/dns/records/$id", ['ttl' => 300])->assertOk();
        $this->assertSame($revision, $domain->refresh()->revision);
        $this->actingAs($user)->patchJson("/api/domains/{$domain->id}/dns/records/$id", ['ttl' => 600])->assertOk()->assertJsonPath('data.ttl', 600);
        $this->actingAs($user)->deleteJson("/api/domains/{$domain->id}/dns/records/$id")->assertNoContent();
        $this->assertDatabaseMissing('dns_records', ['id' => $id]);

        $tooMany = array_fill(0, 5001, ['action' => 'delete', 'id' => 1]);
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records/bulk", ['actions' => $tooMany])->assertUnprocessable();
    }

    public function test_one_bounded_bulk_request_modifies_thousands_of_records_in_one_revision(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $actions = [];
        for ($index = 0; $index < 2000; $index++) {
            $actions[] = ['action' => 'create', 'record' => [
                'type' => 'A', 'name' => "host-$index", 'content' => '192.0.2.'.(($index % 250) + 1), 'ttl' => 60,
            ]];
        }

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/records/bulk", ['actions' => $actions])
            ->assertOk()->assertJsonPath('data.changed', 2000)->assertJsonPath('data.revision', 2);
        $this->assertSame(2000, $domain->dnsRecords()->count());
    }

    private function ownedDomain(string $name = 'example.com'): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => $name, 'display_name' => $name, 'lifecycle_state' => 'pending_verification', 'revision' => 1]);
        $domain->users()->attach($user);

        return [$user, $domain];
    }

    private function record(string $type, string $name, string $content, Domain $domain): array
    {
        return DnsRecordData::validate(compact('type', 'name', 'content') + ['ttl' => 300], $domain->name);
    }
}
