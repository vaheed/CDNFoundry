<?php

namespace App\Filament\Admin\Resources\EdgePools\Pages;

use App\Filament\Admin\Resources\EdgePools\EdgePoolResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEdgePools extends ListRecords
{
    protected static string $resource = EdgePoolResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
