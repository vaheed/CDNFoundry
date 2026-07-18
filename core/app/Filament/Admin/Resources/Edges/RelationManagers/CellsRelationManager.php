<?php

namespace App\Filament\Admin\Resources\Edges\RelationManagers;

use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\EdgeCell;
use App\Models\EdgeTask;
use App\Support\EdgeCellAddressData;
use App\Support\NetworkAddress;
use App\Support\PlatformSettings;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CellsRelationManager extends RelationManager
{
    protected static string $relationship = 'cells';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('service_ipv4')->label('Public service IPv4')->ipv4()->required()
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                    if (NetworkAddress::isUnsafe((string) $value)) {
                        $fail('The cell service address must be public unicast.');
                    }
                })
                ->helperText('Address advertised for this pool cell. It must be public, unique, and routed to this runtime listener.'),
            TextInput::make('service_ipv6')->label('Public service IPv6')->ipv6()
                ->required(fn (): bool => $this->getOwnerRecord()->ipv6 !== null)
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                    if (filled($value) && NetworkAddress::isUnsafe((string) $value)) {
                        $fail('The cell service address must be public unicast.');
                    }
                })
                ->helperText('Required when the edge is dual-stack. Non-default pools need addresses distinct from edge management addresses.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->description(fn (): string => $this->edgeReadinessDescription())
            ->columns([
                TextColumn::make('name')->label('Cell')->searchable(),
                TextColumn::make('pool.name')->label('Service pool'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state, EdgeCell $record): string => $record->drained ? 'Drained' : ucfirst($state))
                    ->color(fn (string $state, EdgeCell $record): string => match (true) {
                        $record->drained => 'gray',
                        $state === 'ready' => 'success',
                        $state === 'failed' => 'danger',
                        $state === 'degraded' => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('service_ipv4')->label('Service addresses')->placeholder('Not configured')
                    ->description(fn (EdgeCell $record): string => $record->service_ipv6 ?? 'IPv6 not configured'),
                TextColumn::make('capacity.active_revision')->label('Runtime')->placeholder('Awaiting heartbeat')
                    ->description(fn (EdgeCell $record): string => filled(data_get($record->capacity, 'openresty_version'))
                        ? 'OpenResty '.data_get($record->capacity, 'openresty_version')
                        : 'Runtime version not reported'),
                TextColumn::make('capacity.assigned_domain_count')->label('Workload')->placeholder('Awaiting heartbeat')
                    ->description(fn (EdgeCell $record): string => filled(data_get($record->capacity, 'active_connections'))
                        ? data_get($record->capacity, 'active_connections').' active connections'
                        : 'Connections not reported'),
                TextColumn::make('capacity.cpu_usage')->label('Resources')->placeholder('Awaiting heartbeat')
                    ->description(fn (EdgeCell $record): string => filled(data_get($record->capacity, 'memory_usage'))
                        ? data_get($record->capacity, 'memory_usage').' memory bytes used'
                        : 'Memory use not reported'),
                TextColumn::make('capacity.cache_usage')->label('Storage')->placeholder('Awaiting heartbeat')
                    ->description(fn (EdgeCell $record): string => filled(data_get($record->capacity, 'temporary_storage_usage'))
                        ? data_get($record->capacity, 'temporary_storage_usage').' temporary bytes used'
                        : 'Temporary use not reported'),
                IconColumn::make('drained')->boolean(),
            ])->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data, EdgeCell $record): array => EdgeCellAddressData::validate($record, $data))
                    ->after(function (EdgeCell $record): void {
                        AuditLog::record(auth()->user(), 'edge.cell_addresses_updated', $record, [], request()->ip());
                        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
                        Notification::make()->success()->title('Cell addresses saved')
                            ->body('PostgreSQL desired state was updated and DNS routing reconciliation was queued.')->send();
                    }),
                Action::make('drain')->requiresConfirmation()->visible(fn (EdgeCell $record): bool => ! $record->drained)->action(fn (EdgeCell $record) => self::queue($record, 'drain')),
                Action::make('undrain')->visible(fn (EdgeCell $record): bool => $record->drained)->action(fn (EdgeCell $record) => self::queue($record, 'undrain')),
                Action::make('restart')->color('warning')->requiresConfirmation()->action(fn (EdgeCell $record) => self::queue($record, 'restart')),
            ]);
    }

    private static function queue(EdgeCell $cell, string $action): void
    {
        if ($action !== 'drain' && $cell->service_ipv4 === null) {
            throw ValidationException::withMessages(['service_ipv4' => 'Configure the cell service addresses before making it available.']);
        }
        if ($action !== 'restart') {
            $cell->update(['drained' => $action === 'drain', ...($action === 'undrain' ? ['status' => 'pending'] : [])]);
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
}
