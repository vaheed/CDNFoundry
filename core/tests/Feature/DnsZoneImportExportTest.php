<?php

namespace Tests\Feature;

use App\Jobs\ImportDnsZone;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DnsZoneImportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_bind_import_export_round_trips_supported_records_atomically(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $zone = <<<'ZONE'
$ORIGIN example.com.
$TTL 5m
@ IN SOA ns1.cdnf.test. hostmaster.cdnf.test. (
  42 1h 10m 1w 5m
)
@ IN A 192.0.2.10
  IN AAAA 2001:db8::10
www 60 IN CNAME target.example.net.
@ 300 IN MX 10 mail
@ 300 IN TXT "verification=" "valid"
child 300 IN NS ns1.example.net.
@ 300 IN CAA 0 issue "letsencrypt.org"
_sip._tcp 300 IN SRV 10 5 5060 sip
pointer 300 IN PTR host
ZONE;

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/import", ['zone' => $zone, 'replace_existing' => true])
            ->assertOk()->assertJsonPath('data.imported', 9)->assertJsonPath('data.revision', 2);
        $this->assertDatabaseCount('dns_records', 9);

        $export = $this->actingAs($user)->get("/api/domains/{$domain->id}/dns/export")
            ->assertOk()->assertHeader('content-type', 'text/dns; charset=utf-8')->getContent();
        $this->assertStringContainsString('$ORIGIN example.com.', $export);
        $this->assertStringContainsString("www\t60\tIN\tCNAME\ttarget.example.net.", $export);
        $this->assertStringContainsString('"verification=valid"', $export);

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/import", ['zone' => $export, 'replace_existing' => true])
            ->assertOk()->assertJsonPath('data.imported', 9)->assertJsonPath('data.revision', 3);
        $this->assertDatabaseCount('dns_records', 9);
    }

    public function test_append_duplicate_and_invalid_cname_roll_back_the_entire_import(): void
    {
        [$user, $domain] = $this->ownedDomain();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/import", [
            'zone' => "@ 300 IN A 192.0.2.1\n",
        ])->assertOk();
        $revision = $domain->refresh()->revision;

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/import", [
            'zone' => "new 300 IN TXT \"would-be-created\"\n@ 300 IN A 192.0.2.1\n",
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('dns_records', ['name' => 'new.example.com']);
        $this->assertSame($revision, $domain->refresh()->revision);

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/import", [
            'zone' => "@ 300 IN CNAME target.example.net.\n",
        ])->assertUnprocessable();
        $this->assertDatabaseCount('dns_records', 1);
    }

    public function test_large_import_is_queued_and_applied_as_one_revision(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $lines = ['$ORIGIN example.com.', '$TTL 60'];
        for ($index = 1; $index <= 101; $index++) {
            $lines[] = "host$index A 192.0.2.".(($index % 250) + 1);
        }

        $response = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/import", [
            'zone' => implode("\n", $lines), 'replace_existing' => true,
        ])->assertAccepted()->assertJsonPath('data.type', 'dns.zone_import');
        $operationId = $response->json('data.id');
        $this->assertDatabaseCount('dns_records', 0);
        Queue::assertPushed(ImportDnsZone::class, fn ($job): bool => $job->operationId === $operationId);

        (new ImportDnsZone($operationId))->handle();
        $this->assertDatabaseCount('dns_records', 101);
        $this->assertSame(2, $domain->refresh()->revision);
        $this->assertSame('succeeded', Operation::findOrFail($operationId)->status);
    }

    public function test_large_invalid_import_fails_without_partial_state_and_can_be_retried(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $zone = implode("\n", array_fill(0, 100, 'same 60 IN A 192.0.2.1'))."\nbad 60 IN A invalid";
        $response = $this->actingAs($user)->postJson("/api/domains/{$domain->id}/dns/import", ['zone' => $zone])->assertAccepted();
        $operation = Operation::findOrFail($response->json('data.id'));

        try {
            (new ImportDnsZone($operation->id))->handle();
            $this->fail('Invalid asynchronous import must fail.');
        } catch (\Throwable) {
        }
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertDatabaseCount('dns_records', 0);
        $this->assertSame(1, $domain->refresh()->revision);

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/api/admin/operations/{$operation->id}/retry")->assertAccepted();
        Queue::assertPushed(ImportDnsZone::class, 2);
    }

    public function test_import_export_respect_domain_policy_and_payload_bounds(): void
    {
        [$owner, $domain] = $this->ownedDomain();
        $other = User::factory()->create();
        $this->actingAs($other)->get("/api/domains/{$domain->id}/dns/export")->assertForbidden();
        $this->actingAs($other)->postJson("/api/domains/{$domain->id}/dns/import", ['zone' => '@ 60 IN A 192.0.2.1'])->assertForbidden();
        $this->actingAs($owner)->postJson("/api/domains/{$domain->id}/dns/import", ['zone' => str_repeat('x', 1048577)])->assertUnprocessable();
        $this->actingAs($owner)->postJson("/api/domains/{$domain->id}/dns/import", ['zone' => str_repeat('é', 600000)])->assertUnprocessable();
    }

    private function ownedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'example.com', 'display_name' => 'example.com', 'lifecycle_state' => 'pending_verification', 'revision' => 1]);
        $domain->users()->attach($user);

        return [$user, $domain];
    }
}
