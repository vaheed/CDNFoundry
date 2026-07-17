<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DnsDeploymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain' => ['id' => $this->domain->id, 'name' => $this->domain->name],
            'cluster' => ['id' => $this->cluster->id, 'name' => $this->cluster->name, 'location' => $this->cluster->location],
            'desired_revision' => $this->desired_revision,
            'deployed_revision' => $this->deployed_revision,
            'status' => $this->status,
            'last_error' => $this->last_error,
            'attempts' => $this->attempts,
            'last_attempted_at' => $this->last_attempted_at?->toIso8601String(),
            'deployed_at' => $this->deployed_at?->toIso8601String(),
            'tombstone' => $this->tombstone,
            'deprovisioned_at' => $this->deprovisioned_at?->toIso8601String(),
        ];
    }
}
