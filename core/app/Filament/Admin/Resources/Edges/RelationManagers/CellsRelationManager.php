<?php

namespace App\Filament\Admin\Resources\Edges\RelationManagers;

use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\EdgeCell;
use App\Models\EdgeTask;
use App\Support\NetworkAddress;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CellsRelationManager extends RelationManager
{
    protected static string $relationship = 'cells';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('service_ipv4')->label('Pool service IPv4')->ipv4()->required()->unique(ignoreRecord: true),
            TextInput::make('service_ipv6')->label('Pool service IPv6')->ipv6()->unique(ignoreRecord: true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name'),
            TextColumn::make('pool.name')->label('Service pool'),
            IconColumn::make('drained')->boolean(),
            TextColumn::make('capacity.active_revision')->label('Active revision')->placeholder('Unknown'),
            TextColumn::make('capacity.openresty_version')->label('OpenResty')->placeholder('Unknown'),
            TextColumn::make('capacity.assigned_domain_count')->label('Domains')->placeholder('Unknown'),
            TextColumn::make('capacity.cpu_usage')->label('CPU')->placeholder('Unknown'),
            TextColumn::make('capacity.memory_usage')->label('Memory')->placeholder('Unknown'),
            TextColumn::make('capacity.active_connections')->label('Connections')->placeholder('Unknown'),
            TextColumn::make('capacity.cache_usage')->label('Cache')->placeholder('Unknown'),
            TextColumn::make('capacity.temporary_storage_usage')->label('Temporary storage')->placeholder('Unknown'),
        ])->recordActions([
            EditAction::make()->mutateDataUsing(function (array $data, EdgeCell $record): array {
                foreach (array_filter($data) as $address) {
                    if (NetworkAddress::isUnsafe($address)) {
                        throw ValidationException::withMessages(['service_ipv4' => 'Cell service addresses must be public unicast addresses.']);
                    }
                }
                if ($record->edge->ipv6 !== null && blank($data['service_ipv6'] ?? null)) {
                    throw ValidationException::withMessages(['service_ipv6' => 'This dual-stack edge requires a pool service IPv6 address.']);
                }

                return $data;
            })->after(function (EdgeCell $record): void {
                AuditLog::record(auth()->user(), 'edge.cell_addresses_updated', $record, [], request()->ip());
                ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
            }),
            Action::make('drain')->requiresConfirmation()->visible(fn (EdgeCell $record): bool => ! $record->drained)->action(fn (EdgeCell $record) => self::queue($record, 'drain')),
            Action::make('undrain')->visible(fn (EdgeCell $record): bool => $record->drained)->action(fn (EdgeCell $record) => self::queue($record, 'undrain')),
            Action::make('restart')->color('warning')->requiresConfirmation()->action(fn (EdgeCell $record) => self::queue($record, 'restart')),
        ]);
    }

    private static function queue(EdgeCell $cell, string $action): void
    {
        if ($action !== 'drain' && $cell->service_ipv4 === null) {
            throw ValidationException::withMessages(['service_ipv4' => 'Configure the cell service addresses before making it available.']);
        }
        if ($action !== 'restart') {
            $cell->update(['drained' => $action === 'drain', ...($action === 'undrain' ? ['status' => 'pending'] : [])]);
        }
        $task = EdgeTask::query()->where('edge_id', $cell->edge_id)->where('type', 'cell_'.$action)
            ->where('status', 'pending')->where('payload->cell_id', $cell->id)->first() ?? EdgeTask::query()->create([
                'id' => (string) Str::uuid(), 'edge_id' => $cell->edge_id, 'type' => 'cell_'.$action,
                'status' => 'pending', 'payload' => ['cell_id' => $cell->id, 'cell_name' => $cell->name],
            ]);
        AuditLog::record(auth()->user(), 'edge.cell_'.$action, $cell, ['task_id' => $task->id], request()->ip());
        if ($action !== 'restart') {
            ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
        }
    }
}
