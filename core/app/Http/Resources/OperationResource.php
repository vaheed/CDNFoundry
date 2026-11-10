<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() === true;

        return [
            'id' => $this->id, 'type' => $this->type, 'status' => $this->status,
            'domain_id' => $this->input['domain_id'] ?? null,
            'input' => $this->when($isAdmin, $this->input),
            'result' => $this->result, 'error' => $this->error, 'attempts' => $this->attempts,
            'created_at' => $this->created_at?->toIso8601String(), 'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'acknowledged_at' => $this->resource->getAttribute('acknowledged_at')?->toIso8601String(),
        ];
    }
}
