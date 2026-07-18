<?php

namespace App\Filament\Admin\Resources\Edges\Pages;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use Filament\Resources\Pages\EditRecord;

class EditEdge extends EditRecord
{
    protected static string $resource = EdgeResource::class;

    protected function afterSave(): void
    {
        AuditLog::record(auth()->user(), 'edge.updated', $this->record, [], request()->ip());
        ReconcilePlatformDnsIdentity::dispatch()->afterCommit();
    }
}
