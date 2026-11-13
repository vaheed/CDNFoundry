<?php

namespace App\Filament\Domain\Resources\Domains\RelationManagers;

use App\Http\Controllers\ClientSecurityController;
use App\Models\ClientScriptSighting;
use App\Models\Domain;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The scripts and connections browsers reported on the domain's pages, with the day each was first seen. Only domains that opted in to
 * client-side security have rows. A script that first appears after the baseline window is new until someone approves it.
 */
class ClientScriptsRelationManager extends RelationManager
{
    protected static string $relationship = 'clientScriptSightings';

    protected static ?string $title = 'Scripts and connections';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $new = $ownerRecord->clientScriptSightings()->whereNull('approved_at')->count();

        return $new > 0 ? (string) $new : null;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->client_security !== null || $ownerRecord->clientScriptSightings()->exists();
    }

    public function table(Table $table): Table
    {
        return $table->recordAction(null)->recordUrl(null)
            ->columns([
                TextColumn::make('page_path')->label('Page')->limit(48)->tooltip(fn (ClientScriptSighting $record): ?string => strlen($record->page_path) > 48 ? $record->page_path : null),
                TextColumn::make('kind')->label('Type')->formatStateUsing(fn (string $state): string => $state === 'script' ? 'Script' : 'Connection'),
                TextColumn::make('resource')->label('Loaded from')->limit(48)->tooltip(fn (ClientScriptSighting $record): ?string => strlen($record->resource) > 48 ? $record->resource : null),
                TextColumn::make('first_seen_at')->label('First seen')->since()->dateTimeTooltip('Y-m-d H:i:s')->sortable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (ClientScriptSighting $record): string => $record->approved_at === null ? 'New' : 'Approved')
                    ->color(fn (string $state): string => $state === 'New' ? 'warning' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(['new' => 'New', 'approved' => 'Approved'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'new' => $query->whereNull('approved_at'),
                        'approved' => $query->whereNotNull('approved_at'),
                        default => $query,
                    }),
                SelectFilter::make('kind')->label('Type')->options(['script' => 'Script', 'connection' => 'Connection']),
            ])->deferFilters(false)
            ->recordActions([
                Action::make('approve')->label('Approve')->icon('phosphor-check-circle')->extraAttributes(['data-testid' => 'client-script-approve'])
                    ->visible(fn (ClientScriptSighting $record): bool => $record->approved_at === null)
                    ->action(function (ClientScriptSighting $record): void {
                        app(ClientSecurityController::class)->approve(request(), $this->getOwnerRecord(), $record->id);
                        Notification::make()->success()->title('Approved')->send();
                    }),
            ])
            ->headerActions([
                Action::make('approveAll')->label('Approve all new')->icon('phosphor-checks')->requiresConfirmation()->extraAttributes(['data-testid' => 'client-script-approve-all'])
                    ->modalHeading('Approve every new script and connection?')
                    ->modalDescription('They stay in the list as approved. Nothing changes on the cells.')
                    ->visible(fn (): bool => $this->getOwnerRecord()->clientScriptSightings()->whereNull('approved_at')->exists())
                    ->action(function (): void {
                        app(ClientSecurityController::class)->approve(request(), $this->getOwnerRecord());
                        Notification::make()->success()->title('All new scripts approved')->send();
                    }),
            ])
            ->emptyStateHeading('No scripts reported yet')
            ->emptyStateDescription('Browsers report scripts and connections while visitors load your HTML pages. Reports arrive within a few minutes.')
            ->defaultSort('first_seen_at', 'desc')->defaultPaginationPageOption(25)->paginated([25, 50]);
    }
}
