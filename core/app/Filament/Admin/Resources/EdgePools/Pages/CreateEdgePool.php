<?php

namespace App\Filament\Admin\Resources\EdgePools\Pages;

use App\Filament\Admin\Resources\EdgePools\EdgePoolResource;
use App\Models\AuditLog;
use App\Models\Edge;
use Filament\Resources\Pages\CreateRecord;

class CreateEdgePool extends CreateRecord
{
    protected static string $resource = EdgePoolResource::class;

    protected function afterCreate(): void
    {
        foreach (Edge::query()->get() as $edge) {
            $edge->cells()->firstOrCreate(['edge_pool_id' => $this->record->id], ['name' => 'pool-'.$this->record->id]);
        }
        AuditLog::record(auth()->user(), 'edge_pool.created', $this->record, [], request()->ip());
    }
}
