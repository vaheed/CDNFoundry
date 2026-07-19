<?php

namespace App\Filament\Admin\Resources\Edges;

use App\Actions\DispatchEmergencyMode;
use App\Filament\Admin\Resources\Edges\Pages\CreateEdge;
use App\Filament\Admin\Resources\Edges\Pages\EditEdge;
use App\Filament\Admin\Resources\Edges\Pages\ListEdges;
use App\Filament\Admin\Resources\Edges\RelationManagers\CellsRelationManager;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\Edge;
use App\Models\EmergencyMode;
use App\Support\GeoVocabulary;
use App\Support\NetworkAddress;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
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
            Select::make('country_code')->label('Country')->options(array_combine(GeoVocabulary::countries(), GeoVocabulary::countries()))->searchable()->required(),
            Select::make('continent_code')->label('Continent')->options(array_combine(GeoVocabulary::CONTINENTS, GeoVocabulary::CONTINENTS))->required(),
            TextInput::make('ipv4')->label('IPv4')->ipv4()->required()->unique(ignoreRecord: true)
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                    if (NetworkAddress::isUnsafe((string) $value)) {
                        $fail('The edge address must be public unicast.');
                    }
                }),
            TextInput::make('ipv6')->label('IPv6')->ipv6()->unique(ignoreRecord: true)
                ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                    if (filled($value) && NetworkAddress::isUnsafe((string) $value)) {
                        $fail('The edge address must be public unicast.');
                    }
                }),
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
            TextEntry::make('active_sequence')->label('Active configuration sequence'),
            TextEntry::make('identity_certificate_expires_at')->label('Identity expires')->dateTime()->placeholder('Not enrolled'),
            TextEntry::make('capacity.last_rejection.reason')->label('Latest deployment rejection')->placeholder('None reported'),
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
            TextColumn::make('capacity.last_rejection.reason')->label('Deployment failure')->placeholder('None'),
        ])->recordActions([
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
            Action::make('emergencyMode')->label('Emergency mode')->color('danger')->requiresConfirmation()
                ->visible(fn (Edge $record): bool => ! EmergencyMode::query()->where('target_type', 'edge')->where('target_id', $record->id)->where('active', true)->exists())
                ->schema([
                    CheckboxList::make('actions')->options(array_combine(config('security.emergency_actions'), config('security.emergency_actions')))->required()->minItems(1),
                    TextInput::make('duration_minutes')->label('Automatic expiry (minutes)')->numeric()->minValue(1)->maxValue(config('security.emergency_duration_minutes_maximum'))->helperText('Leave empty only for an explicitly permanent emergency.'),
                ])->action(function (Edge $record, array $data): void {
                    [$mode, $operation] = DispatchEmergencyMode::activate('edge', $record->id, $data['actions'], filled($data['duration_minutes'] ?? null) ? (int) $data['duration_minutes'] : null, auth()->user());
                    AuditLog::record(auth()->user(), 'security.emergency_activated', $record, ['mode_id' => $mode->id, 'actions' => $mode->actions], request()->ip());
                    Notification::make()->warning()->title('Edge emergency mode queued')->body("Operation {$operation->id} is delivering the bounded actions to every cell.")->send();
                }),
            Action::make('clearEmergencyMode')->label('Clear emergency')->color('success')->requiresConfirmation()
                ->visible(fn (Edge $record): bool => EmergencyMode::query()->where('target_type', 'edge')->where('target_id', $record->id)->where('active', true)->exists())
                ->action(function (Edge $record): void {
                    $operation = DispatchEmergencyMode::deactivateTarget('edge', $record->id, auth()->user());
                    AuditLog::record(auth()->user(), 'security.emergency_deactivated', $record, ['operation_id' => $operation->id], request()->ip());
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
        ReconcilePlatformDnsIdentity::dispatch()->afterCommit();
    }
}
