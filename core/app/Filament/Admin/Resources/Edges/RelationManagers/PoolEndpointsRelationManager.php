<?php

namespace App\Filament\Admin\Resources\Edges\RelationManagers;

use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;
use App\Support\EdgePoolEndpointData;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PoolEndpointsRelationManager extends RelationManager
{
    protected static string $relationship = 'poolEndpoints';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('edge_pool_id')->label('Geo-Unicast service pool')->required()->searchable()->preload()
                ->options(fn (): array => EdgePool::query()->where('routing_mode', 'geo_unicast')->orderBy('name')->pluck('name', 'id')->all())
                ->helperText('Simple Anycast participation is automatic when you assign its first cell on this edge.'),
            TextInput::make('ipv4')->label('Service IPv4')->ipv4()->nullable(),
            TextInput::make('ipv6')->label('Service IPv6')->ipv6()->nullable()
                ->helperText('Configure IPv4, IPv6, or both. Management addresses are not valid service endpoints.'),
            Toggle::make('withdrawn')->label('Temporarily remove from traffic')
                ->helperText('Keeps this endpoint saved but removes it from this edge gateway and DNS after reconciliation. Turn it off to restore traffic.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('pool.name')->label('Pool'),
            TextColumn::make('ipv4')->label('IPv4')->state(fn (EdgePoolEndpoint $record): ?string => $record->effectiveAddress('ipv4'))->placeholder('Not configured'),
            TextColumn::make('ipv6')->label('IPv6')->state(fn (EdgePoolEndpoint $record): ?string => $record->effectiveAddress('ipv6'))->placeholder('Not configured'),
            TextColumn::make('gateway_state')->badge()->label('Gateway'),
            TextColumn::make('readiness')->state(fn (EdgePoolEndpoint $record): string => $record->readinessReason())->badge(),
            TextColumn::make('revision')->label('Desired revision'),
            TextColumn::make('gateway_revision')->label('Active revision'),
            IconColumn::make('withdrawn')->label('Temporarily removed')->boolean(),
        ])->headerActions([
            CreateAction::make()->mutateDataUsing(fn (array $data): array => [...EdgePoolEndpointData::validate($data, null, EdgePool::query()->findOrFail($data['edge_pool_id'])), 'edge_pool_id' => $data['edge_pool_id'], 'revision' => 1, 'gateway_state' => 'pending', 'readiness_reason' => 'gateway_not_acknowledged'])
                ->after(fn (EdgePoolEndpoint $record) => self::changed($record, 'edge.pool_endpoint_created')),
        ])->recordActions([
            EditAction::make()->visible(fn (EdgePoolEndpoint $record): bool => ! $record->pool->isSimpleAnycast())
                ->mutateDataUsing(fn (array $data, EdgePoolEndpoint $record): array => [...EdgePoolEndpointData::validate($data, $record), 'revision' => $record->revision + 1, 'gateway_state' => 'pending', 'readiness_reason' => 'gateway_not_acknowledged'])
                ->after(fn (EdgePoolEndpoint $record) => self::changed($record, 'edge.pool_endpoint_updated')),
            DeleteAction::make()->visible(fn (EdgePoolEndpoint $record): bool => ! $record->pool->isSimpleAnycast() && $record->withdrawn)
                ->requiresConfirmation()
                ->before(fn (EdgePoolEndpoint $record) => AuditLog::record(auth()->user(), 'edge.pool_endpoint_deleted', $record, [], request()->ip()))
                ->after(fn () => ReconcilePlatformDnsIdentity::dispatchForRoutingChange()),
        ]);
    }

    private static function changed(EdgePoolEndpoint $endpoint, string $action): void
    {
        AuditLog::record(auth()->user(), $action, $endpoint, ['revision' => $endpoint->revision], request()->ip());
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
    }
}
