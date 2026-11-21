<?php

namespace App\Http\Resources;

use App\Models\RequestRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RequestRule */
class RequestRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'name' => $this->name,
            'enabled' => $this->enabled, 'priority' => $this->priority,
            'conditions' => $this->conditions, 'action' => $this->action, 'preset' => $this->preset,
            'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
