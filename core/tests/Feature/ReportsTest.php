<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\GeneratedReport;
use App\Models\UsageRollup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private function seedUsage(): array
    {
        config(['services.clickhouse.url' => 'http://clickhouse.test:8123', 'services.prometheus.url' => 'http://prometheus.test:9090']);
        $first = Domain::query()->create(['name' => 'one.example', 'display_name' => 'One', 'revision' => 1, 'lifecycle_state' => 'active']);
        $second = Domain::query()->create(['name' => 'two.example', 'display_name' => 'Two', 'revision' => 1, 'lifecycle_state' => 'active']);
        foreach ([[$first, 40, 1000], [$second, 2, 55]] as [$domain, $dns, $requests]) {
            UsageRollup::query()->create(['domain_id' => $domain->id, 'interval_start' => '2026-10-10 10:00:00+00', 'interval_end' => '2026-10-10 11:00:00+00', 'granularity' => 'hour', 'requests' => $requests, 'dns_queries' => $dns, 'status' => 'finalized']);
        }
        $rows = [
            ['domain_id' => (string) $first->id, 'bytes' => '9000000', 'p95_bps' => 1200.5, 'request_count' => '1000'],
            ['domain_id' => (string) $second->id, 'bytes' => '500000', 'p95_bps' => 80.0, 'request_count' => '55'],
        ];
        Http::fake(function ($request) use ($rows) {
            $body = (string) $request->body();
            if (str_contains($request->url(), 'prometheus.test')) {
                return Http::response(['data' => ['result' => []]]);
            }
            if (str_contains($body, 'GROUP BY region')) {
                return Http::response("{\"region\":\"eu\",\"bytes\":\"9500000\",\"request_count\":\"1055\"}\n");
            }
            if (str_contains($body, 'GROUP BY domain_id ORDER BY bytes')) {
                return Http::response(implode("\n", array_map('json_encode', $rows))."\n");
            }

            return Http::response('');
        });

        return [$first, $second];
    }

    public function test_the_monthly_usage_report_totals_equal_the_rollups_and_are_recorded_once(): void
    {
        Storage::fake('local');
        $this->seedUsage();

        $this->artisan('cdnf:reports:build', ['kind' => 'usage', '--period' => '2026-10'])->assertSuccessful();
        $this->artisan('cdnf:reports:build', ['kind' => 'usage', '--period' => '2026-10'])->assertSuccessful();

        $this->assertSame(2, GeneratedReport::query()->count(), 'a second run replaces the report instead of adding one');
        $csv = Storage::disk('local')->get('reports/usage-2026-10.csv');
        $this->assertStringContainsString('one.example', $csv);
        $this->assertStringContainsString('9000000,1200.5,1000,40', $csv);
        // The totals row is the sum of the rows above it, and equals the dashboard's figures for the month.
        $this->assertStringContainsString("bytes_sent,requests,dns_queries\n9500000,1055,42", $csv);
        $pdf = Storage::disk('local')->get('reports/usage-2026-10.pdf');
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertStringContainsString('one.example', $pdf);
        $this->assertSame(hash('sha256', $csv), GeneratedReport::query()->where('format', 'csv')->value('checksum_sha256'));
    }

    public function test_the_report_runs_the_same_query_as_the_usage_dashboard(): void
    {
        $dashboard = json_decode((string) file_get_contents(base_path('../docker/grafana/dashboards/60-business-capacity/cdnf-usage-billing.json')), true, flags: JSON_THROW_ON_ERROR);
        $queries = collect($dashboard['panels'])->flatMap(fn (array $panel): array => collect($panel['targets'] ?? [])->pluck('rawSql')->all())->implode("\n");
        $source = (string) file_get_contents(app_path('Support/AnalyticsStore.php'));
        foreach (['FROM cdnf.edge_usage_5m', 'quantile(0.95)(bps)', '8 * sum(bytes_sent) / 300', 'sum(bytes_sent) AS sent', 'sum(requests) AS reqs'] as $fragment) {
            $this->assertStringContainsString($fragment, $queries, 'dashboard: '.$fragment);
            $this->assertStringContainsString($fragment, $source, 'report: '.$fragment);
        }
    }

    public function test_the_operations_report_builds_when_prometheus_and_clickhouse_answer_nothing(): void
    {
        Storage::fake('local');
        $this->seedUsage();
        Http::fake(['*' => Http::response('', 500)]);

        $this->artisan('cdnf:reports:build', ['kind' => 'operations', '--period' => '2026-W40'])->assertSuccessful();

        $csv = Storage::disk('local')->get('reports/operations-2026-W40.csv');
        foreach (['SLO results (30 days)', 'Incidents', 'Top errors by domain', 'Top attacked domains', 'Capacity forecast'] as $section) {
            $this->assertStringContainsString($section, $csv);
        }
    }

    public function test_an_unfinished_period_is_refused(): void
    {
        $this->artisan('cdnf:reports:build', ['kind' => 'usage', '--period' => now()->format('Y-m')])->assertFailed();
    }

    public function test_only_administrators_list_and_download_reports(): void
    {
        Storage::fake('local');
        $this->seedUsage();
        $this->artisan('cdnf:reports:build', ['kind' => 'usage', '--period' => '2026-10']);
        $admin = User::factory()->create(['type' => 'admin']);
        $user = User::factory()->create();
        $report = GeneratedReport::query()->where('format', 'csv')->firstOrFail();

        $this->actingAs($user)->getJson('/api/admin/reports')->assertForbidden();
        $this->actingAs($user)->get("/api/admin/reports/{$report->id}/download")->assertForbidden();

        $list = $this->actingAs($admin)->getJson('/api/admin/reports')->assertOk();
        $this->assertCount(2, $list->json('data.data'));
        $this->assertArrayHasKey('next_cursor', $list->json('data'));
        $download = $this->actingAs($admin)->get("/api/admin/reports/{$report->id}/download")->assertOk();
        $this->assertStringContainsString('text/csv', $download->headers->get('Content-Type'));
        $this->assertSame($report->checksum_sha256, $download->headers->get('X-Content-SHA256'));
    }
}
