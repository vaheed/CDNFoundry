<?php

namespace App\Models;

use App\Enums\DomainLifecycleState;
use App\Jobs\DispatchOriginTest;
use App\Support\ArtifactSigner;
use App\Support\OriginData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

#[Fillable(['domain_id', 'type', 'name', 'content', 'content_hash', 'ttl', 'priority', 'weight', 'port', 'mode', 'geo_config', 'origin', 'origin_health'])]
class DnsRecord extends Model
{
    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['origin', 'mode'])) {
                $record->origin_health = null;
            }
        });
    }

    public function requestOriginTest(User $actor, string $role = 'primary', array $edgeIds = []): Operation
    {
        $authorize = function (Domain $domain) use ($actor): void {
            abort_if($actor->isDisabled(), 403);
            Gate::forUser($actor)->authorize('update', $domain);
            abort_unless($domain->lifecycle_state === DomainLifecycleState::Active
                && $domain->nameservers_verified_at !== null && $domain->disabled_at === null,
                409, 'Origin tests require an active, verified domain.');
        };
        $this->refresh();
        $authorize($this->domain()->firstOrFail());
        abort_unless($this->mode === 'proxied', 409, 'The DNS record is not proxied.');
        abort_unless(in_array($role, ['primary', 'backup'], true) && count($edgeIds) <= 20, 422);
        $origin = $role === 'backup' ? ($this->origin['backup'] ?? null) : $this->origin;
        abort_unless(is_array($origin), 422, 'The selected origin does not exist.');
        $checksum = hash('sha256', ArtifactSigner::encode($origin));
        // Resolve before taking database locks. A changed target is rejected
        // under lock rather than combining its settings with these addresses.
        $addresses = OriginData::resolveAndValidate($origin['host']);
        $operation = DB::transaction(function () use ($actor, $role, $edgeIds, $authorize, $checksum, $addresses): Operation {
            $domain = Domain::query()->lockForUpdate()->findOrFail($this->domain_id);
            $authorize($domain);
            $record = $domain->dnsRecords()->lockForUpdate()->findOrFail($this->id);
            $current = $role === 'backup' ? ($record->origin['backup'] ?? null) : $record->origin;
            abort_unless($record->mode === 'proxied' && is_array($current)
                && hash_equals($checksum, hash('sha256', ArtifactSigner::encode($current))),
                409, 'The origin changed while preparing the test; request a new test.');

            return Operation::query()->create(['type' => 'edge.origin_test', 'status' => 'pending', 'actor_id' => $actor->id, 'input' => [
                'domain_id' => $domain->id, 'record_id' => $record->id, 'origin_role' => $role,
                'origin_checksum' => $checksum, 'addresses' => $addresses, 'edge_ids' => $edgeIds,
            ]]);
        });
        DispatchOriginTest::dispatch($operation->id)->afterCommit();

        return $operation;
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    protected function casts(): array
    {
        return ['geo_config' => 'array', 'origin' => 'array', 'origin_health' => 'array'];
    }
}
