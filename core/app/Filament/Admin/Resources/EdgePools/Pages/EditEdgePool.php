<?php

namespace App\Filament\Admin\Resources\EdgePools\Pages;

use App\Filament\Admin\Resources\EdgePools\EdgePoolResource;
use App\Models\AuditLog;
use Filament\Resources\Pages\EditRecord;

class EditEdgePool extends EditRecord
{
    protected static string $resource = EdgePoolResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['revision'] = $this->record->revision + 1;

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->cells()->update(['name' => $this->record->name]);
        AuditLog::record(auth()->user(), 'edge_pool.updated', $this->record, [], request()->ip());
    }
}
