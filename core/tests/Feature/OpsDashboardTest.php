<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\AdminDashboard;
use App\Filament\Admin\Resources\Operations\OperationResource;
use App\Filament\Admin\Widgets\DnsHealthWidget;
use App\Filament\Admin\Widgets\EdgeHealthTable;
use App\Filament\Admin\Widgets\ServiceStatusBanner;
use App\Filament\Admin\Widgets\TrafficOverviewChart;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\Operation;
use App\Models\User;
use App\Ops\Data\OpsDashboardContext;
use App\Ops\Services\MetricComparisonService;
use App\Ops\Services\OpsDashboardService;
use App\Ops\Support\MetricFormatter;
use Carbon\CarbonImmutable;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OpsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_normalizes_complete_hourly_ranges_and_authorizes_filters(): void
    {
        CarbonImmutable::setTestNow('2026-08-01T12:34:56Z');
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create(['name' => 'ops.example.test', 'display_name' => 'Ops']);
        $edge = Edge::query()->create(['name' => 'ops-edge', 'country_code' => 'IR', 'continent_code' => 'AS']);

        $context = OpsDashboardContext::fromFilters([
            'range' => '24h', 'compare' => 'false', 'domain_id' => (string) $domain->id, 'edge_id' => $edge->id,
        ], $admin);

        $this->assertTrue($context->isValid());
        $this->assertSame('2026-07-31T12:00:00+00:00', $context->from->toIso8601String());
        $this->assertSame('2026-08-01T12:00:00+00:00', $context->to->toIso8601String());
        $this->assertFalse($context->compare);
        $this->assertSame($domain->id, $context->domainId);
        $this->assertSame($edge->id, $context->edgeId);

        $invalid = OpsDashboardContext::fromFilters(['range' => 'forever', 'domain_id' => 999999, 'edge_id' => 'not-a-uuid'], $admin);
        $this->assertFalse($invalid->isValid());
        $this->assertSame('24h', $invalid->range);
        $this->assertNull($invalid->domainId);
        $this->assertNull($invalid->edgeId);
        $this->assertCount(3, $invalid->errors);
    }

    public function test_metric_comparisons_and_formatting_do_not_invent_a_zero_baseline(): void
    {
        $service = app(MetricComparisonService::class);

        $this->assertSame(['delta' => 5.0, 'percent' => null, 'direction' => 'up'], $service->compare(5, 0));
        $this->assertSame('down', $service->compare(5, 10)['direction']);
        $this->assertSame(-50.0, $service->compare(5, 10)['percent']);
        $this->assertSame('No comparable baseline', app(MetricFormatter::class)->deltaPercent(null));
        $this->assertSame('1.0 KiB', app(MetricFormatter::class)->bytes(1024));
        $this->assertSame('12.5%', app(MetricFormatter::class)->percent(0.125));
        $this->assertSame('Unavailable', app(MetricFormatter::class)->milliseconds(null));
    }

    public function test_traffic_snapshot_is_bounded_cached_and_uses_real_aggregate_fields(): void
    {
        CarbonImmutable::setTestNow('2026-08-01T12:34:56Z');
        Cache::flush();
        $admin = User::factory()->admin()->create();
        $responses = [
            json_encode([
                'bucket' => '2026-08-01 11:00:00', 'requests' => 100, 'bytes_in' => 10, 'bytes_out' => 2048,
                'cache_hits' => 80, 'cache_misses' => 15, 'cache_bypass' => 5, 'cache_stale' => 0,
                'cache_bytes_out' => 1600, 'requests_4xx' => 4, 'requests_5xx' => 1, 'origin_errors' => 2,
                'origin_latency_sum_ms' => 500, 'origin_latency_samples' => 10,
            ], JSON_THROW_ON_ERROR),
        ];
        Http::fake([config('services.clickhouse.url').'*' => Http::response(implode("\n", $responses))]);
        $context = OpsDashboardContext::fromFilters(['range' => '1h', 'compare' => true], $admin);

        $first = app(OpsDashboardService::class)->traffic($context);
        $second = app(OpsDashboardService::class)->traffic($context);

        $this->assertTrue($first['available']);
        $this->assertSame(100.0, $first['summary']['requests']);
        $this->assertSame(0.8, $first['summary']['cache_ratio']);
        $this->assertSame(50.0, $first['summary']['origin_average_latency_ms']);
        $this->assertSame($first, $second);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'FROM cdnf.edge_hourly')
            && str_contains($request->body(), 'LIMIT 169')
            && str_contains($request->body(), 'origin_latency_samples'));
    }

    public function test_invalid_shared_url_never_falls_back_to_unlabelled_global_metrics(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin?filters[range]=invalid&filters[domain_id]=999999')
            ->assertOk()
            ->assertSee('Service condition is unknown')
            ->assertSee('Invalid investigation filter');
    }

    public function test_dashboard_is_full_width_native_filament_and_widgets_poll_independently(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake();

        $page = new AdminDashboard;
        $this->assertSame(Width::Full, $page->getMaxContentWidth());
        $this->assertCount(11, $page->getWidgets());
        $this->assertSame(['default' => 1, 'xl' => 12], $page->getColumns());
        $this->assertSame('full', $page->getFiltersForm()->getComponents()[0]->getColumnSpan('default'));
        $range = $page->getFiltersForm()->getComponents()[0]->getChildSchema()->getComponents()[0];
        $this->assertSame('24h', $range->getDefaultState());
        $this->assertArrayNotHasKey('15m', $range->getOptions());
        $telemetryView = file_get_contents(resource_path('views/filament/admin/pages/telemetry.blade.php'));
        $this->assertIsString($telemetryView);
        $this->assertLessThan(strpos($telemetryView, '@livewire(\\App\\Filament\\Admin\\Widgets\\TrafficOverviewChart'), strpos($telemetryView, 'class="cdn-stat-grid"'));

        Livewire::actingAs($admin)->test(ServiceStatusBanner::class, ['pageFilters' => ['range' => '1h', 'compare' => true]])
            ->assertSee('Polling active')
            ->assertSeeHtml('wire:poll.10s');

        parse_str((string) parse_url(OperationResource::failedUrl(), PHP_URL_QUERY), $operationQuery);
        $this->assertSame(['failed'], data_get($operationQuery, 'filters.status.values'));

        Operation::query()->create(['type' => 'dns.zone_reconcile', 'status' => 'failed', 'input' => [], 'error' => 'visible failed operation']);
        Operation::query()->create(['type' => 'dns.zone_reconcile', 'status' => 'succeeded', 'input' => [], 'error' => 'hidden successful operation']);
        $this->actingAs($admin)->get(OperationResource::failedUrl())->assertOk()
            ->assertSee('visible failed operation')
            ->assertDontSee('hidden successful operation');
    }

    public function test_chart_distinguishes_no_data_from_query_failure(): void
    {
        $admin = User::factory()->admin()->create();
        $filters = ['range' => '1h', 'compare' => false];

        Cache::flush();
        Http::fakeSequence()
            ->push('', 200)
            ->push('down', 503);
        Livewire::actingAs($admin)->test(TrafficOverviewChart::class, ['pageFilters' => $filters])
            ->assertSee('No matching data')
            ->assertSeeHtml('wire:poll.15s');

        Cache::flush();
        Livewire::actingAs($admin)->test(TrafficOverviewChart::class, ['pageFilters' => $filters])
            ->assertSee('Data unavailable')
            ->assertSee('Traffic aggregates could not be queried');
    }

    public function test_chart_marks_stale_data_and_preserves_context_in_its_drilldown(): void
    {
        CarbonImmutable::setTestNow('2026-08-01T12:34:56Z');
        Cache::flush();
        $admin = User::factory()->admin()->create();
        $domain = Domain::query()->create(['name' => 'stale-ops.example.test', 'display_name' => 'Stale']);
        Http::fake([config('services.clickhouse.url').'*' => Http::response(json_encode([
            'bucket' => '2026-08-01 00:00:00', 'requests' => 1, 'bytes_out' => 100,
        ], JSON_THROW_ON_ERROR))]);

        Livewire::actingAs($admin)->test(TrafficOverviewChart::class, ['pageFilters' => [
            'range' => '24h', 'compare' => false, 'domain_id' => $domain->id,
        ]])
            ->assertDontSee('Data is stale')
            ->assertSee('Open detailed analytics')
            ->assertSee('range=24h', false)
            ->assertSee('domain='.$domain->id, false);

        $this->actingAs($admin)->get('/admin/telemetry')->assertOk()
            ->assertSeeInOrder(['Telemetry status', 'Data is stale', 'Traffic and egress'])
            ->assertSeeHtml('cdn-widget-state--compact');

        $columns = Livewire::actingAs($admin)->test(EdgeHealthTable::class, ['pageFilters' => ['range' => '24h', 'compare' => false]])
            ->instance()->getTable()->getColumns();
        $this->assertArrayNotHasKey('pools', $columns);
    }

    public function test_domain_analytics_waits_for_an_authorized_search_selection(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $assigned = Domain::query()->create(['name' => 'assigned-ops.example.test', 'display_name' => 'Assigned']);
        $private = Domain::query()->create(['name' => 'private-ops.example.test', 'display_name' => 'Private']);
        $assigned->users()->attach($user);
        $private->users()->attach($other);
        Http::fake();

        $this->actingAs($user)->get('/app/analytics')->assertOk()
            ->assertSee('Select an assigned domain')
            ->assertDontSee($private->name);
        Http::assertNothingSent();

        $this->actingAs($user)->get('/app/analytics?domain='.$private->id)->assertOk()
            ->assertSee('Select an assigned domain')
            ->assertDontSee($private->name);
        Http::assertNothingSent();
    }

    public function test_telemetry_drilldown_validates_and_applies_status_family_to_raw_logs(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake([config('services.clickhouse.url').'*' => Http::response('')]);

        $this->actingAs($admin)->get('/admin/telemetry?view=origin&status_family=5xx')
            ->assertOk()
            ->assertSee('Analytics focus')
            ->assertSee('HTTP status')
            ->assertSee('All statuses')
            ->assertSee('Origin focus')
            ->assertSee('HTTP 5xx log focus');
        Http::assertSent(fn ($request): bool => str_contains($request->body(), "event_type = 'request'")
            && str_contains($request->body(), 'status >= 500 AND status < 600'));

        $this->actingAs($admin)->get('/admin/telemetry?status_family=2xx')
            ->assertOk()
            ->assertSee('The requested HTTP status family is invalid.');

        $this->actingAs($admin)->get('/admin/telemetry?range=7d')
            ->assertOk()
            ->assertSee('Recent logs')
            ->assertSee('latest 24 hours');
    }

    public function test_dns_health_keeps_cluster_diagnostics_visible_without_aggregate_rows(): void
    {
        $admin = User::factory()->admin()->create();
        Http::fake([config('services.clickhouse.url').'*' => Http::response('')]);

        Livewire::actingAs($admin)->test(DnsHealthWidget::class, ['pageFilters' => ['range' => '24h', 'compare' => false]])
            ->assertSee('No DNS traffic in this range')
            ->assertSee('Healthy clusters')
            ->assertSee('DNS clusters')
            ->assertSeeHtml('cdn-widget-state--compact');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
