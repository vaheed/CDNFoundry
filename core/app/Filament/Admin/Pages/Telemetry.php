<?php

namespace App\Filament\Admin\Pages;

use App\Http\Controllers\Admin\UsageController;
use App\Models\Domain;
use App\Models\UsageRollup;
use App\Support\AnalyticsStore;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Http;
use Throwable;

class Telemetry extends Page
{
    /** @var array<string, int> */
    public array $logLimits = [];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static ?string $navigationLabel = 'Telemetry and usage';

    protected static string|\UnitEnum|null $navigationGroup = 'Observe';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.pages.telemetry';

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
        $to = CarbonImmutable::now('UTC');
        $range = ['from' => $to->subDay(), 'to' => $to, 'raw' => false];
        $rawRange = ['from' => $to->subHour(), 'to' => $to, 'raw' => true];
        $state = [
            'available' => false,
            'meta' => [
                'from' => $range['from']->toIso8601String(), 'to' => $range['to']->toIso8601String(),
                'partial' => true,
                'finalization_delay_minutes' => app(PlatformSettings::class)->integer('telemetry', 'finalization_delay_minutes'),
            ],
            'summary' => [],
            'traffic' => [],
            'dns' => [],
            'compression' => [],
            'logs' => ['errors' => [], 'security' => [], 'requests' => []],
            'buffer' => $this->bufferStatus(),
            'usage' => $this->recentUsage(),
        ];
        try {
            $state['meta'] = $store->metadata($range);
            $state['summary'] = $store->summary(null, $range);
            $state['traffic'] = $store->aggregate(null, $range, 'traffic');
            $state['dns'] = $store->aggregate(null, $range, 'dns');
            $state['compression'] = $store->aggregate(null, $rawRange, 'compression');
            foreach (array_keys($state['logs']) as $stream) {
                $result = $store->logs(null, $rawRange, $stream, null);
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

    public function showFewerLogs(string $stream): void
    {
        abort_unless(in_array($stream, ['errors', 'security', 'requests'], true), 404);
        $this->logLimits[$stream] = 5;
    }

    private function recentUsage(): array
    {
        return UsageRollup::query()->with('domain:id,name')->where('status', 'finalized')->latest('interval_start')->limit(5)->get()
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
