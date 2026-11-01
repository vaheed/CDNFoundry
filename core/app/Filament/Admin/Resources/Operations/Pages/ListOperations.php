<?php

namespace App\Filament\Admin\Resources\Operations\Pages;

use App\Filament\Admin\Resources\Operations\OperationResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListOperations extends ListRecords
{
    protected static string $resource = OperationResource::class;

    public function getSubheading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return new HtmlString('<span class="cdn-live" data-testid="live-indicator">Polling active</span>');
    }
}
