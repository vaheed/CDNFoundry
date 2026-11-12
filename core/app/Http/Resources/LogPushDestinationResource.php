<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Credentials and endpoints are never part of the representation; only the masked destination is. */
class LogPushDestinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'domain_id' => $this->domain_id, 'kind' => $this->kind, 'name' => $this->name, 'destination' => $this->destination_hint,
            'datasets' => $this->datasets, 'truncate_client_ip' => $this->truncate_client_ip, 'status' => $this->status,
            'last_delivery_at' => $this->last_delivery_at?->toIso8601String(), 'lag_seconds' => $this->lag_seconds,
            'consecutive_failures' => $this->consecutive_failures, 'failures_total' => $this->failures_total, 'rows_pushed' => $this->rows_pushed,
            'bytes_pushed' => $this->bytes_pushed, 'last_error' => $this->last_error, 'last_gap_at' => $this->last_gap_at?->toIso8601String(),
            'next_retry_at' => $this->next_run_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
