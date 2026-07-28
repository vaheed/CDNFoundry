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
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class EdgeResource extends Resource
{
    protected static ?string $model = Edge::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-americas';

    protected static string|\UnitEnum|null $navigationGroup = 'Edge network';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true),
            TextInput::make('cell_slot_count')->label(FilamentHelp::label('Cell slots', 'Bounded OpenResty slots created during edge installation. This cannot be changed after creation.'))->numeric()->integer()->required()->minValue(1)->maxValue(32)->default(8)->disabledOn('edit'),
            Select::make('country_code')->label('Country')->options(array_combine(GeoVocabulary::countries(), GeoVocabulary::countries()))->searchable()->required(),
            Select::make('continent_code')->label('Continent')->options(array_combine(GeoVocabulary::CONTINENTS, GeoVocabulary::CONTINENTS))->required(),
            TextInput::make('management_ipv4')->label(FilamentHelp::label('Management IPv4', 'Optional operator access address. It is never bound by the gateway or published in customer DNS.'))->ipv4()->nullable()->unique(ignoreRecord: true),
            TextInput::make('management_ipv6')->label(FilamentHelp::label('Management IPv6', 'Optional operator access address. Public traffic addresses belong only to pool endpoints.'))->ipv6()->nullable()->unique(ignoreRecord: true),
        ]);
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
                    TextEntry::make('last_heartbeat_at')->label(FilamentHelp::label('Last heartbeat', 'Expected every 5 seconds. The value refreshes automatically and becomes stale after the configured threshold.'))->since()->placeholder('No heartbeat received'),
                    TextEntry::make('agent_version')->label(FilamentHelp::label('Agent version', 'Software version reported by the running edge agent.'))->placeholder('Available after enrollment'),
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
                    TextEntry::make('active_sequence')->label(FilamentHelp::label('Active configuration sequence', 'Monotonic deployment identity. It is safe to grow and must not be reset.')),
                    TextEntry::make('capacity.gateway.active_revision')->label(FilamentHelp::label('Gateway map revision', 'Must match the active configuration sequence after convergence.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('capacity.gateway.listeners')->label(FilamentHelp::label('Gateway listeners', 'Current bound HTTP and HTTPS service sockets.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('capacity.gateway.routes')->label(FilamentHelp::label('Gateway routes', 'Current destination-address plus Host/SNI protocol mappings, not historical rows.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('capacity.gateway.connections_active')->label(FilamentHelp::label('Gateway active connections', 'Client connections currently open through this edge gateway.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('capacity.gateway.errors')->label(FilamentHelp::label('Gateway errors', 'Bounded gateway error counter reported by the running process.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('capacity.gateway.candidate_rejections')->label(FilamentHelp::label('Gateway rejected candidates', 'Configuration candidates rejected before activation because validation failed.'))->placeholder('Gateway not reporting'),
                    TextEntry::make('identity_certificate_expires_at')->label(FilamentHelp::label('Identity expires', 'Expiry time of the certificate used to authenticate this edge to the control plane.'))->dateTime()->placeholder('Not enrolled'),
                    TextEntry::make('capacity.last_rejection.reason')->label(FilamentHelp::label('Latest deployment rejection', 'Most recent reason a runtime configuration could not be activated.'))->placeholder('None reported'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('country_code')->label('Location')->formatStateUsing(fn (string $state, Edge $record): string => $state.' / '.$record->continent_code),
            TextColumn::make('management_ipv4')->label('Management IPv4')->placeholder('None'),
            TextColumn::make('management_ipv6')->label('Management IPv6')->placeholder('None'),
            IconColumn::make('enabled')->boolean(),
            IconColumn::make('drained')->boolean(),
            TextColumn::make('last_heartbeat_at')->label('Heartbeat')->since()->placeholder('Never')->sortable(),
            TextColumn::make('agent_version')->label('Agent')->placeholder('Not registered'),
            TextColumn::make('active_sequence')->label('Active revision')->sortable(),
            TextColumn::make('cells_count')->counts('cells')->label('Cells'),
            TextColumn::make('capacity.last_rejection.reason')->label('Deployment failure')->placeholder('None'),
        ])->recordActions([
            ViewAction::make(),
            Action::make('enable')->visible(fn (Edge $record): bool => ! $record->enabled)->action(fn (Edge $record) => self::changeState($record, ['enabled' => true], 'edge.enable')),
            Action::make('disable')->color('danger')->requiresConfirmation()->visible(fn (Edge $record): bool => $record->enabled)->action(fn (Edge $record) => self::changeState($record, ['enabled' => false], 'edge.disable')),
            Action::make('drain')->color('warning')->requiresConfirmation()->visible(fn (Edge $record): bool => ! $record->drained)->action(fn (Edge $record) => self::changeState($record, ['drained' => true], 'edge.drain')),
            Action::make('undrain')->visible(fn (Edge $record): bool => $record->drained)->action(fn (Edge $record) => self::changeState($record, ['drained' => false], 'edge.undrain')),
            Action::make('rotateIdentity')->label('Rotate identity')->color('danger')->requiresConfirmation()->action(function (Edge $record): void {
                $token = Str::random(64);
                $record->update([
                    'identity_hash' => null, 'identity_csr_hash' => null, 'identity_certificate' => null,
                    'identity_certificate_serial' => null, 'identity_certificate_expires_at' => null,
                    'identity_revoked_at' => now(), 'bootstrap_token_hash' => hash('sha256', $token),
                    'bootstrap_consumed_at' => null, 'registered_at' => null,
                ]);
                AuditLog::record(auth()->user(), 'edge.identity_rotated', $record, [], request()->ip());
                ReconcilePlatformDnsIdentity::dispatch()->afterCommit();
                Notification::make()->warning()->persistent()->title('New one-time bootstrap token')->body($token)->send();
            }),
            EditAction::make(),
        ])->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [PoolEndpointsRelationManager::class, CellsRelationManager::class];
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
