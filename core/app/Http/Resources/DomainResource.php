<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'lifecycle_state' => $this->lifecycle_state->value,
            'revision' => $this->revision,
            'nameservers_verified_at' => $this->nameservers_verified_at?->toIso8601String(),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'deprovision_after' => $this->deprovision_after?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
