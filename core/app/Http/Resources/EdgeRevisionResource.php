<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EdgeRevisionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $snapshot = is_array($this->snapshot) ? $this->snapshot : [];

        return [
            'revision' => $this->revision,
            'status' => $this->status,
            'checksum' => $this->checksum,
            'summary' => [
                'hostnames' => count($snapshot['hostnames'] ?? []),
                'tls_mode' => $snapshot['tls']['mode'] ?? null,
                'pools' => array_values($snapshot['pools'] ?? []),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
