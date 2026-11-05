<?php

namespace App\Support\Reports;

use App\Models\EmergencyMode;
use App\Models\Operation;
use App\Support\AnalyticsStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/** The weekly operations report: SLO results, incidents, top errors, top attacked domains, and the capacity forecast. */
final class OperationsReport
{
    public function __construct(private readonly AnalyticsStore $analytics) {}

    public function forWeek(CarbonImmutable $week): Report
    {
        $from = $week->utc()->startOfWeek();
        $to = $from->addWeek();

        return new Report('CDNFoundry operations report '.$from->format('o-\WW'), [
            ['title' => 'SLO results (30 days)', 'headers' => ['slo', 'bad_ratio', 'budget_remaining'], 'rows' => $this->slos()],
            ['title' => 'Incidents', 'headers' => ['when', 'kind', 'detail'], 'rows' => $this->incidents($from, $to)],
            ['title' => 'Top errors by domain', 'headers' => ['hostname', 'server_errors'], 'rows' => $this->safely(fn (): array => array_map(fn (array $row): array => [$row['hostname'], (int) $row['errors']], $this->analytics->topErrorHostnames($from, $to)))],
            ['title' => 'Top attacked domains', 'headers' => ['hostname', 'blocked_requests'], 'rows' => $this->safely(fn (): array => array_map(fn (array $row): array => [$row['hostname'], (int) $row['blocked']], $this->analytics->topAttackedHostnames($from, $to)))],
            ['title' => 'Capacity forecast (cache fill in 30 days)', 'headers' => ['pop', 'cell', 'forecast_fill_ratio'], 'rows' => $this->forecast()],
        ]);
    }

    /** @return list<list<int|float|string>> */
    private function slos(): array
    {
        $rows = [];
        foreach ($this->prometheus('label_replace({__name__=~"slo:.*:bad_ratio_30d"}, "slo", "$1", "__name__", "slo:(.*):bad_ratio_30d")') as $series) {
            $slo = $series['metric']['slo'] ?? 'unknown';
            $budget = $this->prometheus("slo:{$slo}:budget_remaining")[0]['value'][1] ?? null;
            $rows[] = [$slo, round((float) $series['value'][1], 6), $budget === null ? 'unavailable' : round((float) $budget, 4)];
        }

        return $rows;
    }

    /** @return list<list<int|float|string>> */
    private function incidents(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = Operation::query()->where('status', 'failed')->where('updated_at', '>=', $from)->where('updated_at', '<', $to)->orderBy('updated_at')->limit(50)
            ->get(['type', 'error', 'updated_at'])->map(fn (Operation $operation): array => [$operation->updated_at->toIso8601String(), 'failed operation', $operation->type.': '.mb_substr((string) $operation->error, 0, 120)])->all();
        $modes = EmergencyMode::query()->where('created_at', '>=', $from)->where('created_at', '<', $to)->orderBy('created_at')->limit(50)
            ->get(['target_type', 'target_id', 'created_at'])->map(fn (EmergencyMode $mode): array => [$mode->created_at->toIso8601String(), 'emergency mode', $mode->target_type.' '.$mode->target_id])->all();

        return array_values(array_merge($rows, $modes));
    }

    /** @return list<list<int|float|string>> */
    private function forecast(): array
    {
        $rows = [];
        foreach ($this->prometheus('sort_desc(predict_linear(cdnfoundry_cell_capacity_ratio{resource="cache"}[7d], 30 * 86400))') as $series) {
            $rows[] = [$series['metric']['pop'] ?? 'unknown', $series['metric']['cell'] ?? 'unknown', round((float) $series['value'][1], 3)];
        }

        return array_slice($rows, 0, 20);
    }

    /** @return list<array{metric: array<string, string>, value: array{0: float|int, 1: string}}> */
    private function prometheus(string $expression): array
    {
        try {
            $response = Http::timeout(10)->get(rtrim((string) config('services.prometheus.url'), '/').'/api/v1/query', ['query' => $expression]);

            return $response->successful() ? ($response->json('data.result') ?? []) : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  callable(): list<list<int|float|string>>  $query
     * @return list<list<int|float|string>>
     */
    private function safely(callable $query): array
    {
        try {
            return $query();
        } catch (Throwable) {
            return [];
        }
    }
}
