<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Http\Controllers\CacheController;
use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use App\Models\EdgeRevision;
use App\Models\Operation;
use App\Support\ArtifactSigner;
use App\Support\ManagedCertificateNames;
use App\Support\PlatformSettings;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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
        $domain = Domain::query()->with(['dnsRecords', 'activeTlsCertificate', 'tlsCertificates'])->findOrFail($this->domainId);
        $revision = $domain->revision;
        $retiring = $domain->lifecycle_state === DomainLifecycleState::Deprovisioning && $domain->deprovision_after?->isPast();
        $records = $retiring ? collect() : $domain->dnsRecords->where('mode', 'proxied')->sortBy('name')->values();
        $operation = Operation::query()->where('type', 'edge.domain_reconcile')->whereIn('status', ['pending', 'running'])
            ->where('input->domain_id', $domain->id)->latest()->first();
        $operation?->update(['status' => 'running', 'started_at' => now()]);
        $placement = null;
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
                : ($placement->active_pool_id !== null
                    ? EdgePool::query()->whereKey($placement->active_pool_id)->where('enabled', true)->firstOrFail()
                    : $pools[abs(crc32($domain->name)) % $pools->count()]);
            $alreadyActive = $placement->state === 'active' && $placement->active_pool_id === $target->id
                && $placement->desired_revision === $revision && $domain->active_edge_revision === $revision;
            if (! $alreadyActive) {
                $placement->update(['target_pool_id' => $target->id, 'desired_revision' => $revision, 'state' => 'deploying', 'last_error' => null]);
            }
        }

        $poolNames = $placement === null ? [] : EdgePool::query()
            ->whereIn('id', array_values(array_filter([$placement->active_pool_id, $placement->target_pool_id])))
            ->orderBy('name')->pluck('name')->all();

        $blockedAddresses = Edge::query()->pluck('ipv4')->merge(Edge::query()->pluck('ipv6'))
            ->merge(EdgeCell::query()->pluck('service_ipv4'))->merge(EdgeCell::query()->pluck('service_ipv6'))
            ->filter()->merge(app(PlatformSettings::class)->get('origin_safety', 'blocked_origin_addresses'))->unique()->values()->all();
        $proxySettings = is_array($domain->proxy_settings) ? $domain->proxy_settings : self::defaults();
        $proxySettings['enabled'] = $domain->lifecycle_state === DomainLifecycleState::Active
            && ($proxySettings['enabled'] ?? true);
        $tlsCertificates = $domain->tls_mode === 'disabled' ? collect() : ($domain->tls_mode === 'custom'
            ? collect([$domain->activeTlsCertificate])->filter()
            : $domain->tlsCertificates->where('kind', 'managed')->where('status', 'active')->filter(fn ($certificate) => $certificate->expires_at->isFuture()));
        $certificatePayload = fn ($certificate): array => [
            'id' => $certificate->id, 'certificate_pem' => $certificate->certificate_pem,
            'chain_pem' => $certificate->chain_pem, 'private_key_pem' => $certificate->private_key_ciphertext,
            'expires_at' => $certificate->expires_at->timestamp, 'names' => $certificate->names,
        ];
        $snapshot = [
            'schema_version' => 1, 'domain_id' => $domain->id, 'domain' => $domain->name,
            'revision' => $revision, 'settings' => $proxySettings,
            'cache' => [
                ...($domain->cache_settings ?? CacheController::defaults()),
                'epoch' => $domain->cache_epoch,
                'development_mode_until' => $domain->cache_development_mode_until?->isFuture() ? $domain->cache_development_mode_until->timestamp : null,
            ],
            'tls' => [
                'mode' => $domain->tls_mode,
                'certificate' => $domain->activeTlsCertificate !== null && $domain->activeTlsCertificate->expires_at->isFuture()
                    ? $certificatePayload($domain->activeTlsCertificate) : null,
                'certificates' => $tlsCertificates->map($certificatePayload)->values()->all(),
            ],
            'pools' => $poolNames,
            'hostnames' => $records->map(function ($record) use ($blockedAddresses, $tlsCertificates): array {
                $origin = $record->origin;
                $origin['private_allowlist'] = app(PlatformSettings::class)->get('origin_safety', 'private_origin_allowlist');
                $origin['blocked_networks'] = app(PlatformSettings::class)->get('origin_safety', 'blocked_origin_networks');
                $origin['blocked_addresses'] = $blockedAddresses;

                $certificate = $tlsCertificates->first(fn ($candidate): bool => ManagedCertificateNames::coveredBy($record->name, $candidate->names));

                return [
                    'hostname' => $record->name, 'type' => $record->type, 'ttl' => $record->ttl,
                    'tls_certificate_id' => $certificate?->id, 'origin' => $origin,
                ];
            })->all(),
        ];
        $canonical = ArtifactSigner::encode($snapshot);
        if (strlen($canonical) > app(PlatformSettings::class)->integer('edge_runtime', 'max_domain_artifact_bytes')) {
            throw new \RuntimeException('The domain edge artifact exceeds the configured per-domain byte limit.');
        }
        $checksum = hash('sha256', $canonical);
        EdgeRevision::query()->updateOrCreate(['domain_id' => $domain->id, 'revision' => $revision], [
            'snapshot' => $snapshot, 'checksum' => $checksum, 'status' => 'validated', 'created_by' => $operation?->actor_id,
        ]);
        $activeEdges = Edge::query()->where('enabled', true)->whereNull('identity_revoked_at')->get();
        foreach ($activeEdges as $edge) {
            $payload = $records->isEmpty() ? ['domain' => $domain->name, 'revision' => $revision] : $snapshot;
            $artifactChecksum = hash('sha256', ArtifactSigner::encode($payload));
            EdgeArtifact::query()->firstOrCreate([
                'edge_id' => $edge->id, 'domain_id' => $domain->id, 'revision' => $revision,
                'kind' => $records->isEmpty() ? 'tombstone' : 'domain', 'checksum' => $artifactChecksum,
            ], ['payload' => $payload, 'signature' => ArtifactSigner::sign($artifactChecksum)]);
        }
        if (Domain::query()->whereKey($domain->id)->value('revision') !== $revision) {
            $operation?->update(['status' => 'pending']);
            self::dispatch($domain->id);

            return;
        }
        $operation?->update([
            'status' => $records->isEmpty() && $activeEdges->isEmpty() ? 'succeeded' : 'running',
            'result' => ['revision' => $revision, 'edges' => $activeEdges->count(), 'awaiting_acknowledgements' => true],
            'finished_at' => $records->isEmpty() && $activeEdges->isEmpty() ? now() : null,
        ]);
    }

    public static function defaults(): array
    {
        return [...app(PlatformSettings::class)->values('proxy_defaults'), 'maintenance' => null];
    }

    public function failed(Throwable $exception): void
    {
        Operation::query()->where('type', 'edge.domain_reconcile')->whereIn('status', ['pending', 'running'])
            ->where('input->domain_id', $this->domainId)->update([
                'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now(),
            ]);
    }
}
