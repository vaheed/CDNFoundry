<?php

namespace App\Filament\Admin\Resources\PlatformRulesets\Pages;

use App\Filament\Admin\Resources\PlatformRulesets\PlatformRulesetResource;
use App\Support\PlatformRulesets;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePlatformRuleset extends CreateRecord
{
    protected static string $resource = PlatformRulesetResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return PlatformRulesets::saveRuleset(null, $data, auth()->user(), request()->ip())[0];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
