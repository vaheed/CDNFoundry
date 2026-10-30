<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Http\Controllers\WafController;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WafExclusionsRelationManager extends RelationManager
{
    protected static string $relationship = 'wafExclusions';

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
