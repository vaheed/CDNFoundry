<?php

namespace App\Filament\Domain\Pages;

use App\Models\Domain;
use App\Models\UsageRollup;
use App\Support\AnalyticsStore;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class Analytics extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Analytics and logs';

    protected static string|\UnitEnum|null $navigationGroup = 'Observe';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.domain.pages.analytics';

    public function getStateProperty(): array
    {
        $domain = $this->selectedDomain();
        if ($domain === null) {
            return ['domain' => null, 'available' => true, 'views' => [], 'meta' => [], 'logs' => [], 'usage' => []];
        }
        $store = app(AnalyticsStore::class);
        $to = CarbonImmutable::now('UTC');
        $range = ['from' => $to->subDay(), 'to' => $to, 'raw' => false];
        $rawRange = ['from' => $to->subHour(), 'to' => $to, 'raw' => true];
        try {
            $summary = $store->summary($domain, $range);
            $views = [
                'Request and bandwidth timeseries' => $store->aggregate($domain, $range, 'timeseries'),
                'Status codes' => $store->aggregate($domain, $range, 'status-codes'),
                'Cache ratio' => $store->aggregate($domain, $range, 'cache'),
                'Countries and continents' => $store->aggregate($domain, $range, 'countries'),
                'Hostnames' => $store->aggregate($domain, $range, 'hostnames'),
                'Top URLs (last hour)' => $store->topUrls($domain, $rawRange),
                'Origin health and latency' => $store->aggregate($domain, $range, 'origin'),
                'Edge distribution' => $store->aggregate($domain, $range, 'edges'),
                'DNS activity' => $store->aggregate($domain, $range, 'dns'),
            ];
            $logs = [];
            foreach (['requests', 'dns', 'errors', 'security'] as $stream) {
                $logs[$stream] = array_slice($store->logs($domain, $rawRange, $stream, null)['items'], 0, 10);
            }

            return ['domain' => $domain, 'available' => true, 'meta' => $store->metadata($range), 'summary' => $summary, 'views' => $views, 'logs' => $logs, 'usage' => $this->recentUsage($domain)];
        } catch (Throwable) {
            return ['domain' => $domain, 'available' => false, 'summary' => [], 'views' => [], 'logs' => [], 'usage' => $this->recentUsage($domain), 'meta' => ['from' => $range['from']->toIso8601String(), 'to' => $range['to']->toIso8601String(), 'partial' => true]];
        }
    }

    private function recentUsage(Domain $domain): array
    {
        return UsageRollup::query()->whereBelongsTo($domain)->latest('interval_start')->limit(20)->get()
            ->map(fn (UsageRollup $row): array => [
                'interval' => $row->interval_start->toIso8601String(),
                'requests' => $row->requests,
                'bytes' => $row->bytes_in + $row->bytes_out,
                'dns_queries' => $row->dns_queries,
                'status' => $row->status,
            ])->all();
    }

    public function getDomainsProperty()
    {
        return $this->domainQuery()->orderBy('name')->get(['domains.id', 'domains.name', 'domains.display_name']);
    }

    private function selectedDomain(): ?Domain
    {
        $requested = request()->integer('domain');

        return $this->domainQuery()->when($requested > 0, fn (Builder $query): Builder => $query->whereKey($requested))->orderBy('domains.id')->first();
    }

    private function domainQuery(): Builder
    {
        return Domain::query()->whereHas('users', fn (Builder $query): Builder => $query->where('users.id', auth()->id()));
    }
}
