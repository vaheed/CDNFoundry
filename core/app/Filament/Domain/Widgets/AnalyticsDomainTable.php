<?php

namespace App\Filament\Domain\Widgets;

use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Filament\Domain\Widgets\Concerns\UsesAnalyticsFilters;
use App\Support\AnalyticsStore;
use Filament\Widgets\Widget;
use Throwable;

class AnalyticsDomainTable extends Widget
{
    use UsesAnalyticsFilters;

    protected string $view = 'filament.domain.widgets.analytics.domains';

    protected int|string|array $columnSpan = 'full';

    public int $limit = 10;

    public function showMore(): void
    {
        $this->limit = min(AnalyticsStore::MAX_SCOPE_DOMAINS, $this->limit + 20);
    }

    public function showFewer(): void
    {
        $this->limit = 10;
    }

    protected function getViewData(): array
    {
        $scope = $this->analyticsScope();
        if (count($scope['domains']) < 2) {
            return ['visible' => false];
        }
        $domains = collect($scope['domains'])->keyBy('id');
        try {
            $rows = app(AnalyticsStore::class)->aggregate($scope['domains'], $this->analyticsRange(), 'domains');
        } catch (Throwable) {
            return ['visible' => true, 'state' => 'unavailable', 'rows' => [], 'hasMore' => false];
        }
        $rows = collect($rows)->filter(fn (array $row): bool => $domains->has((int) $row['domain_id']))
            ->map(function (array $row) use ($domains): array {
                $domain = $domains->get((int) $row['domain_id']);
                $requests = (int) $row['requests'];

                return [
                    'name' => $domain->name,
                    'url' => DomainResource::getUrl('view', ['record' => $domain], panel: 'app'),
                    'requests' => number_format($requests),
                    'bandwidth' => $this->formatBytes(((int) $row['bytes_in']) + ((int) $row['bytes_out'])),
                    'cache' => $requests === 0 ? '0.0%' : number_format(100 * ((int) $row['cache_hits']) / $requests, 1).'%',
                    'errors' => number_format((int) $row['origin_errors']),
                ];
            })->values();

        return [
            'visible' => true,
            'state' => $rows->isEmpty() ? 'no_data' : 'ready',
            'rows' => $rows->take($this->limit)->all(),
            'hasMore' => $rows->count() > $this->limit,
        ];
    }
}
