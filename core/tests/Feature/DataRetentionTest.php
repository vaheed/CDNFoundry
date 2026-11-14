<?php

namespace Tests\Feature;

use App\Jobs\BuildUsageRollups;
use App\Models\CachePurge;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\TlsOrder;
use App\Models\UsageRollup;
use App\Support\AnalyticsStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DataRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function hours(Domain $domain, CarbonImmutable $day, int $requests, string $status = 'finalized'): void
    {
        for ($hour = 0; $hour < 24; $hour++) {
            UsageRollup::query()->create(['domain_id' => $domain->id, 'interval_start' => $day->addHours($hour), 'interval_end' => $day->addHours($hour + 1), 'granularity' => 'hour',
                'requests' => $requests, 'bytes_in' => 10, 'bytes_out' => 100, 'cache_hits' => 1, 'dns_queries' => 2, 'status' => $status]);
        }
    }

    private function domain(string $name = 'usage.example'): Domain
    {
        return Domain::query()->create(['name' => $name, 'display_name' => $name, 'revision' => 1]);
    }

    public function test_old_hourly_usage_becomes_one_daily_row_with_the_same_totals_and_recent_hours_stay(): void
    {
        $domain = $this->domain();
        $old = CarbonImmutable::now('UTC')->subDays(60)->startOfDay();
        $recent = CarbonImmutable::now('UTC')->subDays(3)->startOfDay();
        $this->hours($domain, $old, 5);
        $this->hours($domain, $recent, 7);

        $this->artisan('cdnf:usage:compact')->assertSuccessful();

        $day = UsageRollup::query()->where('granularity', 'day')->sole();
        $this->assertTrue($day->interval_start->equalTo($old));
        $this->assertTrue($day->interval_end->equalTo($old->addDay()));
        $this->assertSame([120, 240, 2400, 24, 48], [$day->requests, $day->bytes_in, $day->bytes_out, $day->cache_hits, $day->dns_queries]);
        $this->assertSame(0, UsageRollup::query()->where('granularity', 'hour')->where('interval_start', '<', $old->addDay())->count());
        $this->assertSame(24, UsageRollup::query()->where('granularity', 'hour')->count());

        $this->artisan('cdnf:usage:compact')->assertSuccessful();
        $this->assertSame(1, UsageRollup::query()->where('granularity', 'day')->count());
        $this->assertSame(120, UsageRollup::query()->where('granularity', 'day')->sole()->requests);
    }

    public function test_a_day_with_a_provisional_hour_is_left_as_it_is(): void
    {
        $domain = $this->domain();
        $old = CarbonImmutable::now('UTC')->subDays(60)->startOfDay();
        $this->hours($domain, $old, 5, 'provisional');

        $this->artisan('cdnf:usage:compact')->assertSuccessful();

        $this->assertSame(24, UsageRollup::query()->where('granularity', 'hour')->count());
        $this->assertSame(0, UsageRollup::query()->where('granularity', 'day')->count());
    }

    public function test_daily_usage_older_than_a_year_becomes_one_monthly_row(): void
    {
        $domain = $this->domain();
        $month = CarbonImmutable::now('UTC')->subDays(500)->startOfMonth();
        foreach ([0, 1, 2] as $offset) {
            UsageRollup::query()->create(['domain_id' => $domain->id, 'interval_start' => $month->addDays($offset), 'interval_end' => $month->addDays($offset + 1), 'granularity' => 'day',
                'requests' => 100, 'bytes_in' => 1, 'bytes_out' => 2, 'cache_hits' => 3, 'dns_queries' => 4, 'status' => 'finalized']);
        }

        $this->artisan('cdnf:usage:compact')->assertSuccessful();

        $row = UsageRollup::query()->sole();
        $this->assertSame('month', $row->granularity);
        $this->assertTrue($row->interval_start->equalTo($month));
        $this->assertTrue($row->interval_end->equalTo($month->addMonth()));
        $this->assertSame([300, 3, 6, 9, 12], [$row->requests, $row->bytes_in, $row->bytes_out, $row->cache_hits, $row->dns_queries]);
    }

    public function test_finished_history_is_pruned_and_unfinished_history_and_the_newest_certificate_order_stay(): void
    {
        $domain = $this->domain('history.example');
        $edge = Edge::query()->create(['name' => 'prune-pop', 'country_code' => 'DE', 'continent_code' => 'EU']);
        $old = now()->subDays(400);
        $purge = fn (string $status, $at) => tap(CachePurge::query()->create(['domain_id' => $domain->id, 'type' => 'all', 'cache_epoch' => 1, 'cache_keys' => [], 'status' => $status, 'sequence' => random_int(1, 1000000)]),
            fn ($row) => $row->forceFill(['updated_at' => $at])->saveQuietly());
        $oldPurge = $purge('succeeded', $old);
        $runningPurge = $purge('running', $old);
        $task = fn (string $status, $at) => tap(EdgeTask::query()->create(['edge_id' => $edge->id, 'type' => 'cache_purge', 'status' => $status, 'payload' => []]), fn ($row) => $row->forceFill(['updated_at' => $at])->saveQuietly());
        $oldTask = $task('failed', $old);
        $pendingTask = $task('pending', $old);
        $order = fn (string $status, $at) => tap(TlsOrder::query()->create(['domain_id' => $domain->id, 'status' => $status, 'names' => ['history.example']]), fn ($row) => $row->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly());
        $oldOrder = $order('failed', $old);
        $newestOrder = $order('failed', $old->addDays(10));

        $this->artisan('cdnf:history:prune')->assertSuccessful();

        $this->assertNull(CachePurge::query()->find($oldPurge->id));
        $this->assertNotNull(CachePurge::query()->find($runningPurge->id));
        $this->assertNull(EdgeTask::query()->find($oldTask->id));
        $this->assertNotNull(EdgeTask::query()->find($pendingTask->id));
        $this->assertNull(TlsOrder::query()->find($oldOrder->id));
        $this->assertNotNull(TlsOrder::query()->find($newestOrder->id));
        $this->assertSame(0, Artisan::call('cdnf:history:prune'));
    }

    public function test_a_rebuild_never_recreates_hours_inside_a_compacted_day(): void
    {
        $domain = $this->domain('rebuild.example');
        $day = CarbonImmutable::now('UTC')->subDays(60)->startOfDay();
        UsageRollup::query()->create(['domain_id' => $domain->id, 'interval_start' => $day, 'interval_end' => $day->addDay(), 'granularity' => 'day', 'requests' => 99, 'status' => 'finalized']);
        Http::fake([config('services.clickhouse.url').'*' => Http::response(json_encode(['domain_id' => $domain->id, 'requests' => 1, 'bytes_in' => 1, 'bytes_out' => 1, 'cache_hits' => 0, 'dns_queries' => 0]))]);
        $store = app(AnalyticsStore::class);

        (new BuildUsageRollups($day->addHours(3)->toIso8601String(), $day->addHours(5)->toIso8601String()))->handle($store);
        (new BuildUsageRollups($day->addDays(5)->toIso8601String(), $day->addDays(5)->addHour()->toIso8601String()))->handle($store);

        $this->assertSame(0, UsageRollup::query()->where('granularity', 'hour')->where('interval_start', '<', $day->addDay())->count());
        $this->assertSame(99, UsageRollup::query()->where('granularity', 'day')->sole()->requests);
        $this->assertSame(1, UsageRollup::query()->where('granularity', 'hour')->count());
    }
}
