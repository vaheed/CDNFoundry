<?php

namespace App\Models;

use App\Enums\DomainLifecycleState;
use App\Support\ArtifactSigner;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class EdgeTask extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function currentOriginRecord(bool $lock = false): ?DnsRecord
    {
        $operation = Operation::query()->find($this->payload['operation_id'] ?? null);
        $domain = Domain::query()->find($this->payload['domain_id'] ?? null);
        if ($operation === null || $domain === null || ! in_array($operation->status, ['pending', 'running'], true)
            || $domain->lifecycle_state !== DomainLifecycleState::Active || $domain->nameservers_verified_at === null || $domain->disabled_at !== null) {
            return null;
        }
        $actor = $operation->actor;
        if ($actor !== null ? ($actor->isDisabled() || ! Gate::forUser($actor)->allows('update', $domain)) : ($operation->input['scheduled'] ?? false) !== true) {
            return null;
        }
        $record = DnsRecord::query()->where('domain_id', $domain->id)->where('mode', 'proxied')
            ->when($lock, fn ($query) => $query->lockForUpdate())->find($this->payload['record_id'] ?? null);
        $origin = ($this->payload['origin_role'] ?? 'primary') === 'backup' ? ($record?->origin['backup'] ?? null) : $record?->origin;
        if (! is_array($origin) || ! is_array($this->payload['origin'] ?? null)
            || ! hash_equals(hash('sha256', ArtifactSigner::encode($origin)), hash('sha256', ArtifactSigner::encode($this->payload['origin'])))) {
            return null;
        }

        return $record;
    }

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'available_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
