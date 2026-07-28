<?php

namespace App\Jobs;

use App\Actions\PlanDomainEdgeCells;
use App\Actions\PromoteReadyEdgePlacements;
use App\Enums\DomainLifecycleState;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgePool;
use App\Models\EdgePoolEndpoint;
use App\Models\EdgeRevision;
use App\Models\Operation;
use App\Support\ArtifactSigner;
use App\Support\CachePolicy;
use App\Support\ManagedCertificateNames;
use App\Support\PlatformSettings;
use App\Support\SecurityConfig;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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
        $targetPool = null;
        if ($records->isEmpty()) {
            DomainEdgePlacement::query()->where('domain_id', $domain->id)->delete();
            DomainEdgeCell::query()->where('domain_id', $domain->id)->delete();
        } else {
            $pools = EdgePool::query()->where('enabled', true)->where('kind', 'shared')->orderBy('id')->get();
            if ($pools->isEmpty()) {
                throw new \RuntimeException('No enabled shared edge pool exists.');
            }
            DomainEdgePlacement::query()->firstOrCreate(['domain_id' => $domain->id], ['desired_revision' => $revision]);
            $obsolete = false;
            $placement = DB::transaction(function () use ($domain, $pools, $revision, &$obsolete): DomainEdgePlacement {
                $placement = DomainEdgePlacement::query()->where('domain_id', $domain->id)->lockForUpdate()->firstOrFail();
                $currentDomain = Domain::query()->select(['revision', 'active_edge_revision'])->findOrFail($domain->id);
                if ($currentDomain->revision !== $revision || $placement->desired_revision > $revision) {
                    $obsolete = true;

                    return $placement;
                }
                $target = $placement->target_pool_id !== null
                    ? EdgePool::query()->whereKey($placement->target_pool_id)->where('enabled', true)->firstOrFail()
                    : ($placement->active_pool_id !== null
                        ? EdgePool::query()->whereKey($placement->active_pool_id)->where('enabled', true)->firstOrFail()
                        : $pools[abs(crc32($domain->name)) % $pools->count()]);
                $revisionIsActive = $placement->desired_revision === $revision && $currentDomain->active_edge_revision === $revision;
                $alreadyActive = $placement->state === 'active' && $placement->active_pool_id === $target->id && $revisionIsActive;
                $alreadyDraining = $placement->state === 'draining' && $placement->target_pool_id === $target->id && $revisionIsActive;
                if (! $alreadyActive && ! $alreadyDraining) {
                    $placement->update([
                        'target_pool_id' => $target->id,
                        'desired_revision' => $revision,
                        'state' => 'deploying',
                        'drain_after' => null,
                        'last_error' => null,
                    ]);
                }

                return $placement;
            });
            if ($obsolete) {
                $operation?->update(['status' => 'pending']);
                self::dispatch($domain->id);

                return;
            }
        }

        $poolNames = $placement === null ? [] : EdgePool::query()
            ->whereIn('id', array_values(array_filter([$placement->active_pool_id, $placement->target_pool_id])))
            ->orderBy('name')->pluck('name')->all();

        if ($placement !== null) {
            $targetPool = EdgePool::query()->findOrFail($placement->target_pool_id ?? $placement->active_pool_id);
            PlanDomainEdgeCells::execute($domain, $placement, $targetPool);
        }

        $blockedAddresses = Edge::query()->pluck('management_ipv4')->merge(Edge::query()->pluck('management_ipv6'))
            ->merge(EdgePoolEndpoint::query()->pluck('ipv4'))->merge(EdgePoolEndpoint::query()->pluck('ipv6'))
            ->merge(EdgePool::query()->pluck('anycast_ipv4'))->merge(EdgePool::query()->pluck('anycast_ipv6'))
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
                ...CachePolicy::normalize($domain->cache_settings),
                'profile_name' => $targetPool === null ? 'standard' : $targetPool->cache_profile,
                'profile' => $targetPool === null ? CachePolicy::profile('standard') : CachePolicy::profile($targetPool->cache_profile),
                'epoch' => $domain->cache_epoch,
                'development_mode_until' => $domain->cache_development_mode_until?->isFuture() ? $domain->cache_development_mode_until->timestamp : null,
            ],
            'security' => SecurityConfig::compile($domain),
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
        $activeEdgesQuery = Edge::query()->where('enabled', true)->whereNull('identity_revoked_at');
        $hasCellPlacements = ! $records->isEmpty() && DomainEdgeCell::query()->where('domain_id', $domain->id)->exists();
        if ($hasCellPlacements) {
            $deliveryEdgeIds = DomainEdgeCell::query()->where('domain_id', $domain->id)->pluck('edge_id')
                ->merge(EdgeArtifact::query()->where('domain_id', $domain->id)->pluck('edge_id'))->unique();
            $activeEdgesQuery->whereIn('id', $deliveryEdgeIds);
        }
        $activeEdges = $activeEdgesQuery->get();
        $published = DB::transaction(function () use ($activeEdges, $checksum, $domain, $hasCellPlacements, $operation, $records, $revision, $snapshot): bool {
            $currentDomain = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            if ($currentDomain->revision !== $revision) {
                return false;
            }
            EdgeRevision::query()->updateOrCreate(['domain_id' => $domain->id, 'revision' => $revision], [
                'snapshot' => $snapshot, 'checksum' => $checksum, 'status' => 'validated', 'created_by' => $operation?->actor_id,
            ]);
            foreach ($activeEdges as $edge) {
                $cellNames = $hasCellPlacements ? DomainEdgeCell::query()->where('domain_id', $domain->id)->where('edge_id', $edge->id)
                    ->with(['activeCell:id,name', 'targetCell:id,name'])->get()
                    ->flatMap(fn (DomainEdgeCell $row) => [$row->activeCell?->name, $row->targetCell?->name])
                    ->filter()->unique()->sort()->values()->all() : [];
                $tombstone = $records->isEmpty() || ($hasCellPlacements && $cellNames === []);
                $payload = $tombstone ? ['domain' => $domain->name, 'revision' => $revision] : [
                    ...$snapshot,
                    ...($hasCellPlacements ? ['cells' => $cellNames] : []),
                ];
                $artifactChecksum = hash('sha256', ArtifactSigner::encode($payload));
                EdgeArtifact::query()->firstOrCreate([
                    'edge_id' => $edge->id, 'domain_id' => $domain->id, 'revision' => $revision,
                    'kind' => $tombstone ? 'tombstone' : 'domain', 'checksum' => $artifactChecksum,
                ], ['payload' => $payload, 'signature' => ArtifactSigner::sign($artifactChecksum)]);
            }

            return true;
        });
        if (! $published) {
            $operation?->update(['status' => 'pending']);
            self::dispatch($domain->id);

            return;
        }
        $operation?->update([
            'status' => $records->isEmpty() && $activeEdges->isEmpty() ? 'succeeded' : 'running',
            'result' => ['revision' => $revision, 'edges' => $activeEdges->count(), 'awaiting_acknowledgements' => true],
            'finished_at' => $records->isEmpty() && $activeEdges->isEmpty() ? now() : null,
        ]);
        PromoteReadyEdgePlacements::execute();
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
