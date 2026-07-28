<?php

namespace App\Filament\Admin\Resources\EdgePools;

use App\Actions\DispatchEmergencyMode;
use App\Filament\Admin\Resources\EdgePools\Pages\CreateEdgePool;
use App\Filament\Admin\Resources\EdgePools\Pages\EditEdgePool;
use App\Filament\Admin\Resources\EdgePools\Pages\ListEdgePools;
use App\Jobs\ProvisionEdgePoolCells;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\DomainEdgePlacement;
use App\Models\EdgePool;
use App\Models\EmergencyMode;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Support\EdgeRoutingCompiler;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EdgePoolResource extends Resource
{
    protected static ?string $model = EdgePool::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|\UnitEnum|null $navigationGroup = 'Edge network';

    protected static ?string $navigationLabel = 'Service pools';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true)
                ->helperText('Stable pool identity; participating cells retain their independent slot identities.'),
            Select::make('kind')->options(['shared' => 'Shared', 'reserved' => 'Reserved', 'quarantine' => 'Quarantine', 'dedicated' => 'Dedicated'])->required()
                ->helperText('Shared is the normal default. Quarantine isolates risky/noisy domains. Dedicated is an explicit exceptional allocation, never one per domain.'),
            TextInput::make('minimum_ready_cells')->numeric()->minValue(1)->maxValue(32)->required()->default(1),
            TextInput::make('replicas_per_edge')->numeric()->minValue(1)->maxValue(3)->required()->default(1)
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    if ((int) $value > 1 && ! in_array($get('kind'), ['reserved', 'dedicated'], true)) {
                        $fail('Replicated placement is limited to reserved and dedicated pools.');
                    }
                })
                ->helperText('Normal placement is one stable cell per edge. Replication is bounded and only valid for reserved or dedicated pools.'),
            TextInput::make('maximum_domains_per_cell')->numeric()->minValue(1)->maxValue(100000)->required()->default(20000),
        ]);
    }

    public static function table(Table $table): Table
    {
        $settings = PlatformDnsSetting::query()->find(1);

        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('kind')->badge(),
            IconColumn::make('enabled')->boolean(),
            IconColumn::make('withdrawn')->label('Emergency withdrawal')->boolean(),
            TextColumn::make('routing_target')->label('DNS routing target')
                ->state(fn (EdgePool $record): ?string => $settings === null ? null : EdgeRoutingCompiler::poolHostname($settings, $record))
                ->copyable()->placeholder('Configure System DNS identity')->wrap(),
            TextColumn::make('revision')->sortable(),
            TextColumn::make('cells_count')->counts('cells')->label('Edge cells'),
            TextColumn::make('minimum_ready_cells')->label('Min ready'),
            TextColumn::make('replicas_per_edge')->label('Replicas/edge'),
            TextColumn::make('updated_at')->since()->sortable(),
        ])->recordActions([
            Action::make('reconcileCells')->label('Reconcile cells')->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->action(function (EdgePool $record): void {
                    $operation = Operation::query()->create([
                        'actor_id' => auth()->id(), 'type' => 'edge.pool_provision', 'status' => 'pending',
                        'input' => ['pool_id' => $record->id],
                    ]);
                    AuditLog::record(auth()->user(), 'edge.pool_provision_requested', $record, ['operation_id' => $operation->id], request()->ip());
                    ProvisionEdgePoolCells::dispatch($record->id, $operation->id);
                    Notification::make()->info()->title('Cell reconciliation queued')
                        ->body("Operation {$operation->id} will assign one existing unassigned slot on each missing edge.")->send();
                }),
            Action::make('enable')->visible(fn (EdgePool $record): bool => ! $record->enabled)->action(function (EdgePool $record): void {
                $incomplete = $record->cells()->whereHas('edge', fn ($query) => $query->where('enabled', true))->exists()
                    && $record->endpoints()->count() < $record->cells()->distinct('edge_id')->count('edge_id');
                if ($incomplete) {
                    Notification::make()->danger()->title('Create one pool endpoint on every participating edge first')->send();

                    return;
                }
                $record->update(['enabled' => true, 'revision' => $record->revision + 1]);
                AuditLog::record(auth()->user(), 'edge.pool_enabled', $record, ['revision' => $record->revision], request()->ip());
                ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
            }),
            Action::make('disable')->color('danger')->requiresConfirmation()->visible(fn (EdgePool $record): bool => $record->enabled)->action(function (EdgePool $record): void {
                if (DomainEdgePlacement::query()->where('active_pool_id', $record->id)->orWhere('target_pool_id', $record->id)->exists()) {
                    Notification::make()->danger()->title('Move all active and target placements before disabling this pool')->send();

                    return;
                }
                $record->update(['enabled' => false, 'revision' => $record->revision + 1]);
                AuditLog::record(auth()->user(), 'edge.pool_disabled', $record, ['revision' => $record->revision], request()->ip());
                ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
            }),
            Action::make('withdraw')->color('danger')->requiresConfirmation()->visible(fn (EdgePool $record): bool => ! $record->withdrawn)->action(function (EdgePool $record): void {
                $record->update(['withdrawn' => true, 'revision' => $record->revision + 1]);
                AuditLog::record(auth()->user(), 'edge.pool_withdrawn', $record, ['revision' => $record->revision], request()->ip());
                ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
            }),
            Action::make('restore')->color('success')->visible(fn (EdgePool $record): bool => $record->withdrawn)->action(function (EdgePool $record): void {
                $record->update(['withdrawn' => false, 'revision' => $record->revision + 1]);
                AuditLog::record(auth()->user(), 'edge.pool_restored', $record, ['revision' => $record->revision], request()->ip());
                ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
            }),
            Action::make('emergencyMode')->label('Emergency')->color('danger')->requiresConfirmation()
                ->visible(fn (EdgePool $record): bool => ! EmergencyMode::query()->where('target_type', 'pool')->where('target_id', (string) $record->id)->where('active', true)->exists())
                ->schema([
                    CheckboxList::make('actions')->options(array_combine(config('security.emergency_actions'), config('security.emergency_actions')))->required()->minItems(1),
                    TextInput::make('duration_minutes')->numeric()->minValue(1)->maxValue(config('security.emergency_duration_minutes_maximum')),
                ])->action(function (EdgePool $record, array $data): void {
                    [$mode, $operation] = DispatchEmergencyMode::activate('pool', (string) $record->id, $data['actions'], filled($data['duration_minutes'] ?? null) ? (int) $data['duration_minutes'] : null, auth()->user());
                    AuditLog::record(auth()->user(), 'security.emergency_activated', $record, ['mode_id' => $mode->id], request()->ip());
                    Notification::make()->warning()->title('Pool emergency mode queued')->body("Operation {$operation->id} targets the equivalent cell on each edge.")->send();
                }),
            Action::make('clearEmergency')->label('Clear emergency')->color('success')->requiresConfirmation()
                ->visible(fn (EdgePool $record): bool => EmergencyMode::query()->where('target_type', 'pool')->where('target_id', (string) $record->id)->where('active', true)->exists())
                ->action(fn (EdgePool $record) => DispatchEmergencyMode::deactivateTarget('pool', (string) $record->id, auth()->user())),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListEdgePools::route('/'), 'create' => CreateEdgePool::route('/create'), 'edit' => EditEdgePool::route('/{record}/edit')];
    }
}
