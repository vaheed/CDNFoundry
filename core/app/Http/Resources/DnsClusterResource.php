<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DnsClusterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'location' => $this->location, 'enabled' => $this->enabled,
            'api_url' => $this->api_url, 'server_id' => $this->server_id, 'nameservers' => $this->nameservers,
            'capacity_zones' => $this->capacity_zones, 'operational_notes' => $this->operational_notes,
            'last_health_status' => $this->last_health_status, 'last_health_error' => $this->last_health_error,
            'last_health_at' => $this->last_health_at?->toIso8601String(), 'last_reconciled_revision' => $this->last_reconciled_revision,
        ];
    }
}
