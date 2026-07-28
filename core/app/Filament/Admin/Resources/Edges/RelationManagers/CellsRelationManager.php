<?php

namespace App\Filament\Admin\Resources\Edges\RelationManagers;

use App\Actions\DispatchEmergencyMode;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\EdgeTask;
use App\Models\EmergencyMode;
use App\Models\Operation;
use App\Support\PlatformSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CellsRelationManager extends RelationManager
{
    protected static string $relationship = 'cells';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('edge_pool_id')->label('Service pool assignment')->relationship('pool', 'name')
                ->placeholder('Unassigned')->disabled()->dehydrated(false)
                ->helperText('Assignments are managed through service-pool provisioning so every participating edge changes asynchronously and consistently.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->description(fn (): string => $this->edgeReadinessDescription())
            ->columns([
                TextColumn::make('name')->label('Cell')->searchable(),
                TextColumn::make('slot')->label('Slot')->sortable(),
                TextColumn::make('pool.name')->label('Assignment')->placeholder('Unassigned'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state, EdgeCell $record): string => $record->drained ? 'Drained' : ucfirst($state))
                    ->color(fn (string $state, EdgeCell $record): string => match (true) {
                        $record->drained => 'gray',
                        $state === 'ready' => 'success',
                        $state === 'failed' => 'danger',
                        $state === 'degraded' => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('capacity.active_revision')->label('Runtime')->placeholder('Awaiting heartbeat')
                    ->description(fn (EdgeCell $record): string => filled(data_get($record->capacity, 'openresty_version'))
                        ? 'OpenResty '.data_get($record->capacity, 'openresty_version')
                        : 'Runtime version not reported'),
                TextColumn::make('capacity.assigned_domain_count')->label('Workload')->placeholder('Awaiting heartbeat')
                    ->description(fn (EdgeCell $record): string => filled(data_get($record->capacity, 'active_connections'))
                        ? data_get($record->capacity, 'active_connections').' active connections'
                        : 'Connections not reported'),
                TextColumn::make('capacity.cpu_usage')->label('Resources')->placeholder('Awaiting heartbeat')
                    ->formatStateUsing(fn (mixed $state): string => is_numeric($state) ? number_format((float) $state, 2).' CPU' : (string) $state)
                    ->description(fn (EdgeCell $record): string => filled(data_get($record->capacity, 'memory_usage'))
                        ? self::formatBytes(data_get($record->capacity, 'memory_usage')).' / '.self::formatBytes(data_get($record->capacity, 'memory_limit', data_get($record->resource_limits, 'memory_bytes'))).' memory'
                        : 'Memory use not reported'),
                TextColumn::make('capacity.cache_usage')->label('Storage')->placeholder('Awaiting heartbeat')
                    ->formatStateUsing(fn (mixed $state, EdgeCell $record): string => self::formatBytes($state).' / '.self::formatBytes(data_get($record->capacity, 'cache_limit', data_get($record->resource_limits, 'cache_bytes'))).' cache')
                    ->description(fn (EdgeCell $record): string => filled(data_get($record->capacity, 'temporary_storage_usage'))
                        ? self::formatBytes(data_get($record->capacity, 'temporary_storage_usage')).' / '.self::formatBytes(data_get($record->capacity, 'temporary_storage_limit', data_get($record->resource_limits, 'temporary_bytes'))).' temporary'
                        : 'Temporary use not reported'),
                TextColumn::make('http_port')->label('Ports')->description(fn (EdgeCell $record): string => "HTTPS {$record->https_port}; status {$record->status_port}"),
                TextColumn::make('runtime_path')->label('Runtime path')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('drained')->boolean(),
            ])->recordActions([
                Action::make('assignPool')->label('Assign service pool')->icon('heroicon-o-link')
                    ->visible(fn (EdgeCell $record): bool => $record->edge_pool_id === null)
                    ->schema([
                        Select::make('edge_pool_id')->label('Service pool')
                            ->options(fn (): array => EdgePool::query()->where('withdrawn', false)->orderBy('name')->pluck('name', 'id')->all())
                            ->required()->searchable()->preload(),
                    ])->action(function (EdgeCell $record, array $data): void {
                        [$cell, $pool, $operation] = DB::transaction(function () use ($data, $record): array {
                            $cell = EdgeCell::query()->lockForUpdate()->findOrFail($record->id);
                            abort_if($cell->edge_pool_id !== null, 409, 'The cell has already been assigned to a service pool.');
                            abort_if($cell->drained, 409, 'A drained cell cannot participate in a service pool.');
                            $pool = EdgePool::query()->whereKey($data['edge_pool_id'])->where('withdrawn', false)->lockForUpdate()->firstOrFail();
                            $cell->update(['edge_pool_id' => $pool->id, 'status' => 'assigned']);
                            if ($pool->isSimpleAnycast()) {
                                $endpoint = $pool->endpoints()->firstOrCreate(['edge_id' => $cell->edge_id], [
                                    'revision' => 1, 'gateway_state' => 'pending', 'readiness_reason' => 'gateway_not_acknowledged',
                                ]);
                                if ($endpoint->wasRecentlyCreated) {
                                    AuditLog::record(auth()->user(), 'edge.pool_endpoint_created', $endpoint, [
                                        'edge_id' => $cell->edge_id, 'pool_id' => $pool->id, 'source' => 'cell_assignment',
                                    ], request()->ip());
                                }
                            }
                            $pool->update(['revision' => $pool->revision + 1]);
                            $operation = Operation::query()->create([
                                'actor_id' => auth()->id(), 'type' => 'edge.global_reconcile', 'status' => 'pending',
                                'input' => ['pool_id' => $pool->id, 'cell_id' => $cell->id],
                            ]);
                            AuditLog::record(auth()->user(), 'edge.pool_cell_assigned', $cell, [
                                'pool_id' => $pool->id, 'operation_id' => $operation->id,
                            ], request()->ip());

                            return [$cell, $pool, $operation];
                        });
                        ReconcileAllEdgeDomains::dispatch($operation->id)->afterCommit();
                        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
                        Notification::make()->success()->title('Cell assigned to service pool')
                            ->body($pool->isSimpleAnycast()
                                ? "{$cell->name} and its edge participation now inherit {$pool->name}'s Anycast pair. Operation {$operation->id} is reconciling runtime state."
                                : "Operation {$operation->id} is reconciling {$pool->name}; configure its Geo-Unicast endpoint on this edge.")->send();
                    }),
                Action::make('drain')->requiresConfirmation()->visible(fn (EdgeCell $record): bool => ! $record->drained)->action(fn (EdgeCell $record) => self::queue($record, 'drain')),
                Action::make('undrain')->visible(fn (EdgeCell $record): bool => $record->drained)->action(fn (EdgeCell $record) => self::queue($record, 'undrain')),
                Action::make('restart')->color('warning')->requiresConfirmation()->action(fn (EdgeCell $record) => self::queue($record, 'restart')),
                Action::make('emergencyMode')->label('Emergency')->color('danger')->requiresConfirmation()
                    ->visible(fn (EdgeCell $record): bool => ! EmergencyMode::query()->where('target_type', 'cell')->where('target_id', (string) $record->id)->where('active', true)->exists())
                    ->schema([
                        CheckboxList::make('actions')->options(array_combine(config('security.emergency_actions'), config('security.emergency_actions')))->required()->minItems(1),
                        TextInput::make('duration_minutes')->numeric()->minValue(1)->maxValue(config('security.emergency_duration_minutes_maximum')),
                    ])->action(function (EdgeCell $record, array $data): void {
                        [$mode, $operation] = DispatchEmergencyMode::activate('cell', (string) $record->id, $data['actions'], filled($data['duration_minutes'] ?? null) ? (int) $data['duration_minutes'] : null, auth()->user());
                        AuditLog::record(auth()->user(), 'security.emergency_activated', $record, ['mode_id' => $mode->id], request()->ip());
                        Notification::make()->warning()->title('Cell emergency mode queued')->body("Operation {$operation->id} targets only {$record->name}.")->send();
                    }),
                Action::make('clearEmergency')->label('Clear emergency')->color('success')->requiresConfirmation()
                    ->visible(fn (EdgeCell $record): bool => EmergencyMode::query()->where('target_type', 'cell')->where('target_id', (string) $record->id)->where('active', true)->exists())
                    ->action(fn (EdgeCell $record) => DispatchEmergencyMode::deactivateTarget('cell', (string) $record->id, auth()->user())),
            ]);
    }

    private static function queue(EdgeCell $cell, string $action): void
    {
        if ($action !== 'restart') {
            $cell->update(['drained' => $action === 'drain', ...($action === 'undrain' ? ['status' => $cell->edge_pool_id === null ? 'unassigned' : 'assigned'] : [])]);
        }
        $task = EdgeTask::query()->where('edge_id', $cell->edge_id)->where('type', 'cell_'.$action)
            ->where('status', 'pending')->where('payload->cell_id', $cell->id)->first() ?? EdgeTask::query()->create([
                'id' => (string) Str::uuid(), 'edge_id' => $cell->edge_id, 'type' => 'cell_'.$action,
                'status' => 'pending', 'payload' => ['cell_id' => $cell->id, 'cell_name' => $cell->name],
            ]);
        AuditLog::record(auth()->user(), 'edge.cell_'.$action, $cell, ['task_id' => $task->id], request()->ip());
        if ($action !== 'restart') {
            ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
        }
        $edge = $cell->edge;
        $freshSeconds = app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds');
        $connected = $edge->registered_at !== null && $edge->last_heartbeat_at?->gte(now()->subSeconds($freshSeconds));
        Notification::make()
            ->title($connected ? 'Cell action queued' : 'Desired cell action saved')
            ->body($connected
                ? "Task {$task->id} is ready for the edge agent."
                : "Task {$task->id} remains pending until the edge enrolls and sends a fresh heartbeat.")
            ->color($connected ? 'info' : 'warning')
            ->send();
    }

    private function edgeReadinessDescription(): string
    {
        $edge = $this->getOwnerRecord();
        if ($edge->registered_at === null) {
            return 'Awaiting agent enrollment. Address edits are saved as desired state; runtime capacity appears after the first heartbeat.';
        }
        if ($edge->last_heartbeat_at === null) {
            return 'Agent identity is enrolled, but no heartbeat has arrived. Runtime capacity is not available yet.';
        }
        $freshSeconds = app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds');
        if ($edge->last_heartbeat_at->lt(now()->subSeconds($freshSeconds))) {
            return 'The last agent heartbeat is stale. Desired changes remain saved and tasks wait for reconnection.';
        }

        return 'Agent connected. Capacity values come from the latest authenticated runtime heartbeat.';
    }

    private static function formatBytes(mixed $bytes): string
    {
        if (! is_numeric($bytes)) {
            return 'Not reported';
        }

        $value = max(0, (float) $bytes);
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? number_format($value, 0) : number_format($value, $value < 10 ? 2 : 1)).' '.$units[$unit];
    }
}
