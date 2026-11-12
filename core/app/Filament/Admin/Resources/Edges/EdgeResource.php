<?php

namespace App\Filament\Admin\Resources\Edges;

use App\Filament\Admin\Resources\Edges\Pages\CreateEdge;
use App\Filament\Admin\Resources\Edges\Pages\EditEdge;
use App\Filament\Admin\Resources\Edges\Pages\ListEdges;
use App\Filament\Admin\Resources\Edges\Pages\ViewEdge;
use App\Filament\Admin\Resources\Edges\RelationManagers\CellsRelationManager;
use App\Filament\Admin\Resources\Edges\RelationManagers\PoolEndpointsRelationManager;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\Edge;
use App\Support\FilamentHelp;
use App\Support\GeoVocabulary;
use App\Support\RuntimeVersions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EdgeResource extends Resource
{
    protected static ?string $model = Edge::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-globe-hemisphere-west';

    protected static string|\UnitEnum|null $navigationGroup = 'Infrastructure';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true),
            TextInput::make('cell_slot_count')->label(FilamentHelp::label('Cell slots', 'OpenResty slots created during edge installation. Production Compose runs exactly eight cells per PoP, so the count is fixed.'))->numeric()->integer()->required()->minValue(Edge::CELL_SLOT_COUNT)->maxValue(Edge::CELL_SLOT_COUNT)->default(Edge::CELL_SLOT_COUNT)->disabled()->dehydrated(),
            Select::make('country_code')->label('Country')->options(array_combine(GeoVocabulary::countries(), GeoVocabulary::countries()))->searchable()->required(),
            Select::make('continent_code')->label('Continent')->options(array_combine(GeoVocabulary::CONTINENTS, GeoVocabulary::CONTINENTS))->required()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Live edge status')
                ->columnSpanFull()
                ->poll('5s')
                ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                ->schema([
                    TextEntry::make('registered_at')->label(FilamentHelp::label('Enrolled at', 'When this edge agent completed its first authenticated enrollment.'))->dateTime()->placeholder('Awaiting agent enrollment'),
                    TextEntry::make('last_heartbeat_at')->label(FilamentHelp::label('Control last heard', 'Expected every 5 seconds. The value refreshes automatically and becomes stale after the configured threshold.'))->since()->placeholder('No heartbeat received'),
                    TextEntry::make('serving')->label(FilamentHelp::label('Serving', 'Whether other PoPs can reach this edge, from their recent probes. It does not depend on the heartbeat, so an edge that serves traffic while the control plane has not heard from it shows Serving here and a stale heartbeat above.'))->badge()
                        ->state(fn (Edge $record): string => $record->servingLabel())
                        ->color(fn (string $state): string => match ($state) {
                            'Serving' => 'success',
                            'Not serving' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('agent_version')->label(FilamentHelp::label('Agent version', 'Software version reported by the running edge agent.'))->placeholder('Available after enrollment'),
                    TextEntry::make('runtime_versions')->label(FilamentHelp::label('Current runtime versions', 'Immutable gateway, agent, normal-cell, and WAF-cell image digests reported by the edge.'))
                        ->state(fn (Edge $record): string => self::formatRuntimeVersions($record->runtime_versions, 'Not reported'))
                        ->columnSpanFull(),
                    TextEntry::make('desired_runtime_versions')->label(FilamentHelp::label('Desired runtime versions', 'A value here means this edge is in a bounded fleet rollout or has version drift.'))
                        ->state(fn (Edge $record): string => self::formatRuntimeVersions($record->desired_runtime_versions, 'No rollout pending'))
                        ->columnSpanFull(),
                    TextEntry::make('capacity.listener_ready')->label(FilamentHelp::label('Traffic listener', 'Ready only when the gateway revision matches and at least one assigned cell is ready.'))->badge()
                        ->formatStateUsing(fn (mixed $state): string => match ($state) {
                            true => 'Ready',
                            false => 'Not ready',
                            default => 'Awaiting heartbeat',
                        })
                        ->color(fn (mixed $state): string => match ($state) {
                            true => 'success',
                            false => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('capacity.gateway.ready')->label(FilamentHelp::label('Gateway process', 'The gateway has validated and activated a complete routing map.'))->badge()
                        ->formatStateUsing(fn (mixed $state): string => match ($state) {
                            true => 'Ready',
                            false => 'Not ready',
                            default => 'Awaiting heartbeat',
                        })
                        ->color(fn (mixed $state): string => match ($state) {
                            true => 'success',
                            false => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('active_sequence')->label(FilamentHelp::label('Active configuration sequence', 'This edge’s monotonic deployment identity. Different edges do not need the same number. It is safe to grow and must not be reset.')),
                    TextEntry::make('capacity.gateway.active_revision')->label(FilamentHelp::label('Gateway map revision', 'Must match this edge’s active configuration sequence after convergence. It does not need to match another edge.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('capacity.gateway.listeners')->label(FilamentHelp::label('Gateway listeners', 'Current bound HTTP and HTTPS service sockets.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('capacity.gateway.routes')->label(FilamentHelp::label('Gateway routes', 'Current HTTP and HTTPS destination-address plus Host/SNI mappings, not a lifetime counter. Inspect assigned cells here and customer hostnames under Domains → DNS records.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('capacity.gateway.connections_active')->label(FilamentHelp::label('Gateway active connections', 'Client connections currently open through this edge gateway.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('capacity.gateway.errors')->label(FilamentHelp::label('Gateway errors', 'Bounded gateway error counter reported by the running process.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('capacity.gateway.candidate_rejections')->label(FilamentHelp::label('Gateway rejected candidates', 'Configuration candidates rejected before activation because validation failed.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('identity_certificate_expires_at')->label(FilamentHelp::label('Identity expires', 'Expiry time of the certificate used to authenticate this edge to the control plane.'))->dateTime()->placeholder('Not enrolled'),
                    TextEntry::make('capacity.last_rejection.reason')->label(FilamentHelp::label('Latest deployment rejection', 'Most recent reason a runtime configuration could not be activated.'))->placeholder('None reported')])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->extraCellAttributes(['class' => 'cdn-link-cell']),
            TextColumn::make('status')->label('Status')
                ->state(fn (Edge $record): string => match (true) {
                    ! $record->enabled => 'Disabled',
                    $record->drained => 'Drained',
                    default => 'Enabled',
                })
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Enabled' => 'success',
                    'Drained' => 'warning',
                    default => 'gray',
                }),
            TextColumn::make('serving')->label('Serving')
                ->state(fn (Edge $record): string => $record->servingLabel())
                ->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Serving' => 'success',
                    'Not serving' => 'danger',
                    default => 'gray',
                }),
            TextColumn::make('country_code')->label('Location')->formatStateUsing(fn (string $state, Edge $record): string => $state.' / '.$record->continent_code),
            TextColumn::make('last_heartbeat_at')->label('Control last heard')->since()->placeholder('Never')->sortable()
                ->color(fn (Edge $record): string => $record->controlLinkFresh() ? 'gray' : 'warning'),
            TextColumn::make('agent_version')->label('Agent'),
            TextColumn::make('cells_count')->counts('cells')->label('Cells')->alignEnd(),
            TextColumn::make('desired_runtime_versions')->label('Version drift')->badge()
                ->state(fn (Edge $record): string => $record->desired_runtime_versions !== null && $record->desired_runtime_versions !== $record->runtime_versions ? 'Drifted' : 'Aligned')
                ->color(fn (string $state): string => $state === 'Drifted' ? 'warning' : 'success'),
            TextColumn::make('capacity.last_rejection.reason')->label('Deployment failure')->limit(40),
            TextColumn::make('runtime_versions_reported_at')->label('Version report')->since()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('active_sequence')->label('Active revision')->sortable()->alignEnd()->toggleable(isToggledHiddenByDefault: true)])->recordActions([
                ViewAction::make()->iconButton(),
                EditAction::make()->iconButton(),
                ActionGroup::make([
                    Action::make('enable')->icon('phosphor-check-circle')->visible(fn (Edge $record): bool => ! $record->enabled)->action(fn (Edge $record) => self::changeState($record, ['enabled' => true], 'edge.enable')),
                    Action::make('disable')->icon('phosphor-prohibit')->color('danger')->requiresConfirmation()->visible(fn (Edge $record): bool => $record->enabled)->action(fn (Edge $record) => self::changeState($record, ['enabled' => false], 'edge.disable')),
                    Action::make('drain')->icon('phosphor-pause-circle')->color('warning')->requiresConfirmation()->visible(fn (Edge $record): bool => ! $record->drained)->action(fn (Edge $record) => self::changeState($record, ['drained' => true], 'edge.drain')),
                    Action::make('undrain')->icon('phosphor-play-circle')->visible(fn (Edge $record): bool => $record->drained)->action(fn (Edge $record) => self::changeState($record, ['drained' => false], 'edge.undrain')),
                    Action::make('rotateIdentity')
                        ->label('Rotate identity')
                        ->icon('phosphor-arrows-clockwise')
                        ->color('danger')
                        ->url(fn (Edge $record): string => self::getUrl('view', [
                            'record' => $record,
                            'action' => 'rotateIdentity']))])->label('More')->icon('phosphor-dots-three')])->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [PoolEndpointsRelationManager::class, CellsRelationManager::class];
    }

    private static function formatRuntimeVersions(?array $versions, string $empty): string
    {
        if ($versions === null || $versions === []) {
            return $empty;
        }

        return collect(RuntimeVersions::COMPONENTS)
            ->map(fn (string $component): string => str_replace('_', ' ', $component).': '.($versions[$component] ?? 'Not reported'))
            ->implode("\n");
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEdges::route('/'),
            'create' => CreateEdge::route('/create'),
            'view' => ViewEdge::route('/{record}'),
            'edit' => EditEdge::route('/{record}/edit'),
        ];
    }

    private static function changeState(Edge $edge, array $changes, string $action): void
    {
        $edge->update($changes);
        AuditLog::record(auth()->user(), $action, $edge, [], request()->ip());
        ReconcilePlatformDnsIdentity::dispatch()->afterCommit();
    }
}
