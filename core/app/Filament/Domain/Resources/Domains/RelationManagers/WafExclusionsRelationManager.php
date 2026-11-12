<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Filament\Domain\Resources\Domains\RelationManagers\Concerns\SyncsDomainPage;
use App\Http\Controllers\WafController;
use Filament\Actions\DeleteAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WafExclusionsRelationManager extends RelationManager
{
    use SyncsDomainPage;

    protected static string $relationship = 'wafExclusions';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return number_format($ownerRecord->wafExclusions()->count());
    }

    protected static ?string $title = 'WAF exclusions';

    /** New exclusions are unavailable, so the tab exists only to remove exclusions saved before that. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return parent::canViewForRecord($ownerRecord, $pageClass) && $ownerRecord->wafExclusions()->exists();
    }

    public function table(Table $table): Table
    {
        return $table->description('These exclusions are inactive: the edge ignores them. Delete them, and use the Observe protection level for a false positive.')->columns([
            TextColumn::make('dimension')->badge()->formatStateUsing(fn ($state): string => ucfirst(str_replace('_', ' ', $state instanceof \BackedEnum ? $state->value : (string) $state))), TextColumn::make('value')->limit(50),
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
