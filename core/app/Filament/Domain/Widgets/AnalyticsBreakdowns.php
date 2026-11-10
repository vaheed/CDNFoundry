<?php

namespace App\Filament\Domain\Widgets;

use App\Filament\Domain\Widgets\Concerns\UsesAnalyticsFilters;
use App\Support\AnalyticsStore;
use App\Support\GeoVocabulary;
use Filament\Widgets\Widget;
use Throwable;

/** One card with a tab per breakdown, so the page shows one bounded table instead of many long lists. */
class AnalyticsBreakdowns extends Widget
{
    use UsesAnalyticsFilters;

    public const TABS = ['status' => 'Status codes', 'countries' => 'Countries', 'hostnames' => 'Hostnames', 'paths' => 'Paths', 'cache' => 'Cache', 'dns' => 'DNS'];

    private const RCODES = ['NOERROR' => 'No error', 'NXDOMAIN' => 'Name does not exist', 'SERVFAIL' => 'Server failure', 'REFUSED' => 'Refused', 'FORMERR' => 'Format error', 'NOTIMP' => 'Not implemented'];

    protected string $view = 'filament.domain.widgets.analytics.breakdowns';

    protected int|string|array $columnSpan = 'full';

    public string $tab = 'status';

    public int $limit = 10;

    public function selectTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, self::TABS), 404);
        $this->tab = $tab;
        $this->limit = 10;
    }

    public function showMore(): void
    {
        $this->limit = min(100, $this->limit + 20);
    }

    public function showFewer(): void
    {
        $this->limit = 10;
    }

    protected function getViewData(): array
    {
        $tab = array_key_exists($this->tab, self::TABS) ? $this->tab : 'status';
        $base = ['tabs' => self::TABS, 'active' => $tab, 'columns' => [], 'rows' => [], 'hasMore' => false, 'note' => null];
        $scope = $this->analyticsScope();
        if ($scope['domains'] === []) {
            return [...$base, 'state' => 'no_data'];
        }
        $range = $this->analyticsRange();
        $raw = $this->analyticsRawRange();
        $store = app(AnalyticsStore::class);
        try {
            [$columns, $rows, $note] = match ($tab) {
                'status' => $this->shares(['label' => 'Status', 'requests' => 'Requests', 'share' => 'Share'], $store->aggregate($scope['domains'], $range, 'status-codes'), fn (array $row): string => (string) $row['status']),
                'cache' => $this->shares(['label' => 'Cache result', 'requests' => 'Requests', 'share' => 'Share'], $store->aggregate($scope['domains'], $range, 'cache'), fn (array $row): string => ucfirst(strtolower((string) ($row['cache_status'] ?: 'none')))),
                'countries' => [['label' => 'Country', 'secondary' => 'Continent', 'requests' => 'Requests', 'bandwidth' => 'Bandwidth'], array_map(fn (array $row): array => [
                    'label' => GeoVocabulary::countryName($row['country'] ?? null), 'secondary' => GeoVocabulary::continentName($row['continent'] ?? null),
                    'requests' => number_format((int) $row['requests']), 'bandwidth' => $this->formatBytes($row['bytes_out'] ?? 0),
                ], $store->aggregate($scope['domains'], $range, 'countries')), null],
                'hostnames' => [['label' => 'Hostname', 'requests' => 'Requests', 'bandwidth' => 'Bandwidth'], array_map(fn (array $row): array => [
                    'label' => (string) ($row['hostname'] ?: 'Unknown'), 'requests' => number_format((int) $row['requests']), 'bandwidth' => $this->formatBytes($row['bytes_out'] ?? 0),
                ], $store->aggregate($scope['domains'], $range, 'hostnames')), null],
                'paths' => [['label' => 'Path', 'requests' => 'Requests', 'bandwidth' => 'Bandwidth'], $raw === null ? [] : array_map(fn (array $row): array => [
                    'label' => (string) $row['path'], 'requests' => number_format((int) $row['requests']), 'bandwidth' => $this->formatBytes($row['bytes_out'] ?? 0),
                ], $store->topUrls($scope['domains'], $raw)), 'Paths cover the last 24 hours of the range; request details are kept for 7 days.'],
                'dns' => [['label' => 'Type', 'secondary' => 'Response', 'requests' => 'Queries'], array_map(fn (array $row): array => [
                    'label' => (string) ($row['qtype'] ?? 'Unknown'), 'secondary' => self::RCODES[strtoupper((string) ($row['rcode'] ?? ''))] ?? ucfirst(strtolower((string) ($row['rcode'] ?? 'unknown'))),
                    'requests' => number_format((int) $row['queries']),
                ], $store->aggregate($scope['domains'], $range, 'dns')), null],
            };
        } catch (Throwable) {
            return [...$base, 'state' => 'unavailable'];
        }

        return [
            ...$base,
            'state' => $rows === [] ? 'no_data' : 'ready',
            'columns' => $columns,
            'rows' => array_slice($rows, 0, $this->limit),
            'hasMore' => count($rows) > $this->limit,
            'note' => $note,
        ];
    }

    /**
     * @param  array<string, string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function shares(array $columns, array $rows, callable $label): array
    {
        $total = max(1, array_sum(array_map(fn (array $row): int => (int) $row['requests'], $rows)));

        return [$columns, array_map(fn (array $row): array => [
            'label' => $label($row), 'requests' => number_format((int) $row['requests']),
            'share' => number_format(100 * ((int) $row['requests']) / $total, 1).'%',
        ], $rows), null];
    }
}
