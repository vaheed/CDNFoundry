<?php

namespace App\Filament\Admin\Resources\Operations\Pages;

use App\Filament\Admin\Resources\Operations\OperationResource;
use App\Models\Operation;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ListOperations extends ListRecords
{
    protected static string $resource = OperationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('acknowledgeAll')->label('Acknowledge all failures')->icon('phosphor-checks')->color('gray')
                ->visible(fn (): bool => Operation::query()->unresolvedFailures()->exists())
                ->requiresConfirmation()
                ->modalDescription('Marks every unresolved failed operation as reviewed so health stops reporting degraded. Failed rows stay in the history.')
                ->action(function (): void {
                    $count = OperationResource::acknowledge(Operation::query()->unresolvedFailures()->limit(1000)->get());
                    Notification::make()->success()->title("{$count} failure(s) acknowledged")->send();
                }),
        ];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString('<span class="cdn-live" data-testid="live-indicator">Polling active</span>');
    }
}
