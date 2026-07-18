<?php

namespace App\Filament\Admin\Resources\EdgePools;

use App\Filament\Admin\Resources\EdgePools\Pages\CreateEdgePool;
use App\Filament\Admin\Resources\EdgePools\Pages\EditEdgePool;
use App\Filament\Admin\Resources\EdgePools\Pages\ListEdgePools;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\DomainEdgePlacement;
use App\Models\EdgePool;
use Filament\Actions\Action;
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
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true),
            Select::make('kind')->options(['shared' => 'Shared', 'quarantine' => 'Quarantine', 'dedicated' => 'Dedicated'])->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('kind')->badge(),
            IconColumn::make('enabled')->boolean(),
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
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListEdgePools::route('/'), 'create' => CreateEdgePool::route('/create'), 'edit' => EditEdgePool::route('/{record}/edit')];
    }
}
