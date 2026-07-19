<?php

namespace App\Filament\Admin\Resources\EdgePools;

use App\Actions\DispatchEmergencyMode;
use App\Filament\Admin\Resources\EdgePools\Pages\CreateEdgePool;
use App\Filament\Admin\Resources\EdgePools\Pages\EditEdgePool;
use App\Filament\Admin\Resources\EdgePools\Pages\ListEdgePools;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\DomainEdgePlacement;
use App\Models\EdgePool;
use App\Models\EmergencyMode;
use App\Models\PlatformDnsSetting;
use App\Support\EdgeRoutingCompiler;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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
                ->helperText('Stable runtime name shared by one equivalent OpenResty cell at each participating edge.'),
            Select::make('kind')->options(['shared' => 'Shared', 'quarantine' => 'Quarantine', 'dedicated' => 'Dedicated'])->required()
                ->helperText('Shared is the normal default. Quarantine isolates risky/noisy domains. Dedicated is an explicit exceptional allocation, never one per domain.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        $settings = PlatformDnsSetting::query()->find(1);

        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('kind')->badge(),
            IconColumn::make('enabled')->boolean(),
            IconColumn::make('withdrawn')->boolean(),
            TextColumn::make('routing_target')->label('DNS routing target')
                ->state(fn (EdgePool $record): ?string => $settings === null ? null : EdgeRoutingCompiler::poolHostname($settings, $record))
                ->copyable()->placeholder('Configure System DNS identity')->wrap(),
            TextColumn::make('revision')->sortable(),
            TextColumn::make('cells_count')->counts('cells')->label('Edge cells'),
            TextColumn::make('updated_at')->since()->sortable(),
        ])->recordActions([
            Action::make('enable')->visible(fn (EdgePool $record): bool => ! $record->enabled)->action(function (EdgePool $record): void {
                $incomplete = $record->cells()->whereHas('edge', fn ($query) => $query->where('enabled', true))->whereNull('service_ipv4')->exists()
                    || $record->cells()->whereNull('service_ipv6')->whereHas('edge', fn ($query) => $query->where('enabled', true)->whereNotNull('ipv6'))->exists();
                if ($incomplete) {
                    Notification::make()->danger()->title('Configure every enabled edge cell service address first')->send();

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
