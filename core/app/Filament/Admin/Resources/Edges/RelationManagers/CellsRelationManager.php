<?php

namespace App\Filament\Admin\Resources\Edges\RelationManagers;

use App\Models\AuditLog;
use App\Models\EdgeCell;
use App\Models\EdgeTask;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CellsRelationManager extends RelationManager
{
    protected static string $relationship = 'cells';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
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
            Action::make('drain')->requiresConfirmation()->visible(fn (EdgeCell $record): bool => ! $record->drained)->action(fn (EdgeCell $record) => self::queue($record, 'drain')),
            Action::make('undrain')->visible(fn (EdgeCell $record): bool => $record->drained)->action(fn (EdgeCell $record) => self::queue($record, 'undrain')),
            Action::make('restart')->color('warning')->requiresConfirmation()->action(fn (EdgeCell $record) => self::queue($record, 'restart')),
        ]);
    }

    private static function queue(EdgeCell $cell, string $action): void
    {
        if ($action !== 'restart') {
            $cell->update(['drained' => $action === 'drain', 'status' => $action === 'drain' ? 'drained' : 'pending']);
        }
        $task = EdgeTask::query()->create([
            'id' => (string) Str::uuid(), 'edge_id' => $cell->edge_id, 'type' => 'cell_'.$action,
            'status' => 'pending', 'payload' => ['cell_id' => $cell->id, 'cell_name' => $cell->name],
        ]);
        AuditLog::record(auth()->user(), 'edge.cell_'.$action, $cell, ['task_id' => $task->id], request()->ip());
    }
}
