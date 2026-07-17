<?php

namespace App\Filament\Admin\Resources\DnsClusters\Pages;

use App\Filament\Admin\Resources\DnsClusters\DnsClusterResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDnsClusters extends ListRecords
{
    protected static string $resource = DnsClusterResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
