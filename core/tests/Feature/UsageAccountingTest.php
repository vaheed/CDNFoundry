<?php

namespace Tests\Feature;

use App\Jobs\BuildUsageRollups;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\UsageCoverage;
use App\Models\UsageRollup;
use App\Models\User;
use App\Support\AnalyticsStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Usage is stored sparsely with an explicit coverage record, so a telemetry outage is never a finalized zero, a
 * rebuild that changes a finalized hour is a recorded correction, and attribution survives a deleted domain.
 */
class UsageAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function build(string $from, ?int $domainId = null): void
    {
        $start = CarbonImmutable::parse($from, 'UTC');
        (new BuildUsageRollups($start->toIso8601String(), $start->addHour()->toIso8601String(), $domainId))->handle(app(AnalyticsStore::class));
    }

    /** @var list<array<string, int>> */
    private array $served = [];

    private function telemetry(array $rows): void
    {
        $this->served = $rows;
        Http::fake([config('services.clickhouse.url').'*' => fn () => Http::response(implode("\n", array_map('json_encode', $this->served)))]);
    }

    private function row(Domain $domain, int $requests): array
    {
        return ['domain_id' => $domain->id, 'requests' => $requests, 'bytes_in' => $requests, 'bytes_out' => $requests * 10, 'cache_hits' => 0, 'dns_queries' => 0];
    }

    private function domain(string $name): Domain
    {
        return Domain::query()->create(['name' => $name, 'display_name' => $name, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now(), 'revision' => 1]);
    }

    public function test_rows_are_sparse_and_an_hour_with_traffic_elsewhere_is_a_known_zero(): void
    {
        $busy = $this->domain('busy.example');
        $quiet = $this->domain('quiet.example');
        $this->telemetry([$this->row($busy, 5)]);

        $this->build('2026-07-20T08:00:00Z');

        $this->assertSame(1, UsageRollup::query()->count());
        $this->assertNull(UsageRollup::query()->where('domain_id', $quiet->id)->first());
        $this->assertDatabaseHas('usage_coverage', ['status' => 'finalized', 'domains_with_traffic' => 1]);
    }

    public function test_an_hour_with_no_telemetry_at_all_is_incomplete_and_never_a_final_zero(): void
    {
        $this->domain('silent.example');
        Edge::query()->create(['name' => 'edge-a', 'country_code' => 'DE', 'continent_code' => 'EU']);
        $this->telemetry([]);

        $this->build('2026-07-20T08:00:00Z');

        $this->assertSame(0, UsageRollup::query()->count());
        $this->assertDatabaseHas('usage_coverage', ['status' => 'incomplete', 'reason' => 'no_telemetry_for_interval']);
    }

    public function test_a_fleet_without_edges_has_a_known_zero_hour(): void
    {
        $this->domain('lab.example');
        $this->telemetry([]);

        $this->build('2026-07-20T08:00:00Z');

        $this->assertDatabaseHas('usage_coverage', ['status' => 'finalized', 'domains_with_traffic' => 0]);
    }

    public function test_a_failed_source_records_nothing_so_the_catch_up_finds_the_hour_unbuilt(): void
    {
        $this->domain('down.example');
        Http::fake([config('services.clickhouse.url').'*' => Http::response('down', 500)]);

        try {
            $this->build('2026-07-20T08:00:00Z');
            $this->fail('The build must fail while the analytics store is down.');
        } catch (\Throwable) {
        }

        $this->assertSame(0, UsageCoverage::query()->count());
        $this->assertSame(0, UsageRollup::query()->count());
    }

    public function test_a_rebuild_that_changes_a_finalized_hour_is_a_recorded_correction_and_an_identical_one_changes_nothing(): void
    {
        $domain = $this->domain('late.example');
        $this->telemetry([$this->row($domain, 5)]);
        $this->build('2026-07-20T08:00:00Z');
        $this->build('2026-07-20T08:00:00Z');
        $row = UsageRollup::query()->sole();
        $this->assertSame('finalized', $row->status);
        $this->assertNull($row->corrections);

        $this->telemetry([$this->row($domain, 9)]);
        $this->build('2026-07-20T08:00:00Z');

        $row = $row->refresh();
        $this->assertSame('corrected', $row->status);
        $this->assertSame(9, $row->requests);
        $this->assertSame(5, $row->corrections[0]['previous']['requests']);
        $this->assertSame('late_telemetry', $row->corrections[0]['reason']);
    }

    public function test_attribution_is_written_with_the_row_and_survives_owner_changes_and_domain_deletion(): void
    {
        $domain = $this->domain('owned.example');
        $first = User::factory()->create();
        $second = User::factory()->create();
        $domain->users()->attach($first);
        $this->telemetry([$this->row($domain, 3)]);
        $this->build('2026-07-20T08:00:00Z');

        $domain->users()->detach($first);
        $domain->users()->attach($second);
        $this->telemetry([$this->row($domain, 4)]);
        $this->build('2026-07-20T09:00:00Z');
        $domain->forceDelete();

        $rows = UsageRollup::query()->orderBy('interval_start')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$first->id], $rows[0]->owner_user_ids);
        $this->assertSame([$second->id], $rows[1]->owner_user_ids);
        $this->assertSame('owned.example', $rows[0]->domain_name);
        $this->assertSame(3, $rows[0]->requests);
    }

    public function test_a_single_domain_rebuild_writes_no_coverage(): void
    {
        $domain = $this->domain('one.example');
        $this->telemetry([$this->row($domain, 2)]);

        $this->build('2026-07-20T08:00:00Z', $domain->id);

        $this->assertSame(1, UsageRollup::query()->count());
        $this->assertSame(0, UsageCoverage::query()->count());
    }

    public function test_the_catch_up_builds_every_missing_hour_and_never_before_the_first_covered_one(): void
    {
        Bus::fake();
        $last = CarbonImmutable::now('UTC')->subHour()->startOfHour()->subHour();
        $this->artisan('cdnf:usage:finalize')->assertSuccessful();
        Bus::assertDispatchedTimes(BuildUsageRollups::class, 1);
        Bus::assertDispatched(BuildUsageRollups::class, fn ($job) => $job->from === $last->toIso8601String());

        UsageCoverage::query()->create(['interval_start' => $last->subHours(6), 'interval_end' => $last->subHours(5), 'status' => 'finalized', 'domains_with_traffic' => 1, 'built_at' => now()]);
        UsageCoverage::query()->create(['interval_start' => $last->subHours(5), 'interval_end' => $last->subHours(4), 'status' => 'finalized', 'domains_with_traffic' => 1, 'built_at' => now()]);
        Bus::fake();
        $this->artisan('cdnf:usage:finalize')->assertSuccessful();

        // Hours -4 to 0 were never built: one contiguous job, nothing older than the first covered hour.
        Bus::assertDispatchedTimes(BuildUsageRollups::class, 1);
        Bus::assertDispatched(BuildUsageRollups::class, fn ($job) => $job->from === $last->subHours(4)->toIso8601String() && $job->to === $last->addHour()->toIso8601String());
    }

    public function test_the_catch_up_retries_recent_incomplete_hours_only(): void
    {
        $last = CarbonImmutable::now('UTC')->subHour()->startOfHour()->subHour();
        foreach ([2, 40] as $hoursAgo) {
            UsageCoverage::query()->create(['interval_start' => $last->subHours($hoursAgo), 'interval_end' => $last->subHours($hoursAgo - 1), 'status' => 'incomplete', 'reason' => 'no_telemetry_for_interval', 'domains_with_traffic' => 0, 'built_at' => now()]);
        }
        foreach (range(0, 39) as $hoursAgo) {
            if (! in_array($hoursAgo, [2, 40], true)) {
                UsageCoverage::query()->create(['interval_start' => $last->subHours($hoursAgo), 'interval_end' => $last->subHours($hoursAgo - 1), 'status' => 'finalized', 'domains_with_traffic' => 1, 'built_at' => now()]);
            }
        }
        Bus::fake();

        $this->artisan('cdnf:usage:finalize')->assertSuccessful();

        Bus::assertDispatchedTimes(BuildUsageRollups::class, 1);
        Bus::assertDispatched(BuildUsageRollups::class, fn ($job) => $job->from === $last->subHours(2)->toIso8601String());
    }

    public function test_compaction_carries_identity_and_marks_a_day_with_an_uncovered_hour_incomplete(): void
    {
        $domain = $this->domain('compact.example');
        $user = User::factory()->create();
        $day = CarbonImmutable::now('UTC')->subDays(60)->startOfDay();
        foreach ([3, 4] as $hour) {
            UsageRollup::query()->create(['domain_id' => $domain->id, 'interval_start' => $day->addHours($hour), 'interval_end' => $day->addHours($hour + 1), 'granularity' => 'hour',
                'requests' => 10, 'status' => 'finalized', 'domain_name' => 'compact.example', 'owner_user_ids' => [$user->id]]);
        }
        UsageCoverage::query()->create(['interval_start' => $day->addHours(5), 'interval_end' => $day->addHours(6), 'status' => 'incomplete', 'reason' => 'no_telemetry_for_interval', 'domains_with_traffic' => 0, 'built_at' => now()]);

        $this->artisan('cdnf:usage:compact')->assertSuccessful();

        $summed = UsageRollup::query()->where('granularity', 'day')->sole();
        $this->assertSame(20, $summed->requests);
        $this->assertSame('incomplete', $summed->status);
        $this->assertSame('compact.example', $summed->domain_name);
        $this->assertSame([$user->id], $summed->owner_user_ids);
        $this->assertSame(0, UsageCoverage::query()->count());
    }

    public function test_the_usage_api_reports_coverage_for_the_range(): void
    {
        $domain = $this->domain('covered.example');
        $user = User::factory()->create();
        $domain->users()->attach($user);
        $from = CarbonImmutable::parse('2026-07-20T08:00:00Z');
        UsageCoverage::query()->create(['interval_start' => $from, 'interval_end' => $from->addHour(), 'status' => 'finalized', 'domains_with_traffic' => 1, 'built_at' => now()]);
        UsageCoverage::query()->create(['interval_start' => $from->addHour(), 'interval_end' => $from->addHours(2), 'status' => 'incomplete', 'domains_with_traffic' => 0, 'built_at' => now()]);

        $meta = $this->actingAs($user)->getJson("/api/domains/{$domain->id}/usage?".http_build_query(['from' => $from->toIso8601String(), 'to' => $from->addHours(4)->toIso8601String()]))
            ->assertOk()->json('meta.coverage');

        $this->assertSame(['hours' => 4, 'complete' => 1, 'incomplete' => 1, 'unbuilt' => 2], array_intersect_key($meta, array_flip(['hours', 'complete', 'incomplete', 'unbuilt'])));
    }
}
