<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Http\Controllers\WafController;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WafExclusionsRelationManager extends RelationManager
{
    protected static string $relationship = 'wafExclusions';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return number_format($ownerRecord->wafExclusions()->count());
    }

    protected static ?string $title = 'Managed WAF exclusions';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('dimension')->badge(), TextColumn::make('value')->limit(50),
            TextColumn::make('rule_id')->placeholder('All applicable rules'),
            TextColumn::make('reason')->limit(60), TextColumn::make('owner.name')->label('Owner'),
            TextColumn::make('expires_at')->dateTime()->sortable(),
        ])->recordActions([
            DeleteAction::make()->using(function ($record): bool {
                app(WafController::class)->destroyExclusion(request(), $this->getOwnerRecord(), $record);

                return true;
            }),
        ])->defaultSort('expires_at');
    }
}
