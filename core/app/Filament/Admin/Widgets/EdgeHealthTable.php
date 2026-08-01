<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Filament\Admin\Widgets\Concerns\UsesOpsDashboardContext;
use App\Models\Edge;
use App\Support\PlatformSettings;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class EdgeHealthTable extends TableWidget
{
    use UsesOpsDashboardContext;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = ['default' => 12, 'xl' => 7];

    public function table(Table $table): Table
    {
        $context = $this->opsContext();
        $query = Edge::query()->with('cells');
        if (! $context->isValid()) {
            $query->whereRaw('1 = 0');
        } else {
            $query->when($context->edgeId, fn (Builder $builder, string $edgeId): Builder => $builder->whereKey($edgeId));
            $query->when($context->domainId, fn (Builder $builder, int $domainId): Builder => $builder->whereHas('domainCells', fn (Builder $cells): Builder => $cells->where('domain_id', $domainId)));
        }

        return $table
            ->heading('Edge health and capacity')
            ->description('Runtime health and the highest reported cell resource usage. An idle edge can still have memory or storage pressure.')
            ->query($query)
            ->poll('10s')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->url(fn (Edge $record): string => EdgeResource::getUrl('view', ['record' => $record], panel: 'admin')),
                TextColumn::make('country_code')->label('Region')->formatStateUsing(fn (string $state, Edge $record): string => $state.' / '.$record->continent_code)->sortable(),
                TextColumn::make('operational_state')->label('State')->state(fn (Edge $record): string => $this->edgeState($record))->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Healthy' => 'success', 'Drained', 'Disabled' => 'gray', 'Stale' => 'warning', default => 'danger',
                    }),
                TextColumn::make('capacity_percent')->label('Peak resource')->state(fn (Edge $record): string => $this->capacitySummary($record)['label'])->badge()
                    ->tooltip(fn (Edge $record): string => $this->capacitySummary($record)['detail'])
                    ->color(fn (string $state): string => $this->capacityTone($state)),
                TextColumn::make('last_heartbeat_at')->label('Heartbeat')->since()->dateTimeTooltip()->placeholder('Never')->sortable(),
                TextColumn::make('active_sequence')->label('Revision')->numeric()->sortable(),
                TextColumn::make('capacity.last_rejection.reason')->label('Current issue')->placeholder('None')->limit(38)->tooltip(fn (Edge $record): ?string => data_get($record->capacity, 'last_rejection.reason')),
            ])
            ->filters([
                TernaryFilter::make('enabled'),
                TernaryFilter::make('drained'),
            ])
            ->headerActions([
                Action::make('allEdges')->label('All edges')->icon('heroicon-o-arrow-top-right-on-square')->url(EdgeResource::getUrl(panel: 'admin')),
            ])
            ->defaultSort('name')
            ->paginationPageOptions([10, 25, 50]);
    }

    private function edgeState(Edge $edge): string
    {
        if (! $edge->enabled) {
            return 'Disabled';
        }
        if ($edge->drained) {
            return 'Drained';
        }
        if ($edge->last_heartbeat_at === null || $edge->last_heartbeat_at->lt(now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds')))) {
            return 'Stale';
        }

        return data_get($edge->capacity, 'listener_ready') === true ? 'Healthy' : 'Degraded';
    }

    /** @return array{label: string, detail: string} */
    private function capacitySummary(Edge $edge): array
    {
        $peak = null;
        foreach ($edge->cells as $cell) {
            foreach ([
                ['Memory', 'memory_usage', 'memory_limit', 'memory_bytes'],
                ['Cache', 'cache_usage', 'cache_limit', 'cache_bytes'],
                ['Temporary', 'temporary_storage_usage', 'temporary_storage_limit', 'temporary_bytes'],
                ['Connections', 'active_connections', 'connection_limit', 'connections'],
            ] as [$resource, $used, $limit, $configuredLimit]) {
                $usedValue = data_get($cell->capacity, $used);
                $limitValue = data_get($cell->capacity, $limit, data_get($cell->resource_limits, $configuredLimit));
                if (is_numeric($usedValue) && is_numeric($limitValue) && (float) $limitValue > 0) {
                    $ratio = ((float) $usedValue / (float) $limitValue) * 100;
                    if ($peak === null || $ratio > $peak['ratio']) {
                        $peak = compact('resource', 'usedValue', 'limitValue', 'ratio') + ['cell' => $cell->name];
                    }
                }
            }
        }

        if ($peak === null) {
            return ['label' => 'Unavailable', 'detail' => 'No cell has reported a usable resource limit.'];
        }

        $format = fn (float $value): string => $peak['resource'] === 'Connections'
            ? number_format($value)
            : number_format($value / 1048576, 1).' MiB';

        return [
            'label' => $peak['resource'].' '.number_format($peak['ratio'], 1).'%',
            'detail' => $peak['cell'].': '.$format((float) $peak['usedValue']).' used of '.$format((float) $peak['limitValue']).'. This is resource utilization, not customer traffic load.',
        ];
    }

    private function capacityTone(string $label): string
    {
        preg_match('/([0-9]+(?:\.[0-9]+)?)%$/', $label, $match);
        $ratio = isset($match[1]) ? (float) $match[1] : null;

        return match (true) {
            $ratio === null => 'gray',
            $ratio >= 90 => 'danger',
            $ratio >= 80 => 'warning',
            default => 'success',
        };
    }
}
