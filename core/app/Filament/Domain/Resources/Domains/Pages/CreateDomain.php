<?php

namespace App\Filament\Domain\Resources\Domains\Pages;

use App\Enums\DomainLifecycleState;
use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Support\DomainName;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateDomain extends CreateRecord
{
    protected static string $resource = DomainResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $name = DomainName::normalize((string) $data['name']);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['data.name' => $exception->getMessage()]);
        }
        if (Domain::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['data.name' => 'This canonical domain is already managed.']);
        }
        $domain = Domain::query()->create(['name' => $name, 'display_name' => trim((string) $data['name']), 'lifecycle_state' => DomainLifecycleState::PendingVerification, 'revision' => 1]);
        if (! auth()->user()->isAdmin()) {
            $domain->users()->attach(auth()->id());
        }
        AuditLog::record(auth()->user(), 'domain.created', $domain, ['name' => $domain->name], request()->ip());

        return $domain;
    }
}
