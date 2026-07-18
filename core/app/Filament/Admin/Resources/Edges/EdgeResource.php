<?php

namespace App\Filament\Admin\Resources\Edges;

use App\Filament\Admin\Resources\Edges\Pages\CreateEdge;
use App\Filament\Admin\Resources\Edges\Pages\EditEdge;
use App\Filament\Admin\Resources\Edges\Pages\ListEdges;
use App\Filament\Admin\Resources\Edges\RelationManagers\CellsRelationManager;
use App\Models\AuditLog;
use App\Models\Edge;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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
            TextInput::make('country_code')->label('Country')->required()->length(2),
            TextInput::make('continent_code')->label('Continent')->required()->length(2),
            TextInput::make('ipv4')->label('IPv4')->ip()->required()->unique(ignoreRecord: true),
            TextInput::make('ipv6')->label('IPv6')->ipv6()->unique(ignoreRecord: true),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('registered_at')->dateTime()->placeholder('Awaiting registration'),
            TextEntry::make('last_heartbeat_at')->since()->placeholder('Never'),
            TextEntry::make('agent_version')->placeholder('Unknown'),
            TextEntry::make('active_sequence')->label('Active configuration sequence'),
            TextEntry::make('identity_certificate_expires_at')->label('Identity expires')->dateTime()->placeholder('Not enrolled'),
            TextEntry::make('capacity')->formatStateUsing(fn (?array $state): string => json_encode($state, JSON_UNESCAPED_SLASHES) ?: 'No heartbeat capacity reported')
                ->placeholder('No heartbeat capacity reported'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('country_code')->label('Location')->formatStateUsing(fn (string $state, Edge $record): string => $state.' / '.$record->continent_code),
            TextColumn::make('ipv4')->label('IPv4'),
            TextColumn::make('ipv6')->label('IPv6')->placeholder('None'),
            IconColumn::make('enabled')->boolean(),
            IconColumn::make('drained')->boolean(),
            TextColumn::make('last_heartbeat_at')->label('Heartbeat')->since()->placeholder('Never')->sortable(),
            TextColumn::make('agent_version')->label('Agent')->placeholder('Not registered'),
            TextColumn::make('active_sequence')->label('Active revision')->sortable(),
            TextColumn::make('cells_count')->counts('cells')->label('Cells'),
        ])->recordActions([
            Action::make('enable')->visible(fn (Edge $record): bool => ! $record->enabled)->action(fn (Edge $record) => self::changeState($record, ['enabled' => true], 'edge.enable')),
            Action::make('disable')->color('danger')->requiresConfirmation()->visible(fn (Edge $record): bool => $record->enabled)->action(fn (Edge $record) => self::changeState($record, ['enabled' => false], 'edge.disable')),
            Action::make('drain')->color('warning')->requiresConfirmation()->visible(fn (Edge $record): bool => ! $record->drained)->action(fn (Edge $record) => self::changeState($record, ['drained' => true], 'edge.drain')),
            Action::make('undrain')->visible(fn (Edge $record): bool => $record->drained)->action(fn (Edge $record) => self::changeState($record, ['drained' => false], 'edge.undrain')),
            Action::make('rotateIdentity')->label('Rotate identity')->color('danger')->requiresConfirmation()->action(function (Edge $record): void {
                $token = Str::random(64);
                $record->update(['identity_hash' => null, 'identity_certificate_serial' => null, 'identity_certificate_expires_at' => null, 'identity_revoked_at' => now(), 'bootstrap_token_hash' => hash('sha256', $token), 'registered_at' => null]);
                AuditLog::record(auth()->user(), 'edge.identity_rotated', $record, [], request()->ip());
                Notification::make()->warning()->persistent()->title('New one-time bootstrap token')->body($token)->send();
            }),
            EditAction::make(),
        ])->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [CellsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListEdges::route('/'), 'create' => CreateEdge::route('/create'), 'edit' => EditEdge::route('/{record}/edit')];
    }

    private static function changeState(Edge $edge, array $changes, string $action): void
    {
        $edge->update($changes);
        AuditLog::record(auth()->user(), $action, $edge, [], request()->ip());
    }
}
