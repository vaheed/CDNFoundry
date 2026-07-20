<?php

namespace App\Filament\Admin\Resources\DnsClusters\Pages;

use App\Filament\Admin\Resources\DnsClusters\DnsClusterResource;
use App\Http\Controllers\Admin\DnsOperationController;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListDnsClusters extends ListRecords
{
    protected static string $resource = DnsClusterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reconcileAllZones')
                ->label('Reconcile all zones')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (): void {
                    $response = app(DnsOperationController::class)->reconcile(request());
                    $operation = $response->getData(true)['data'];
                    Notification::make()->info()->title('Global DNS reconciliation queued')
                        ->body("Operation {$operation['id']} will process active zones in bounded chunks.")->send();
                }),
            CreateAction::make(),
        ];
    }
}
