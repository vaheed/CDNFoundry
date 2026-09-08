<?php

namespace App\Filament\Domain\Resources\Domains\Pages;

use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Models\Domain;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateDomain extends CreateRecord
{
    protected static string $resource = DomainResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return Domain::createPendingFor(auth()->user(), (string) $data['name'], request()->ip());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['data.name' => $exception->errors()['name'] ?? ['The domain could not be created.']]);
        }
    }
}
