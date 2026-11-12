<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The settings, tokens, and URLs of a channel are never part of its representation. */
class NotificationChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'type' => $this->type, 'name' => $this->name, 'destination' => $this->destination_hint,
            'events' => $this->events, 'status' => $this->status, 'paused_reason' => $this->paused_reason,
            'consecutive_failures' => $this->consecutive_failures, 'last_error' => $this->last_error,
            'last_success_at' => $this->last_success_at?->toIso8601String(), 'last_failure_at' => $this->last_failure_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
