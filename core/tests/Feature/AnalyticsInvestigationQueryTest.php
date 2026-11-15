<?php

namespace Tests\Feature;

use App\Support\AnalyticsStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The analytics focus views were empty ("Analytics unavailable") because ClickHouse runs with prefer_column_name_to_alias: an
 * `ORDER BY requests` over `sum(requests) AS requests` names the stored column, which is not aggregated, and the query fails.
 * Real ClickHouse behavior is qualified by tests/e2e/analytics_investigation_views.py; this guards the SQL the store sends.
 */
class AnalyticsInvestigationQueryTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function views(): array
    {
        return ['traffic' => ['traffic'], 'cache' => ['cache'], 'origin' => ['origin'], 'dns' => ['dns']];
    }

    #[DataProvider('views')]
    public function test_a_query_never_orders_by_a_name_that_is_both_a_stored_column_and_an_aggregate_alias(string $view): void
    {
        config(['services.clickhouse.url' => 'http://clickhouse.test']);
        Http::fake(['clickhouse.test*' => Http::response('', 200)]);
        $now = CarbonImmutable::now('UTC');

        app(AnalyticsStore::class)->investigation(null, ['from' => $now->subDay(), 'to' => $now], ['from' => $now->subDay(), 'to' => $now, 'raw' => true], $view, '5xx');

        $queries = Http::recorded()->map(fn (array $pair): string => $pair[0]->body())->all();
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            if (str_contains($sql, 'cdnf.edge_hourly') || str_contains($sql, 'cdnf.dns_hourly')) {
                $this->assertDoesNotMatchRegularExpression('/ORDER BY (requests|queries|bytes_out)\b/', $sql, $sql);
            }
        }
    }
}
