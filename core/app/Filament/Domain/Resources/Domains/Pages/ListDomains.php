<?php

namespace App\Filament\Domain\Resources\Domains\Pages;

use App\Filament\Domain\Resources\Domains\DomainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDomains extends ListRecords
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
