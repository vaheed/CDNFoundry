<?php

namespace App\Filament\Domain\Pages;

use App\Models\Domain;
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
            return ['domain' => null, 'available' => true, 'views' => [], 'meta' => []];
        }
        $store = app(AnalyticsStore::class);
        $to = CarbonImmutable::now('UTC');
        $range = ['from' => $to->subDay(), 'to' => $to, 'raw' => false];
        $rawRange = ['from' => $to->subHour(), 'to' => $to, 'raw' => true];
        try {
            return ['domain' => $domain, 'available' => true, 'meta' => $store->metadata($range), 'views' => [
                'Summary' => [$store->summary($domain, $range)],
                'Request and bandwidth timeseries' => $store->aggregate($domain, $range, 'timeseries'),
                'Status codes' => $store->aggregate($domain, $range, 'status-codes'),
                'Cache ratio' => $store->aggregate($domain, $range, 'cache'),
                'Countries and continents' => $store->aggregate($domain, $range, 'countries'),
                'Hostnames' => $store->aggregate($domain, $range, 'hostnames'),
                'Top URLs (last hour)' => $store->topUrls($domain, $rawRange),
                'Origin health and latency' => $store->aggregate($domain, $range, 'origin'),
                'Edge distribution' => $store->aggregate($domain, $range, 'edges'),
                'DNS activity' => $store->aggregate($domain, $range, 'dns'),
            ]];
        } catch (Throwable) {
            return ['domain' => $domain, 'available' => false, 'views' => [], 'meta' => ['from' => $range['from']->toIso8601String(), 'to' => $range['to']->toIso8601String(), 'partial' => true]];
        }
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
