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
            'revision_changed_at' => $this->revision_changed_at?->toIso8601String(),
            'assigned_nameservers' => $this->resource->assignedNameservers(),
            'claim_status' => $this->resource->claimStatus(),
            'claim_expires_at' => $this->claim_expires_at?->toIso8601String(),
            'nameservers_verified_at' => $this->nameservers_verified_at?->toIso8601String(),
            'nameserver_check' => [
                'result' => $this->resource->ns_check_result,
                'last_checked_at' => $this->resource->ns_checked_at?->toIso8601String(),
                'next_check_at' => $this->resource->ns_check_next_at?->toIso8601String(),
                'observed_nameservers' => $this->resource->ns_observed,
                'delegation_lost_at' => $this->resource->delegation_lost_at?->toIso8601String(),
            ],
            'registration_expires_at' => $this->resource->registration_expires_at?->toIso8601String(),
            'registration_checked_at' => $this->resource->registration_checked_at?->toIso8601String(),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'deprovision_after' => $this->deprovision_after?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
