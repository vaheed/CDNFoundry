<?php

namespace App\Filament\Admin\Resources\Edges\RelationManagers;

use App\Models\EdgeCell;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
            TextColumn::make('active_revision')->label('Active revision'),
            TextColumn::make('capacity.assigned_domain_count')->label('Domains')->placeholder('Unknown'),
            TextColumn::make('capacity.cpu_usage')->label('CPU')->placeholder('Unknown'),
            TextColumn::make('capacity.memory_usage')->label('Memory')->placeholder('Unknown'),
            TextColumn::make('capacity.active_connections')->label('Connections')->placeholder('Unknown'),
            TextColumn::make('capacity.cache_usage')->label('Cache')->placeholder('Unknown'),
            TextColumn::make('capacity.temporary_storage_usage')->label('Temporary storage')->placeholder('Unknown'),
        ])->recordActions([
            Action::make('drain')->requiresConfirmation()->visible(fn (EdgeCell $record): bool => ! $record->drained)->action(fn (EdgeCell $record) => $record->update(['drained' => true])),
            Action::make('undrain')->visible(fn (EdgeCell $record): bool => $record->drained)->action(fn (EdgeCell $record) => $record->update(['drained' => false])),
        ]);
    }
}
