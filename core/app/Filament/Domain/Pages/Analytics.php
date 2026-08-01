<?php

namespace App\Filament\Domain\Pages;

use App\Models\Domain;
use App\Models\UsageRollup;
use App\Support\AnalyticsStore;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('selectDomain')
                ->label($this->selectedDomain() === null ? 'Select domain' : 'Switch domain')
                ->icon('heroicon-o-magnifying-glass')
                ->schema([
                    Select::make('domain_id')->label('Assigned domain')->required()->searchable()->native(false)
                        ->getSearchResultsUsing(fn (string $search): array => $this->domainQuery()->where('name', 'like', '%'.$search.'%')->orderBy('name')->limit(50)->pluck('name', 'domains.id')->all())
                        ->getOptionLabelUsing(fn (mixed $value): ?string => $this->domainQuery()->whereKey($value)->value('name')),
                ])
                ->fillForm(fn (): array => ['domain_id' => $this->selectedDomain()?->id])
                ->action(fn (array $data) => redirect(static::getUrl(['domain' => $data['domain_id']], panel: 'app'))),
        ];
    }

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
                'Compression savings (last hour)' => $store->aggregate($domain, $rawRange, 'compression'),
                'DNS activity' => $store->aggregate($domain, $range, 'dns'),
            ];
            $logs = [];
            foreach (['requests', 'dns', 'errors', 'security'] as $stream) {
                $logs[$stream] = array_slice($store->logs($domain, $rawRange, $stream, null)['items'], 0, 10);
            }

            return ['domain' => $domain, 'available' => true, 'meta' => $store->metadata($range), 'summary' => $summary, 'views' => $views, 'logs' => $logs, 'usage' => $this->recentUsage($domain)];
        } catch (Throwable) {
            return [
                'domain' => $domain, 'available' => false, 'summary' => [], 'views' => [], 'logs' => [],
                'usage' => $this->recentUsage($domain),
                'meta' => [
                    'from' => $range['from']->toIso8601String(), 'to' => $range['to']->toIso8601String(),
                    'partial' => true,
                    'finalization_delay_minutes' => app(PlatformSettings::class)->integer('telemetry', 'finalization_delay_minutes'),
                ],
            ];
        }
    }

    private function recentUsage(Domain $domain): array
    {
        return UsageRollup::query()->whereBelongsTo($domain)->where('status', 'finalized')->latest('interval_start')->limit(5)->get()
            ->map(fn (UsageRollup $row): array => [
                'interval' => $row->interval_start->toIso8601String(),
                'requests' => $row->requests,
                'bytes' => $row->bytes_in + $row->bytes_out,
                'dns_queries' => $row->dns_queries,
                'status' => $row->status,
            ])->all();
    }

    private function selectedDomain(): ?Domain
    {
        $requested = request()->integer('domain');

        if ($requested < 1) {
            return null;
        }

        return $this->domainQuery()->whereKey($requested)->first();
    }

    private function domainQuery(): Builder
    {
        return Domain::query()->whereHas('users', fn (Builder $query): Builder => $query->where('users.id', auth()->id()));
    }
}
