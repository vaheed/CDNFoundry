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
use App\Support\FilamentHelp;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Infrastructure';

    protected static ?string $navigationLabel = 'Service pools';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(FilamentHelp::label('Name', 'Creating a pool does not attach any edge or consume any cell. Assign both explicitly after creation.'))->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('kind')->label(FilamentHelp::label('Kind', 'Use Shared for normal Anycast service. Reserved is controlled capacity, Dedicated is exceptional single-tenant isolation, and Quarantine is only for risky traffic. Kind does not select addresses or edges.'))->options(['shared' => 'Shared', 'reserved' => 'Reserved', 'quarantine' => 'Quarantine', 'dedicated' => 'Dedicated'])->required(),
            Select::make('cache_profile')->label(FilamentHelp::label('Cache profile', 'Sets bounded per-cell disk, temporary space, minimum-free, inactive, object, and admission ceilings. Changing it reconciles assigned domains.'))->options(['small' => 'Small', 'standard' => 'Standard', 'large' => 'Large', 'streaming' => 'Streaming'])->required()->default('standard'),
            Select::make('compression_profile')->label(FilamentHelp::label('Compression profile', 'Standard safely delivers Gzip. Maximum savings also enables Brotli with a lower concurrency ceiling and is limited to reserved or dedicated pools.'))->options(['off' => 'Off', 'standard' => 'Standard', 'maximum_savings' => 'Maximum savings'])->required()->default('standard')
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    if ($value === 'maximum_savings' && ! in_array($get('kind'), ['reserved', 'dedicated'], true)) {
                        $fail('Maximum-savings compression is limited to reserved and dedicated pools.');
                    }
                }),
            Toggle::make('waf_capable')
                ->label(FilamentHelp::label('Offer managed WAF protection', 'Enable this only when the pool cells run the already-qualified CDNFoundry WAF image. This does not enable WAF for every domain.'))
                ->helperText('After this is enabled, each domain independently chooses Off, Observe, Recommended, or High sensitivity.')
                ->default(false)->live(),
            TextInput::make('waf_runtime_version')
                ->label(FilamentHelp::label('Managed WAF release', 'Filled automatically from the pinned CDNFoundry image. Operators do not need to find or type a version.'))
                ->readOnly()->dehydrated()
                ->maxLength(80)->nullable()->visible(fn (Get $get): bool => (bool) $get('waf_capable'))->required(fn (Get $get): bool => (bool) $get('waf_capable')),
            Select::make('routing_mode')->label(FilamentHelp::label('Routing mode', 'Simple Anycast only binds and publishes the shared pair. CDNFoundry never announces or withdraws BGP routes; the network operator/provider owns routing.'))->options(['geo_unicast' => 'Geo-Unicast', 'simple_anycast' => 'Simple Anycast'])->required()->default('geo_unicast')->live(),
            TextInput::make('anycast_ipv4')->label(FilamentHelp::label('Anycast IPv4', 'One distinct Anycast address pair belongs to one pool. Create a second pool only for a second distinct pair.'))->ipv4()->nullable()->visible(fn (Get $get): bool => $get('routing_mode') === 'simple_anycast'),
            TextInput::make('anycast_ipv6')->label(FilamentHelp::label('Anycast IPv6', 'At least one address is required. Every explicitly attached edge binds this same pair after local readiness is acknowledged.'))->ipv6()->nullable()->visible(fn (Get $get): bool => $get('routing_mode') === 'simple_anycast'),
            TextInput::make('minimum_ready_cells')->numeric()->minValue(1)->maxValue(32)->required()->default(1),
            TextInput::make('replicas_per_edge')->label(FilamentHelp::label('Replicas per edge', 'Normal placement is one stable cell per edge. Replication is bounded and only valid for reserved or dedicated pools.'))->numeric()->minValue(1)->maxValue(3)->required()->default(1)
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                    if ((int) $value > 1 && ! in_array($get('kind'), ['reserved', 'dedicated'], true)) {
                        $fail('Replicated placement is limited to reserved and dedicated pools.');
                    }
                }),
            TextInput::make('maximum_domains_per_cell')->numeric()->minValue(1)->maxValue(100000)->required()->default(20000),
        ]);
    }

    public static function table(Table $table): Table
    {
        $settings = PlatformDnsSetting::query()->find(1);

        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('kind')->badge(),
            TextColumn::make('cache_profile')->label('Cache')->badge(),
            TextColumn::make('compression_profile')->label('Compression')->badge(),
            IconColumn::make('waf_capable')->label('Managed WAF')->boolean(),
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
                        EmergencyMode::query()->where('target_type', 'pool')->where('target_id', (string) $record->id)->where('active', true)->exists() => 'End maintenance before deleting this pool.',
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
            Action::make('maintenance')->label('Maintenance')->color('warning')->requiresConfirmation()
                ->visible(fn (EdgePool $record): bool => $record->enabled && ! $record->withdrawn
                    && ! EmergencyMode::query()->where('target_type', 'pool')->where('target_id', (string) $record->id)->where('active', true)->exists())
                ->schema([
                    TextInput::make('duration_minutes')->label('Automatic expiry (minutes)')->numeric()->default(30)->minValue(1)->maxValue(config('security.emergency_duration_minutes_maximum'))->required(),
                ])->action(function (EdgePool $record, array $data): void {
                    [$mode, $operation] = DispatchEmergencyMode::activate('pool', (string) $record->id, ['return_maintenance_response'], (int) $data['duration_minutes'], auth()->user());
                    AuditLog::record(auth()->user(), 'security.pool_maintenance_started', $record, ['mode_id' => $mode->id, 'expires_at' => $mode->expires_at], request()->ip());
                    Notification::make()->warning()->title('Pool maintenance queued')->body("Operation {$operation->id} will return HTTP 503 from this pool's cells until the control is cleared or expires.")->send();
                }),
            Action::make('endMaintenance')->label('End maintenance')->color('success')->requiresConfirmation()
                ->visible(fn (EdgePool $record): bool => EmergencyMode::query()->where('target_type', 'pool')->where('target_id', (string) $record->id)->where('active', true)->exists())
                ->action(function (EdgePool $record): void {
                    $operation = DispatchEmergencyMode::deactivateTarget('pool', (string) $record->id, auth()->user());
                    AuditLog::record(auth()->user(), 'security.pool_maintenance_ended', $record, ['operation_id' => $operation->id], request()->ip());
                }),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListEdgePools::route('/'), 'create' => CreateEdgePool::route('/create'), 'edit' => EditEdgePool::route('/{record}/edit')];
    }
}
