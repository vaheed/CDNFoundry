<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgePool;
use App\Models\EdgeRevision;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileEdgeDomain implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $domainId)
    {
        $this->onQueue('runtime');
    }

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    public function handle(): void
    {
        $domain = Domain::query()->with('dnsRecords')->findOrFail($this->domainId);
        $revision = $domain->revision;
        $records = $domain->dnsRecords->where('mode', 'proxied')->sortBy('name')->values();
        $operation = Operation::query()->where('type', 'edge.domain_reconcile')->whereIn('status', ['pending', 'running'])
            ->where('input->domain_id', $domain->id)->latest()->first();
        $operation?->update(['status' => 'running', 'started_at' => now()]);
        if ($records->isEmpty()) {
            DomainEdgePlacement::query()->where('domain_id', $domain->id)->delete();
        } else {
            $pools = EdgePool::query()->where('enabled', true)->where('kind', 'shared')->orderBy('id')->get();
            if ($pools->isEmpty()) {
                throw new \RuntimeException('No enabled shared edge pool exists.');
            }
            $placement = DomainEdgePlacement::query()->firstOrCreate(['domain_id' => $domain->id], ['desired_revision' => $revision]);
            $target = $placement->target_pool_id !== null
                ? EdgePool::query()->whereKey($placement->target_pool_id)->where('enabled', true)->firstOrFail()
                : $pools[abs(crc32($domain->name)) % $pools->count()];
            $alreadyActive = $placement->state === 'active' && $placement->active_pool_id === $target->id
                && $placement->desired_revision === $revision && $domain->active_edge_revision === $revision;
            if (! $alreadyActive) {
                $placement->update(['target_pool_id' => $target->id, 'desired_revision' => $revision, 'state' => 'deploying', 'last_error' => null]);
            }
        }

        $snapshot = [
            'schema_version' => 1, 'domain_id' => $domain->id, 'domain' => $domain->name,
            'revision' => $revision, 'settings' => $domain->proxy_settings ?? self::defaults(),
            'hostnames' => $records->map(fn ($record) => ['hostname' => $record->name, 'type' => $record->type, 'ttl' => $record->ttl, 'origin' => $record->origin])->all(),
        ];
        $canonical = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $checksum = hash('sha256', $canonical);
        EdgeRevision::query()->updateOrCreate(['domain_id' => $domain->id, 'revision' => $revision], [
            'snapshot' => $snapshot, 'checksum' => $checksum, 'status' => 'validated', 'created_by' => $operation?->actor_id,
        ]);
        $activeEdges = Edge::query()->where('enabled', true)->whereNull('identity_revoked_at')->get();
        foreach ($activeEdges as $edge) {
            $payload = $records->isEmpty() ? ['domain' => $domain->name, 'revision' => $revision] : $snapshot;
            $artifactChecksum = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            EdgeArtifact::query()->firstOrCreate(
                ['edge_id' => $edge->id, 'domain_id' => $domain->id, 'revision' => $revision, 'kind' => $records->isEmpty() ? 'tombstone' : 'domain'],
                ['payload' => $payload, 'checksum' => $artifactChecksum, 'signature' => hash_hmac('sha256', $artifactChecksum, (string) config('edge.artifact_signing_key'))],
            );
        }
        if (Domain::query()->whereKey($domain->id)->value('revision') !== $revision) {
            $operation?->update(['status' => 'pending']);
            self::dispatch($domain->id);

            return;
        }
        $operation?->update(['status' => 'succeeded', 'result' => ['revision' => $revision, 'edges' => $activeEdges->count()], 'finished_at' => now()]);
    }

    public static function defaults(): array
    {
        return ['enabled' => true, 'redirect_https' => false, 'http_versions' => ['1.1', '2'], 'retry_count' => 0, 'maintenance' => null];
    }
}
