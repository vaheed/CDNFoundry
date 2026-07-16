<?php

namespace App\Filament\Admin\Resources\Operations;

use App\Filament\Admin\Resources\Operations\Pages\ListOperations;
use App\Jobs\ApplyPlatformDnsSettings;
use App\Models\AuditLog;
use App\Models\Operation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OperationResource extends Resource
{
    protected static ?string $model = Operation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->copyable()->limit(12),
                TextColumn::make('type')->searchable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('attempts')->numeric(),
                TextColumn::make('error')->limit(80)->wrap(),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('finished_at')->dateTime(),
            ])
            ->recordActions([
                Action::make('retry')
                    ->visible(fn (Operation $record): bool => $record->status === 'failed')
                    ->requiresConfirmation()
                    ->action(function (Operation $record): void {
                        abort_unless($record->type === 'platform_dns_identity.update', 422, 'Unsupported operation type.');
                        $record->update(['status' => 'pending', 'error' => null, 'finished_at' => null]);
                        AuditLog::record(auth()->user(), 'operation.retry_requested', $record, [], request()->ip());
                        ApplyPlatformDnsSettings::dispatch($record->getKey());
                        Notification::make()->success()->title('Operation queued for retry')->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListOperations::route('/')];
    }
}
