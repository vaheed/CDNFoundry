<?php

namespace App\Filament\Admin\Resources\EdgePools\Pages;

use App\Filament\Admin\Resources\EdgePools\EdgePoolResource;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditEdgePool extends EditRecord
{
    protected static string $resource = EdgePoolResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['name'] ?? $this->record->name) !== $this->record->name && $this->record->cells()->exists()) {
            throw ValidationException::withMessages(['name' => 'Pool runtime names are immutable after cells have been provisioned.']);
        }
        $data['revision'] = $this->record->revision + 1;

        return $data;
    }

    protected function afterSave(): void
    {
        AuditLog::record(auth()->user(), 'edge.pool_updated', $this->record, [], request()->ip());
        ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
    }
}
