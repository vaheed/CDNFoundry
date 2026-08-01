<?php

namespace App\Filament\Admin\Pages;

use App\Http\Controllers\Admin\UsageController;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\UsageRollup;
use App\Ops\Data\OpsDashboardContext;
use App\Support\AnalyticsStore;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Url;
use Throwable;

class Telemetry extends Page
{
    #[Url]
    public string $range = '24h';

    #[Url]
    public ?string $domain = null;

    #[Url]
    public ?string $edge = null;

    #[Url(as: 'view')]
    public ?string $investigationView = null;

    #[Url(as: 'status_family')]
    public ?string $statusFamily = null;

    /** @var array<string, int> */
    public array $logLimits = [];

    public int $compressionLimit = 5;

    public int $trafficLimit = 12;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Traffic and telemetry';

    protected static string|\UnitEnum|null $navigationGroup = 'Observe';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.pages.telemetry';

    /** @return array{domains: array<int, string>, edges: array<string, string>} */
    public function getFilterOptionsProperty(): array
    {
        return [
            'domains' => Domain::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all(),
            'edges' => Edge::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rebuildUsage')
                ->label('Rebuild usage')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->schema([
                    Select::make('domain_id')->label('Domain (optional)')
                        ->options(fn (): array => Domain::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all())
                        ->searchable(),
                    DateTimePicker::make('from')->label('From (UTC)')->timezone('UTC')->seconds(false)->required(),
                    DateTimePicker::make('to')->label('To (UTC)')->timezone('UTC')->seconds(false)->required()->after('from'),
                ])
                ->fillForm(fn (): array => [
                    'from' => CarbonImmutable::now('UTC')->subHour()->startOfHour(),
                    'to' => CarbonImmutable::now('UTC')->startOfHour(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    request()->merge([
                        'domain_id' => $data['domain_id'] ?? null,
                        'from' => CarbonImmutable::parse($data['from'], 'UTC')->startOfHour()->toIso8601String(),
                        'to' => CarbonImmutable::parse($data['to'], 'UTC')->startOfHour()->toIso8601String(),
                    ]);
                    $response = app(UsageController::class)->rebuild(request());
                    $operation = $response->getData(true)['data'];
                    Notification::make()->info()->title('Usage rebuild queued')
                        ->body("Operation {$operation['operation_id']} will rebuild complete UTC hours without changing serving behavior.")->send();
                }),
        ];
    }

    public function getStateProperty(): array
    {
        $store = app(AnalyticsStore::class);
        $context = OpsDashboardContext::fromFilters([
            'range' => $this->range,
            'compare' => true,
            'domain_id' => $this->domain,
            'edge_id' => $this->edge,
        ], auth()->user());
        $range = $context->rangeValue();
        $rawTo = CarbonImmutable::now('UTC');
        $rawHours = min(24, max(1, (int) ceil(OpsDashboardContext::RANGES[$context->range] / 3600)));
        $rawRange = ['from' => $rawTo->subHours($rawHours), 'to' => $rawTo, 'raw' => true];
        $domain = $context->domainId === null ? null : Domain::query()->find($context->domainId);
        $investigationView = in_array($this->investigationView, ['traffic', 'cache', 'origin', 'dns'], true) ? $this->investigationView : null;
        $statusFamily = in_array($this->statusFamily, ['4xx', '5xx'], true) ? $this->statusFamily : null;
        $filterErrors = $context->errors;
        if (filled($this->investigationView) && $investigationView === null) {
            $filterErrors[] = 'The requested analytics focus is invalid.';
        }
        if (filled($this->statusFamily) && $statusFamily === null) {
            $filterErrors[] = 'The requested HTTP status family is invalid.';
        }
        $state = [
            'available' => false,
            'meta' => [
                'from' => $range['from']->toIso8601String(), 'to' => $range['to']->toIso8601String(),
                'partial' => true,
                'raw_window_hours' => $rawHours,
                'finalization_delay_minutes' => app(PlatformSettings::class)->integer('telemetry', 'finalization_delay_minutes'),
            ],
            'summary' => [],
            'traffic' => ['items' => [], 'has_more' => false, 'expanded' => false],
            'dns' => [],
            'compression' => ['items' => [], 'has_more' => false, 'expanded' => false],
            'logs' => ['errors' => [], 'security' => [], 'requests' => []],
            'buffer' => $this->bufferStatus(),
            'usage' => $this->recentUsage($domain),
            'filters' => [
                'range' => $context->range, 'domain' => $domain, 'edge' => $context->edgeId,
                'view' => $investigationView, 'status_family' => $statusFamily, 'errors' => $filterErrors,
            ],
        ];
        if (! $context->isValid() || count($filterErrors) > count($context->errors)) {
            return $state;
        }
        try {
            $state['meta'] = $store->metadata($range);
            $state['meta']['raw_window_hours'] = $rawHours;
            $state['summary'] = $store->summary($domain, $range);
            $traffic = $store->aggregate($domain, $range, 'traffic');
            $latestTrafficBucket = collect($traffic)->pluck('bucket')->filter()->sort()->last();
            $state['meta']['aggregate_source_timestamp'] = is_string($latestTrafficBucket)
                ? CarbonImmutable::parse($latestTrafficBucket, 'UTC')->toIso8601String()
                : null;
            $aggregateAge = is_string($latestTrafficBucket)
                ? abs(CarbonImmutable::now('UTC')->diffInSeconds(CarbonImmutable::parse($latestTrafficBucket, 'UTC')))
                : null;
            $state['meta']['aggregate_state'] = match (true) {
                $aggregateAge === null => 'no_data',
                $aggregateAge > 14400 => 'stale',
                $aggregateAge > 7200 => 'delayed',
                default => 'fresh',
            };
            $state['traffic'] = [
                'items' => array_slice($traffic, 0, $this->trafficLimit),
                'has_more' => count($traffic) > $this->trafficLimit,
                'expanded' => $this->trafficLimit > 12,
            ];
            $state['dns'] = $store->aggregate($domain, $range, 'dns');
            $compression = $store->aggregate($domain, $rawRange, 'compression');
            $state['compression'] = [
                'items' => array_slice($compression, 0, $this->compressionLimit),
                'has_more' => count($compression) > $this->compressionLimit,
                'expanded' => $this->compressionLimit > 5,
            ];
            foreach (array_keys($state['logs']) as $stream) {
                $result = $store->logs($domain, $rawRange, $stream, null, $stream === 'security' ? null : $statusFamily);
                $limit = $this->logLimits[$stream] ?? 5;
                $state['logs'][$stream] = [
                    'items' => array_slice($result['items'], 0, $limit),
                    'has_more' => count($result['items']) > $limit || $result['next_cursor'] !== null,
                    'expanded' => $limit > 5,
                ];
            }
            $state['available'] = true;
        } catch (Throwable) {
            // PostgreSQL usage and Vector health remain independently useful.
        }

        return $state;
    }

    public function showMoreLogs(string $stream): void
    {
        abort_unless(in_array($stream, ['errors', 'security', 'requests'], true), 404);
        $this->logLimits[$stream] = min(100, ($this->logLimits[$stream] ?? 5) + 20);
    }

    public function showMoreCompression(): void
    {
        $this->compressionLimit = min(1000, $this->compressionLimit + 20);
    }

    public function showMoreTraffic(): void
    {
        $this->trafficLimit = 24;
    }

    public function showFewerTraffic(): void
    {
        $this->trafficLimit = 12;
    }

    public function showFewerCompression(): void
    {
        $this->compressionLimit = 5;
    }

    public function showFewerLogs(string $stream): void
    {
        abort_unless(in_array($stream, ['errors', 'security', 'requests'], true), 404);
        $this->logLimits[$stream] = 5;
    }

    private function recentUsage(?Domain $domain): array
    {
        return UsageRollup::query()->with('domain:id,name')->where('status', 'finalized')
            ->when($domain, fn ($query) => $query->whereBelongsTo($domain))
            ->latest('interval_start')->limit(5)->get()
            ->map(fn (UsageRollup $row): array => [
                'domain' => $row->domain?->name ?? "Domain #{$row->domain_id}",
                'interval' => $row->interval_start->toIso8601String(),
                'requests' => $row->requests,
                'bytes' => $row->bytes_in + $row->bytes_out,
                'dns_queries' => $row->dns_queries,
                'status' => $row->status,
            ])->all();
    }

    private function bufferStatus(): array
    {
        try {
            $metrics = Http::connectTimeout(1)->timeout(2)->get('http://vector:9598/metrics')->throw()->body();
            preg_match_all('/^(vector_buffer_(?:byte_size|events)|vector_component_(?:discarded_events_total|errors_total))(\{[^}]*\})?\s+([0-9.eE+-]+)(?:\s+\d+)?$/m', $metrics, $matches, PREG_SET_ORDER);
            $values = [
                'vector_buffer_byte_size' => 0.0,
                'vector_buffer_events' => 0.0,
                'vector_component_discarded_events_total' => 0.0,
                'vector_component_errors_total' => 0.0,
            ];
            $components = [];
            foreach ($matches as $match) {
                $value = (float) $match[3];
                $values[$match[1]] += $value;
                if ($value > 0 && str_starts_with($match[1], 'vector_component_') && preg_match('/component_id="([^"]+)"/', $match[2], $componentMatch)) {
                    $component = $componentMatch[1];
                    $components[$component][$match[1]] = ($components[$component][$match[1]] ?? 0) + $value;
                }
            }

            ksort($components);

            return ['available' => true, 'metrics' => $values, 'components' => $components];
        } catch (Throwable) {
            return ['available' => false, 'metrics' => [], 'components' => []];
        }
    }
}
