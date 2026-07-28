<?php

namespace App\Filament\Admin\Resources\EdgePools\Pages;

use App\Filament\Admin\Resources\EdgePools\EdgePoolResource;
use App\Jobs\ProvisionEdgePoolCells;
use App\Models\AuditLog;
use App\Models\EdgePool;
use App\Models\Operation;
use App\Support\EdgePoolRoutingData;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateEdgePool extends CreateRecord
{
    protected static string $resource = EdgePoolResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $data = [...$data, ...EdgePoolRoutingData::validate($data)];
        if (($data['replicas_per_edge'] ?? 1) > 1 && ! in_array($data['kind'], ['reserved', 'dedicated'], true)) {
            throw ValidationException::withMessages(['replicas_per_edge' => 'Replicated placement is limited to reserved and dedicated pools.']);
        }
        abort_if(EdgePool::query()->count() >= 32, 409, 'The deployment has reached the bounded 32-pool limit.');
        $pool = EdgePool::query()->create([...$data, 'enabled' => false]);
        $operation = Operation::query()->create([
            'actor_id' => auth()->id(), 'type' => 'edge.pool_provision', 'status' => 'pending',
            'input' => ['pool_id' => $pool->id],
        ]);
        AuditLog::record(auth()->user(), 'edge.pool_created', $pool, ['kind' => $pool->kind], request()->ip());
        ProvisionEdgePoolCells::dispatch($pool->id, $operation->id)->afterCommit();

        return $pool;
    }
}
