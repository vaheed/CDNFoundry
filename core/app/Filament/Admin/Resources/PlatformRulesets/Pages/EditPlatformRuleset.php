<?php

namespace App\Filament\Admin\Resources\PlatformRulesets\Pages;

use App\Filament\Admin\Resources\PlatformRulesets\PlatformRulesetResource;
use App\Support\PlatformRulesets;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPlatformRuleset extends EditRecord
{
    protected static string $resource = PlatformRulesetResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['domain_ids'] = $this->record->domains()->pluck('domains.id')->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return PlatformRulesets::saveRuleset($record, $data, auth()->user(), request()->ip())[0];
    }
}
