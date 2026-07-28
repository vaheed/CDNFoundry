<?php

namespace App\Filament\Admin\Resources\Edges\Pages;

use App\Filament\Admin\Resources\Edges\EdgeResource;
use App\Models\AuditLog;
use App\Models\Edge;
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
            for ($slot = 1; $slot <= $edge->cell_slot_count; $slot++) {
                $name = sprintf('cell-%02d', $slot);
                $edge->cells()->create([
                    'slot' => $slot, 'edge_pool_id' => null, 'name' => $name,
                    'http_port' => 18080 + $slot, 'https_port' => 18443 + $slot, 'status_port' => 19080 + $slot,
                    'runtime_path' => "/var/lib/cdnfoundry/runtime/{$name}.json",
                    'cache_path' => "/var/cache/cdnfoundry/{$name}", 'temporary_path' => "/var/lib/cdnfoundry/tmp/{$name}",
                    'resource_limits' => ['memory_bytes' => 536870912, 'cpu_millis' => 500, 'pids' => 128, 'cache_bytes' => 268435456, 'temporary_bytes' => 67108864, 'log_bytes' => 16777216],
                    'status' => 'unassigned',
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
