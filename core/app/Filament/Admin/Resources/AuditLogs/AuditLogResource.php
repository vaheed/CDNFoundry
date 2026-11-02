<?php

namespace App\Filament\Admin\Resources\AuditLogs;

use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-clipboard-text';

    protected static ?string $navigationLabel = 'Audit logs';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Time')->since()->sortable()->tooltip(fn ($record): string => $record->created_at->toDateTimeString().' UTC'),
                TextColumn::make('actor.email')->label('Actor')->searchable(),
                TextColumn::make('action')->searchable(),
                TextColumn::make('subject_type')->label('Subject')
                    ->formatStateUsing(fn (?string $state, $record): string => trim(class_basename($state ?? '').' #'.$record->subject_id)),
                TextColumn::make('ip_address')->label('IP address')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')->label('ID')->numeric()->sortable()->alignEnd()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
