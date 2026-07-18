<?php

namespace App\Filament\Admin\Resources\Edges\Pages;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Models\AuditLog;
use App\Models\Edge;
use App\Models\EdgePool;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateEdge extends CreateRecord
{
    protected static string $resource = EdgeResource::class;

    private string $bootstrapToken;

    protected function handleRecordCreation(array $data): Model
    {
        $this->bootstrapToken = Str::random(64);
        $data['country_code'] = strtoupper($data['country_code']);
        $data['continent_code'] = strtoupper($data['continent_code']);
        $data['bootstrap_token_hash'] = hash('sha256', $this->bootstrapToken);
        $edge = DB::transaction(function () use ($data): Edge {
            $edge = Edge::query()->create($data);
            $defaultSharedId = EdgePool::query()->where('enabled', true)->where('kind', 'shared')->orderBy('id')->value('id');
            foreach (EdgePool::query()->orderBy('id')->limit(32)->get() as $pool) {
                $edge->cells()->create([
                    'edge_pool_id' => $pool->id, 'name' => $pool->name,
                    'service_ipv4' => $pool->id === $defaultSharedId ? $edge->ipv4 : null,
                    'service_ipv6' => $pool->id === $defaultSharedId ? $edge->ipv6 : null,
                ]);
            }
            AuditLog::record(auth()->user(), 'edge.created', $edge, [], request()->ip());

            return $edge;
        });

        return $edge;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()->success()->persistent()->title('Edge created — copy the one-time bootstrap token')->body($this->bootstrapToken);
    }
}
