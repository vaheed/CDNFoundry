<?php

namespace App\Filament\Admin\Resources\PlatformRulesets\Pages;

use App\Filament\Admin\Resources\PlatformRulesets\PlatformRulesetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlatformRulesets extends ListRecords
{
    protected static string $resource = PlatformRulesetResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New ruleset')];
    }
}
