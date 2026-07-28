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
                ->helperText('Creating a pool does not attach any edge or consume any cell. Assign both explicitly after creation.'),
            Select::make('kind')->options(['shared' => 'Shared', 'reserved' => 'Reserved', 'quarantine' => 'Quarantine', 'dedicated' => 'Dedicated'])->required()
                ->helperText('Use Shared for normal Anycast service. Reserved is controlled capacity, Dedicated is exceptional single-tenant isolation, and Quarantine is only for risky traffic. Kind does not select addresses or edges.'),
            Select::make('routing_mode')->options(['geo_unicast' => 'Geo-Unicast', 'simple_anycast' => 'Simple Anycast'])->required()->default('geo_unicast')->live()
                ->helperText('Simple Anycast only binds and publishes the shared pair. CDNFoundry never announces or withdraws BGP routes; the network operator/provider owns routing.'),
            TextInput::make('anycast_ipv4')->label('Anycast IPv4')->ipv4()->nullable()->visible(fn (Get $get): bool => $get('routing_mode') === 'simple_anycast')
                ->helperText('One distinct Anycast address pair belongs to one pool. Create a second pool only for a second distinct pair.'),
            TextInput::make('anycast_ipv6')->label('Anycast IPv6')->ipv6()->nullable()->visible(fn (Get $get): bool => $get('routing_mode') === 'simple_anycast')
                ->helperText('At least one address is required. Every explicitly attached edge binds this same pair after local readiness is acknowledged.'),
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
            TextColumn::make('routing_mode')->label('Routing')->badge(),
            TextColumn::make('routing_status')->label('Route state')->state(fn (EdgePool $record): string => $record->routingStatus())->badge(),
            TextColumn::make('service_pair')->label('Service pair')->state(fn (EdgePool $record): ?string => $record->isSimpleAnycast()
                ? collect([$record->anycast_ipv4, $record->anycast_ipv6])->filter()->join(' / ') : null)->placeholder('Per-edge Geo-Unicast')->wrap(),
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
            Action::make('enable')->visible(fn (EdgePool $record): bool => ! $record->enabled)->action(function (EdgePool $record): void {
                $endpoints = $record->endpoints()->with('edge')->get();
                $incomplete = $endpoints->isEmpty() || $endpoints->contains(fn ($endpoint): bool => (! $record->isSimpleAnycast() && ! filled($endpoint->ipv4) && ! filled($endpoint->ipv6))
                    || $endpoint->edge->cells()->where('edge_pool_id', $record->id)->count() < $record->minimum_ready_cells);
                if ($incomplete) {
                    Notification::make()->danger()->title('Attach intended edges and satisfy their minimum cell readiness first')->send();

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
            Action::make('delete')->label('Delete')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()
                ->visible(fn (EdgePool $record): bool => ! $record->enabled)
                ->action(function (EdgePool $record): void {
                    $blockedBy = match (true) {
                        $record->cells()->exists() => 'Unassign every cell before deleting this pool.',
                        $record->endpoints()->exists() => 'Remove every Geo-Unicast endpoint before deleting this pool.',
                        DomainEdgePlacement::query()->where('active_pool_id', $record->id)->orWhere('target_pool_id', $record->id)->exists() => 'Move every domain away from this pool before deleting it.',
                        default => null,
                    };
                    if ($blockedBy !== null) {
                        Notification::make()->danger()->title('Service pool cannot be deleted')->body($blockedBy)->send();

                        return;
                    }
                    AuditLog::record(auth()->user(), 'edge.pool_deleted', $record, ['kind' => $record->kind], request()->ip());
                    $record->delete();
                    Notification::make()->success()->title('Service pool deleted')->send();
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
