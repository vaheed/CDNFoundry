<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DnsRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'name' => $this->name,
            'content' => $this->content, 'ttl' => $this->ttl, 'priority' => $this->priority,
            'weight' => $this->weight, 'port' => $this->port, 'mode' => $this->mode,
            'geo' => $this->when($this->mode === 'geo_dns', $this->geo_config),
            'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
