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
use App\Support\GeoVocabulary;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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
            TextInput::make('cell_slot_count')->label('Cell slots')->numeric()->integer()->required()->minValue(1)->maxValue(32)->default(8)->disabledOn('edit')
                ->helperText('Bounded OpenResty slots created during edge installation. This cannot be changed after creation.'),
            Select::make('country_code')->label('Country')->options(array_combine(GeoVocabulary::countries(), GeoVocabulary::countries()))->searchable()->required(),
            Select::make('continent_code')->label('Continent')->options(array_combine(GeoVocabulary::CONTINENTS, GeoVocabulary::CONTINENTS))->required(),
            TextInput::make('management_ipv4')->label('Management IPv4')->ipv4()->nullable()->unique(ignoreRecord: true)
                ->helperText('Optional operator access address. It is never bound by the gateway or published in customer DNS.'),
            TextInput::make('management_ipv6')->label('Management IPv6')->ipv6()->nullable()->unique(ignoreRecord: true)
                ->helperText('Optional operator access address. Public traffic addresses belong only to pool endpoints.'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('registered_at')->label('Enrolled at')->dateTime()->placeholder('Awaiting agent enrollment'),
            TextEntry::make('last_heartbeat_at')->label('Last heartbeat')->since()->placeholder('No heartbeat received'),
            TextEntry::make('agent_version')->label('Agent version')->placeholder('Available after enrollment'),
            TextEntry::make('capacity.listener_ready')->label('Traffic listener')->badge()
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
            TextEntry::make('capacity.gateway.ready')->label('Gateway')->badge()
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
            TextEntry::make('active_sequence')->label('Active configuration sequence'),
            TextEntry::make('capacity.gateway.active_revision')->label('Gateway map revision')->placeholder('Gateway not reporting'),
            TextEntry::make('capacity.gateway.listeners')->label('Gateway listeners')->placeholder('Gateway not reporting'),
            TextEntry::make('capacity.gateway.routes')->label('Gateway routes')->placeholder('Gateway not reporting'),
            TextEntry::make('capacity.gateway.connections_active')->label('Gateway active connections')->placeholder('Gateway not reporting'),
            TextEntry::make('capacity.gateway.errors')->label('Gateway errors')->placeholder('Gateway not reporting'),
            TextEntry::make('capacity.gateway.candidate_rejections')->label('Gateway rejected candidates')->placeholder('Gateway not reporting'),
            TextEntry::make('identity_certificate_expires_at')->label('Identity expires')->dateTime()->placeholder('Not enrolled'),
            TextEntry::make('capacity.last_rejection.reason')->label('Latest deployment rejection')->placeholder('None reported'),
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
