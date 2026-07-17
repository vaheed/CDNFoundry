<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->isAdmin() === true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable(),
            TextColumn::make('email')->searchable(),
            TextColumn::make('disabled_at')->label('State')->formatStateUsing(fn ($state): string => $state ? 'Disabled' : 'Active')->badge(),
        ])->headerActions([
            AttachAction::make()->attachAnother(false)->preloadRecordSelect()
                ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query->where('type', UserType::User->value)->whereNull('disabled_at'))
                ->after(function (): void {
                    AuditLog::record(auth()->user(), 'domain.user_assigned', $this->getOwnerRecord(), [], request()->ip());
                }),
        ])->recordActions([
            DetachAction::make()->after(function (User $record): void {
                AuditLog::record(auth()->user(), 'domain.user_unassigned', $this->getOwnerRecord(), ['user_id' => $record->id], request()->ip());
            }),
        ]);
    }
}
