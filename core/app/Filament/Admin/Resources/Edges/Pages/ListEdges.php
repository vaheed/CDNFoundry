<?php

namespace App\Filament\Admin\Resources\Edges\Pages;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Http\Controllers\Admin\EdgeOperationsController;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListEdges extends ListRecords
{
    protected static string $resource = EdgeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reconcileAllDomains')
                ->label('Reconcile all domains')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (): void {
                    $response = app(EdgeOperationsController::class)->reconcile(request());
                    $operation = $response->getData(true)['data'];
                    Notification::make()->info()->title('Global edge reconciliation queued')
                        ->body("Operation {$operation['operation_id']} will coalesce domain deployments in bounded chunks.")->send();
                }),
            CreateAction::make(),
        ];
    }
}
