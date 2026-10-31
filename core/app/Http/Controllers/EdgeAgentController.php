<?php

namespace App\Http\Controllers;

use App\Actions\PromoteReadyEdgePlacements;
use App\Jobs\AdvanceFleetRollout;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcileEdgeDomain;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\CachePurge;
use App\Models\DnsCluster;
use App\Models\DnsRecord;
use App\Models\DnsZoneChange;
use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgePoolEndpoint;
use App\Models\EdgeProbeResult;
use App\Models\EdgeTask;
use App\Models\FleetRolloutEdge;
use App\Models\Operation;
use App\Models\SecurityEvent;
use App\Models\TlsCertificate;
use App\Support\ArtifactSigner;
use App\Support\EdgeCertificateAuthority;
use App\Support\EdgeKeyEnvelope;
use App\Support\EdgeRejectionReasons;
use App\Support\OriginPolicy;
use App\Support\PlatformSettings;
use App\Support\PowerDnsClient;
use App\Support\RuntimeVersions;
use App\Support\SecurityConfig;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EdgeAgentController extends Controller
{
    public function gatewayConfig(Request $request): JsonResponse
    {
        /** @var Edge $edge */
        $edge = $request->attributes->get('edge');
        $endpoints = $edge->poolEndpoints()->with('pool')->where('withdrawn', false)
            ->whereHas('pool', fn ($query) => $query->where('enabled', true)->where('withdrawn', false))->orderBy('id')->get();
        $bindings = $endpoints->flatMap(function (EdgePoolEndpoint $endpoint) use ($edge): array {
            $cells = $edge->cells()->where('edge_pool_id', $endpoint->edge_pool_id)->where('drained', false)->orderBy('slot')->get();
            if ($cells->isEmpty()) {
                return [];
            }
            $targets = $cells->map(fn ($cell): array => ['name' => $cell->name, 'http' => '127.0.0.1:'.$cell->http_port, 'https' => '127.0.0.1:'.$cell->https_port])->all();

            return collect([$endpoint->effectiveAddress('ipv4'), $endpoint->effectiveAddress('ipv6')])->filter()->map(fn (string $address): array => ['address' => $address, 'pool' => $endpoint->pool->name, 'cells' => $targets])->all();
        })->values();

        $revision = max(
            (int) $endpoints->max('revision'),
            (int) $endpoints->max('gateway_revision'),
            (int) $edge->active_sequence,
        );

        return response()->json(['data' => ['revision' => $revision, 'bindings' => $bindings]]);
    }

    /** The listeners this PoP should probe from the outside: every other PoP's own addresses. */
    public function probeTargets(Request $request): JsonResponse
    {
        /** @var Edge $edge */
        $edge = $request->attributes->get('edge');
        $targets = EdgePoolEndpoint::query()->with('edge')->where('edge_id', '!=', $edge->id)->where('withdrawn', false)
            ->whereHas('edge', fn ($query) => $query->where('enabled', true)->whereNotNull('registered_at')->whereNull('identity_revoked_at'))
            ->orderBy('id')->limit(256)->get()
            ->flatMap(fn (EdgePoolEndpoint $endpoint): array => collect([$endpoint->ipv4, $endpoint->ipv6])->filter()
                ->map(fn (string $address): array => ['edge_id' => $endpoint->edge_id, 'address' => $address])->all())
            ->unique(fn (array $target): string => $target['edge_id'].'|'.$target['address'])->take(128)->values();

        return response()->json(['data' => ['targets' => $targets, 'ports' => ['http' => 80, 'tls' => 443]]]);
    }

    public function probeResults(Request $request): JsonResponse
    {
        /** @var Edge $edge */
        $edge = $request->attributes->get('edge');
        $data = $request->validate([
            'results' => ['required', 'array', 'max:128'],
            'results.*.edge_id' => ['required', 'uuid'],
            'results.*.address' => ['required', 'ip'],
            'results.*.ok' => ['required', 'boolean'],
            'results.*.detail' => ['nullable', 'string', 'max:120'],
        ]);
        $known = EdgePoolEndpoint::query()->where('edge_id', '!=', $edge->id)->get(['edge_id', 'ipv4', 'ipv6'])
            ->flatMap(fn (EdgePoolEndpoint $endpoint): array => collect([$endpoint->ipv4, $endpoint->ipv6])->filter()->map(fn (string $address): string => $endpoint->edge_id.'|'.$address)->all())
            ->flip();
        $accepted = 0;
        foreach ($data['results'] as $result) {
            if (! $known->has($result['edge_id'].'|'.$result['address'])) {
                continue;
            }
            EdgeProbeResult::query()->updateOrCreate(
                ['observer_edge_id' => $edge->id, 'target_edge_id' => $result['edge_id'], 'address' => $result['address']],
                ['ok' => $result['ok'], 'detail' => $result['detail'] ?? null, 'checked_at' => now()],
            );
            $accepted++;
        }
        ReconcilePlatformDnsIdentity::dispatchForProbeChange();

        return response()->json(['data' => ['accepted' => $accepted]]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'edge_id' => ['required', 'uuid'], 'bootstrap_token' => ['required', 'string', 'size:64'],
            'agent_version' => ['required', 'string', 'max:40'], 'certificate_request' => ['required', 'string', 'max:8192'],
        ]);
        $newlyRegistered = false;
        $identity = DB::transaction(function () use ($data, &$newlyRegistered): array {
            $edge = Edge::query()->lockForUpdate()->findOrFail($data['edge_id']);
            $tokenMatches = $edge->bootstrap_token_hash !== null
                && hash_equals($edge->bootstrap_token_hash, hash('sha256', $data['bootstrap_token']));
            abort_unless($tokenMatches, 401, 'The bootstrap token is invalid or already used.');
            $csrHash = hash('sha256', $data['certificate_request']);
            if ($edge->bootstrap_consumed_at !== null) {
                $replayable = $edge->bootstrap_consumed_at->gte(now()->subMinutes(10))
                    && hash_equals((string) $edge->identity_csr_hash, $csrHash)
                    && filled($edge->identity_certificate)
                    && filled($edge->identity_certificate_serial)
                    && $edge->identity_certificate_expires_at?->isFuture();
                abort_unless($replayable, 401, 'The bootstrap token is invalid or already used.');

                return [
                    'edge_id' => $edge->id, 'identity_certificate' => $edge->identity_certificate,
                    'identity_certificate_serial' => $edge->identity_certificate_serial,
                    'identity_certificate_expires_at' => $edge->identity_certificate_expires_at->toIso8601String(),
                    'signing_public_key' => ArtifactSigner::publicKey(),
                ];
            }
            try {
                $signed = EdgeCertificateAuthority::sign($data['certificate_request'], $edge->id);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['certificate_request' => $exception->getMessage()]);
            }
            $edge->update([
                'identity_hash' => null, 'identity_csr_hash' => $csrHash,
                'identity_certificate' => $signed['certificate'], 'bootstrap_consumed_at' => now(),
                'identity_certificate_serial' => $signed['serial'],
                'identity_certificate_expires_at' => CarbonImmutable::parse($signed['expires_at']),
                'identity_revoked_at' => null, 'registered_at' => now(), 'agent_version' => $data['agent_version'],
            ]);
            $newlyRegistered = true;

            return [
                'edge_id' => $edge->id, 'identity_certificate' => $signed['certificate'],
                'identity_certificate_serial' => $signed['serial'], 'identity_certificate_expires_at' => $signed['expires_at'],
                'signing_public_key' => ArtifactSigner::publicKey(),
            ];
        });

        if ($newlyRegistered) {
            $operation = Operation::query()->where('type', 'edge.global_reconcile')->whereIn('status', ['pending', 'running'])->first()
                ?? Operation::query()->create(['actor_id' => null, 'type' => 'edge.global_reconcile', 'status' => 'pending', 'input' => ['reason' => 'edge_registered']]);
            ReconcileAllEdgeDomains::dispatch($operation->id)->afterCommit();
        }

        return response()->json(['data' => $identity], 201);
    }

    /**
     * Sign a new identity certificate for an authenticated edge. The current
     * certificate stays valid; the new one becomes current the first time the
     * edge authenticates with it.
     */
    public function renewIdentity(Request $request): JsonResponse
    {
        /** @var Edge $edge */
        $edge = $request->attributes->get('edge');
        $data = $request->validate(['certificate_request' => ['required', 'string', 'max:8192']]);
        $identity = DB::transaction(function () use ($edge, $data): array {
            $locked = Edge::query()->lockForUpdate()->findOrFail($edge->id);
            $csrHash = hash('sha256', $data['certificate_request']);
            if ($locked->pending_identity_certificate !== null && hash_equals((string) $locked->pending_identity_csr_hash, $csrHash)
                && $locked->pending_identity_certificate_expires_at?->isFuture()) {
                return ['identity_certificate' => $locked->pending_identity_certificate, 'identity_certificate_serial' => $locked->pending_identity_certificate_serial,
                    'identity_certificate_expires_at' => $locked->pending_identity_certificate_expires_at->toIso8601String()];
            }
            try {
                $signed = EdgeCertificateAuthority::sign($data['certificate_request'], $locked->id);
            } catch (RuntimeException $exception) {
                throw ValidationException::withMessages(['certificate_request' => $exception->getMessage()]);
            }
            $locked->update([
                'pending_identity_certificate' => $signed['certificate'], 'pending_identity_certificate_serial' => $signed['serial'],
                'pending_identity_certificate_expires_at' => CarbonImmutable::parse($signed['expires_at']), 'pending_identity_csr_hash' => $csrHash,
            ]);

            return ['identity_certificate' => $signed['certificate'], 'identity_certificate_serial' => $signed['serial'], 'identity_certificate_expires_at' => $signed['expires_at']];
        });

        return response()->json(['data' => $identity], 201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $data = $request->validate([
            'agent_version' => ['required', 'string', 'max:40'], 'listener_ready' => ['required', 'boolean'],
            'runtime_versions' => ['sometimes', 'required', 'array', 'size:4'],
            'runtime_versions.gateway' => ['required_with:runtime_versions', 'string', 'max:255'],
            'runtime_versions.agent' => ['required_with:runtime_versions', 'string', 'max:255'],
            'runtime_versions.normal_cell' => ['required_with:runtime_versions', 'string', 'max:255'],
            'runtime_versions.waf_cell' => ['required_with:runtime_versions', 'string', 'max:255'],
            'active_sequence' => ['required', 'integer', 'min:0'], 'cells' => ['required', 'array', 'max:32'],
            'identity_expires_at' => ['sometimes', 'integer', 'min:0'],
            'dns' => ['sometimes', 'array', 'max:8'],
            'dns.cursor' => ['required_with:dns', 'integer', 'min:0'],
            'dns.catalog_serial' => ['sometimes', 'integer', 'min:0'],
            'dns.latest' => ['sometimes', 'integer', 'min:0'],
            'dns.zones' => ['sometimes', 'integer', 'min:0'],
            'dns.pending' => ['sometimes', 'integer', 'min:0'],
            'dns.error' => ['sometimes', 'nullable', 'string', 'max:500'],
            'gateway' => ['sometimes', 'array', 'max:12'],
            'gateway.ready' => ['required_with:gateway', 'boolean'],
            'gateway.active_revision' => ['sometimes', 'integer', 'min:0'],
            'gateway.routes' => ['sometimes', 'integer', 'between:0,200000'],
            'gateway.listeners' => ['sometimes', 'integer', 'between:0,128'],
            'gateway.connections_active' => ['sometimes', 'integer', 'min:0'],
            'gateway.connections_accepted' => ['sometimes', 'integer', 'min:0'],
            'gateway.connections_rejected' => ['sometimes', 'integer', 'min:0'],
            'gateway.errors' => ['sometimes', 'integer', 'min:0'],
            'gateway.candidate_rejections' => ['sometimes', 'integer', 'min:0'],
            'cells.*.name' => ['required', 'regex:/^cell-(0[1-9]|[12][0-9]|3[0-2])$/', 'distinct'], 'cells.*.status' => ['required', 'in:ready,degraded,drained,stopped'],
            'cells.*.capacity' => ['required', 'array', 'max:20'], 'noisy_domains' => ['sometimes', 'array', 'max:20'],
            'noisy_domains.*.domain_id' => ['required', 'integer', 'exists:domains,id'],
            'noisy_domains.*.hostname' => ['nullable', 'string', 'max:253'],
            'noisy_domains.*.reason_code' => ['required', 'string', 'in:'.implode(',', SecurityConfig::REASON_CODES)],
            'noisy_domains.*.count' => ['required', 'integer', 'between:1,2147483647'],
            'noisy_domains.*.occurred_at' => ['required', 'integer', 'min:0'],
            'passive_origins' => ['sometimes', 'array', 'max:100'],
            'passive_origins.*.domain' => ['required', 'string', 'max:253'],
            'passive_origins.*.hostname' => ['required', 'string', 'max:253'],
            'passive_origins.*.failure_count' => ['required', 'integer', 'between:1,2147483647'],
            'passive_origins.*.last_status' => ['required', 'integer', 'between:0,599'],
            'passive_origins.*.last_failed_at' => ['required', 'integer', 'min:0'],
        ]);
        $knownCells = $edge->cells()->pluck('id', 'name');
        if (isset($data['runtime_versions'])) {
            RuntimeVersions::validate($data['runtime_versions']);
        }
        foreach ($data['cells'] as $index => $cell) {
            if (! $knownCells->has($cell['name'])) {
                throw ValidationException::withMessages(["cells.$index.name" => 'The cell is not assigned to this edge.']);
            }
        }
        // Artifact retention may remove an already acknowledged row. The edge's
        // durable active sequence remains valid evidence, while values beyond
        // both durable acknowledgement and retained issued artifacts are not.
        $latestIssuedSequence = max((int) $edge->active_sequence, (int) $edge->artifacts()->max('sequence'));
        if ($data['active_sequence'] > $latestIssuedSequence) {
            throw ValidationException::withMessages(['active_sequence' => 'The active sequence was not issued to this edge.']);
        }
        $oldCellRouting = $edge->cells()->orderBy('id')->get(['id', 'status', 'drained'])->toJson();
        $wasRoutable = $edge->enabled && ! $edge->drained && $edge->last_heartbeat_at?->gte(now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds')))
            && ($edge->capacity['listener_ready'] ?? false);
        foreach ($data['cells'] as $cell) {
            $edge->cells()->where('name', $cell['name'])->limit(1)->update(['status' => $cell['status'], 'capacity' => $cell['capacity']]);
        }
        $reportedNames = collect($data['cells'])->pluck('name');
        $edge->cells()->whereNotIn('name', $reportedNames)->whereNotNull('edge_pool_id')->update(['status' => 'degraded', 'capacity' => null]);
        $edge->cells()->whereNotIn('name', $reportedNames)->whereNull('edge_pool_id')->update(['status' => 'stopped', 'capacity' => null]);
        $computedReady = $edge->cells()->whereNotNull('edge_pool_id')->where('drained', false)->where('status', 'ready')->exists();
        $listenerReady = $data['listener_ready'] && $computedReady;
        $edge->update([
            'last_heartbeat_at' => now(), 'agent_version' => $data['agent_version'],
            ...(isset($data['identity_expires_at']) ? ['reported_identity_expires_at' => CarbonImmutable::createFromTimestamp($data['identity_expires_at'])] : []),
            // Evaluate against the durable row, not the middleware's stale model.
            'active_sequence' => DB::raw('CASE WHEN active_sequence < '.(int) $data['active_sequence'].' THEN '.(int) $data['active_sequence'].' ELSE active_sequence END'),
            'bootstrap_token_hash' => null, 'bootstrap_consumed_at' => null,
            'capacity' => array_merge($edge->capacity ?? [], [
                'listener_ready' => $listenerReady, 'gateway' => $data['gateway'] ?? null,
                // Zone-transfer progress of this node's DNS service: the last change it has pulled.
                ...(isset($data['dns']) ? ['dns' => [...$data['dns'], 'reported_at' => now()->toIso8601String()]] : []),
                'cells' => $data['cells'], 'noisy_domains' => $data['noisy_domains'] ?? [],
            ]),
            ...(isset($data['runtime_versions']) ? ['runtime_versions' => $data['runtime_versions'], 'runtime_versions_reported_at' => now()] : []),
        ]);
        $edge->refresh();
        $endpointRoutingChanged = false;
        if (isset($data['gateway'])) {
            $gatewayReady = (bool) ($data['gateway']['ready'] ?? false);
            $gatewayRevision = (int) ($data['gateway']['active_revision'] ?? 0);
            $edge->poolEndpoints()->each(function (EdgePoolEndpoint $endpoint) use (&$endpointRoutingChanged, $gatewayReady, $gatewayRevision): void {
                $previousState = $endpoint->gateway_state;
                $previousRevision = $endpoint->gateway_revision;
                $endpoint->update([
                    'gateway_state' => $gatewayReady && $gatewayRevision >= $endpoint->revision ? 'ready' : 'degraded',
                    'gateway_revision' => max($endpoint->gateway_revision, $gatewayRevision),
                    'gateway_acknowledged_at' => $gatewayReady ? now() : $endpoint->gateway_acknowledged_at,
                    'readiness_reason' => $gatewayReady && $gatewayRevision >= $endpoint->revision ? 'ready' : 'gateway_not_acknowledged',
                ]);
                $endpointRoutingChanged = $endpointRoutingChanged
                    || $previousState !== $endpoint->gateway_state
                    || $previousRevision !== $endpoint->gateway_revision;
            });
        }
        $isRoutable = $edge->enabled && ! $edge->drained && $listenerReady;
        $newCellRouting = $edge->cells()->orderBy('id')->get(['id', 'status', 'drained'])->toJson();
        if ($wasRoutable !== $isRoutable || $oldCellRouting !== $newCellRouting || $endpointRoutingChanged) {
            if ($oldCellRouting !== $newCellRouting) {
                $edge->poolEndpoints()->update([
                    'revision' => DB::raw('revision + 1'),
                    'gateway_state' => 'pending',
                    'readiness_reason' => 'gateway_not_acknowledged',
                ]);
            }
            ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
        }
        foreach ($data['passive_origins'] ?? [] as $failure) {
            DnsRecord::query()->where('name', $failure['hostname'])->whereHas('domain', fn ($query) => $query->where('name', $failure['domain'])->where('lifecycle_state', 'active')
                ->whereHas('edgeCells', fn ($cells) => $cells->where('edge_id', $edge->id)
                    ->where(fn ($assigned) => $assigned->whereNotNull('active_cell_id')->orWhereNotNull('target_cell_id'))))
                ->where('mode', 'proxied')->limit(1)->update(['origin_health' => [
                    'status' => 'unhealthy', 'source' => 'passive', 'edge_id' => $edge->id,
                    'failure_count' => $failure['failure_count'], 'http_status' => $failure['last_status'] ?: null,
                    'reported_at' => now()->toIso8601String(),
                ]]);
        }
        foreach ($data['noisy_domains'] ?? [] as $event) {
            DB::transaction(function () use ($event, $edge): void {
                $domain = Domain::query()->where('lifecycle_state', 'active')
                    ->whereHas('edgeCells', fn ($cells) => $cells->where('edge_id', $edge->id)
                        ->where(fn ($assigned) => $assigned->whereNotNull('active_cell_id')->orWhereNotNull('target_cell_id')))
                    ->lockForUpdate()->find($event['domain_id']);
                if ($domain === null || ! $domain->dnsRecords()->where('mode', 'proxied')->where('name', $event['hostname'])->exists()) {
                    return;
                }
                SecurityEvent::query()->create([
                    'domain_id' => $domain->id, 'edge_id' => $edge->id, 'hostname' => $event['hostname'],
                    'state' => $domain->security_state, 'reason_code' => $event['reason_code'],
                    'details' => ['count' => $event['count']], 'occurred_at' => CarbonImmutable::createFromTimestamp($event['occurred_at']),
                ]);
                $settings = $domain->security_settings ?? SecurityConfig::defaults();
                if ($event['count'] >= 50 && $domain->security_state === 'normal') {
                    $domain->update(['security_state' => 'suspected', 'security_state_changed_at' => now(), 'revision' => $domain->revision + 1]);
                    Operation::coalesceDomain('edge.domain_reconcile', $domain->id);
                    ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();
                }
                if ($event['count'] >= 100 && str_starts_with($settings['quarantine_policy'], 'automatic')
                    && in_array($domain->refresh()->security_state, ['normal', 'suspected'], true)) {
                    $domain->update(['security_state' => 'restricted', 'security_state_changed_at' => now(), 'revision' => $domain->revision + 1]);
                    Operation::coalesceDomain('edge.domain_reconcile', $domain->id);
                    ReconcileEdgeDomain::dispatch($domain->id)->afterCommit();
                }
            });
        }
        PromoteReadyEdgePlacements::execute();
        $this->completeAcknowledgedTombstones();

        return response()->json(['data' => ['accepted' => true, 'server_time' => now()->toIso8601String()]]);
    }

    /**
     * The platform origin destination policy, one signed document per PoP. The agent sends the
     * checksum it already holds and receives nothing but `unchanged` while the policy is the same.
     */
    public function originPolicy(Request $request): JsonResponse
    {
        $encoded = ArtifactSigner::encode(OriginPolicy::compile());
        $checksum = hash('sha256', $encoded);
        if (hash_equals($checksum, (string) $request->query('checksum', ''))) {
            return response()->json(['unchanged' => true, 'checksum' => $checksum]);
        }
        $expiresAt = now()->addMinutes(5)->timestamp;

        return response()->json([
            'encoded_payload' => base64_encode($encoded), 'checksum' => $checksum, 'signature' => ArtifactSigner::sign($checksum),
            'expires_at' => $expiresAt,
            'delivery_signature' => ArtifactSigner::signDelivery(ArtifactSigner::ORIGIN_POLICY_SCOPE, $checksum, 0, $expiresAt),
        ]);
    }

    /**
     * How a DNS node pulls zones: where the primary listens, the catalog to follow, and the transfer
     * key, sealed to this edge's identity key so it is protected beyond the mTLS channel.
     */
    public function dnsTransfer(Request $request): JsonResponse
    {
        /** @var Edge $edge */
        $edge = $request->attributes->get('edge');
        $primary = DnsCluster::query()->where('enabled', true)->whereNotNull('transfer_endpoint')->orderBy('id')->first();
        abort_unless($primary !== null && filled($edge->identity_certificate), 404, 'No DNS primary accepts transfers.');

        return response()->json(['data' => [
            'primary' => $primary->transfer_endpoint, 'catalog' => PowerDnsClient::CATALOG_ZONE,
            'key_name' => PowerDnsClient::TRANSFER_KEY, 'algorithm' => 'hmac-sha256',
            'secret' => EdgeKeyEnvelope::seal($primary->transfer_tsig_secret, $edge->identity_certificate, $edge->id, 'dns-transfer-key'),
        ]]);
    }

    /** The zones that changed on the primary after the node's cursor, oldest first. */
    public function dnsChanges(Request $request): JsonResponse
    {
        $cursor = max(0, (int) $request->query('cursor', 0));
        $rows = DnsZoneChange::query()->where('id', '>', $cursor)->orderBy('id')->limit(200)->get(['id', 'zone', 'serial', 'deleted']);

        return response()->json([
            'data' => $rows->map(fn (DnsZoneChange $row): array => ['sequence' => $row->id, 'zone' => $row->zone, 'serial' => $row->serial, 'deleted' => $row->deleted]),
            'cursor' => $rows->last()?->id ?? $cursor, 'has_more' => $rows->count() === 200,
            'latest' => (int) DnsZoneChange::query()->max('id'),
        ]);
    }

    public function manifest(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $cursor = max(0, (int) $request->query('cursor', 0));
        $rows = $edge->artifacts()->where('sequence', '>', $cursor)->orderBy('sequence')->limit(500)->get(['sequence', 'kind', 'domain_id', 'revision', 'checksum', 'signature', 'schema_version', 'minimum_agent_version', 'maximum_agent_version']);

        return response()->json(['data' => $rows, 'cursor' => $rows->last()?->sequence ?? $cursor, 'has_more' => $rows->count() === 500, 'key_rotation' => ArtifactSigner::rotation()]);
    }

    public function artifact(Request $request, string $checksum): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $artifact = $edge->artifacts()->where('checksum', $checksum)->firstOrFail();
        $expiresAt = now()->addMinutes(5)->timestamp;

        return response()->json([
            'data' => ['sequence' => $artifact->sequence, 'kind' => $artifact->kind, 'domain_id' => $artifact->domain_id, 'revision' => $artifact->revision],
            'encoded_payload' => base64_encode(ArtifactSigner::encode($artifact->payload)),
            'expires_at' => $expiresAt,
            'delivery_signature' => ArtifactSigner::signDelivery(ArtifactSigner::artifactScope($artifact->kind, (int) $artifact->domain_id, (int) $artifact->revision), $artifact->checksum, $artifact->sequence, $expiresAt),
        ]);
    }

    public function certificateKey(Request $request, string $certificate): JsonResponse
    {
        /** @var Edge $edge */
        $edge = $request->attributes->get('edge');
        $record = TlsCertificate::query()->whereKey($certificate)->first();
        $assigned = $record !== null && DomainEdgeCell::query()->where('domain_id', $record->domain_id)->where('edge_id', $edge->id)
            ->where(fn ($query) => $query->whereNotNull('active_cell_id')->orWhereNotNull('target_cell_id'))->exists();
        abort_unless($assigned && filled($edge->identity_certificate), 404, 'The certificate key is not available to this edge.');

        return response()->json(['data' => EdgeKeyEnvelope::seal($record->private_key_ciphertext, $edge->identity_certificate, $edge->id, $record->id)]);
    }

    public function full(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $latestSequences = EdgeArtifact::query()->where('edge_id', $edge->id)->whereNotNull('domain_id')
            ->selectRaw('MAX(sequence)')->groupBy('domain_id');
        $latest = EdgeArtifact::query()->where('edge_id', $edge->id)->whereIn('sequence', $latestSequences)
            ->orderBy('domain_id')->limit(100001)->get();
        abort_if($latest->count() > 100000, 409, 'The edge snapshot exceeds the configured per-edge domain bound.');
        $payload = [
            'schema_version' => 1,
            'minimum_agent_version' => '1.0.0',
            'maximum_agent_version' => '1.99.0',
            'artifacts' => $latest,
        ];
        $encoded = ArtifactSigner::encode($payload);
        abort_if(strlen($encoded) > 64 * 1024 * 1024, 409, 'The edge snapshot exceeds the 64 MiB activation bound.');
        $compressed = gzencode($encoded, 6, ZLIB_ENCODING_GZIP);
        throw_if($compressed === false, RuntimeException::class, 'Unable to compress the edge snapshot.');
        $checksum = hash('sha256', $compressed);
        $sequence = (int) $latest->max('sequence');
        $expiresAt = now()->addMinutes(5)->timestamp;

        return response()->json(['data' => ['artifact_count' => $latest->count(), 'maximum_domains' => 100000], 'encoding' => 'gzip', 'encoded_snapshot' => base64_encode($compressed), 'checksum' => $checksum, 'signature' => ArtifactSigner::sign($checksum), 'signing_public_key' => ArtifactSigner::publicKey(), 'sequence' => $sequence, 'expires_at' => $expiresAt, 'delivery_signature' => ArtifactSigner::signDelivery(ArtifactSigner::SNAPSHOT_SCOPE, $checksum, $sequence, $expiresAt)]);
    }

    public function applied(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $data = $request->validate(['sequence' => ['required', 'integer', 'min:0']]);
        abort_if($data['sequence'] > 0 && ! $edge->artifacts()->where('sequence', $data['sequence'])->exists(), 422, 'The applied sequence was not issued to this edge.');
        $updated = Edge::query()->whereKey($edge->id)->where('active_sequence', '<=', $data['sequence'])
            ->update(['active_sequence' => $data['sequence']]);
        abort_unless($updated === 1, 409, 'An edge cannot acknowledge a sequence older than its active state.');
        $this->clearResolvedRejection($edge, $data['sequence']);
        PromoteReadyEdgePlacements::execute();
        $this->completeAcknowledgedTombstones();

        return response()->json(['data' => ['accepted' => true]]);
    }

    /**
     * A rejection describes one domain. It stays on the edge until that domain
     * has a newer artifact that the edge acknowledged.
     */
    private function clearResolvedRejection(Edge $edge, int $appliedSequence): void
    {
        $fresh = Edge::query()->findOrFail($edge->id);
        $rejection = ($fresh->capacity ?? [])['last_rejection'] ?? null;
        if (! is_array($rejection) || ! isset($rejection['domain_id'], $rejection['sequence'])) {
            return;
        }
        $resolved = $edge->artifacts()->where('domain_id', $rejection['domain_id'])
            ->where('sequence', '>', $rejection['sequence'])->where('sequence', '<=', $appliedSequence)->exists();
        if ($resolved) {
            $capacity = $fresh->capacity ?? [];
            unset($capacity['last_rejection']);
            $fresh->update(['capacity' => $capacity]);
        }
    }

    public function rejected(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $data = $request->validate([
            'sequence' => ['required', 'integer', 'min:1'],
            // An unknown reason is stored as reported, never refused: a refusal
            // would hold back every later acknowledgement from the agent.
            'reason' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'details' => ['nullable', 'string', 'max:4000'],
        ]);
        $artifact = $edge->artifacts()->where('sequence', $data['sequence'])->firstOrFail();
        $edge->update(['capacity' => array_merge($edge->capacity ?? [], ['last_rejection' => [
            ...$data, 'domain_id' => $artifact->domain_id, 'known_reason' => in_array($data['reason'], EdgeRejectionReasons::all(), true),
            'reported_at' => now()->toIso8601String(),
        ]])]);
        if ($artifact->domain_id !== null) {
            $message = $data['reason'].(filled($data['details'] ?? null) ? ': '.$data['details'] : '');
            DomainEdgePlacement::query()->where('domain_id', $artifact->domain_id)
                ->where('desired_revision', $artifact->revision)->whereNotNull('target_pool_id')
                ->update(['state' => 'failed', 'last_error' => mb_substr($message, 0, 4000)]);
            DomainEdgeCell::query()->where('domain_id', $artifact->domain_id)->where('edge_id', $edge->id)
                ->where('desired_revision', $artifact->revision)->whereNotNull('target_cell_id')
                ->update(['state' => 'failed', 'last_error' => mb_substr($message, 0, 4000)]);
            Operation::query()->where('type', 'edge.domain_reconcile')->whereIn('status', ['pending', 'running'])
                ->where('input->domain_id', $artifact->domain_id)
                ->update(['status' => 'failed', 'error' => mb_substr($message, 0, 4000), 'finished_at' => now()]);
        }

        return response()->json(['data' => ['accepted' => true]]);
    }

    public function tasks(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');

        $tasks = $edge->tasks()->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('created_at')->limit(100)->get()->filter(function (EdgeTask $task) use ($edge): bool {
                if ($task->type !== 'origin_test') {
                    return true;
                }

                return DB::transaction(function () use ($task, $edge): bool {
                    Operation::query()->lockForUpdate()->find($task->payload['operation_id'] ?? null);
                    $current = $edge->tasks()->lockForUpdate()->find($task->id);
                    if ($current === null || $current->status !== 'pending') {
                        return false;
                    }
                    if ($current->currentOriginRecord() !== null) {
                        return true;
                    }
                    $cancel = Request::create('/edge/v1/tasks/'.$task->id.'/result', 'POST', [
                        'status' => 'failed', 'result' => ['status' => 'unhealthy', 'failure_reason' => 'task_cancelled'],
                    ]);
                    $this->applyTaskResult($cancel, $edge, $current);

                    return false;
                }, 3);
            })->values();

        return response()->json(['data' => $tasks]);
    }

    public function taskResult(Request $request, string $task): JsonResponse
    {
        $edge = $request->attributes->get('edge');

        return DB::transaction(function () use ($request, $task, $edge): JsonResponse {
            $row = $edge->tasks()->findOrFail($task);
            // Serialize sibling receipts before taking a task lock so their
            // aggregate observes all preceding committed results.
            if (in_array($row->type, ['origin_test', 'emergency_mode'], true) && isset($row->payload['operation_id'])) {
                Operation::query()->lockForUpdate()->find($row->payload['operation_id']);
            } elseif ($row->type === 'cache_purge' && $row->cache_purge_id !== null) {
                CachePurge::query()->lockForUpdate()->find($row->cache_purge_id);
            }
            $row = $edge->tasks()->lockForUpdate()->findOrFail($task);

            return $this->applyTaskResult($request, $edge, $row);
        }, 3);
    }

    private function applyTaskResult(Request $request, Edge $edge, EdgeTask $row): JsonResponse
    {
        if (in_array($row->status, ['succeeded', 'failed'], true)
            || ($row->type === 'cache_purge' && $row->attempts > 0 && $row->available_at?->isFuture())) {
            return response()->json(['data' => ['accepted' => true, 'replayed' => true]]);
        }
        $rules = ['status' => ['required', 'in:succeeded,failed'], 'result' => ['required', 'array', 'max:30']];
        $rules += $row->type === 'origin_test' ? [
            'result.status' => ['required', 'in:healthy,unhealthy'], 'result.latency_ms' => ['nullable', 'integer', 'between:0,60000'],
            'result.resolved_address' => ['nullable', 'ip'], 'result.tls_result' => ['nullable'],
            'result.http_status' => ['nullable', 'integer', 'between:100,599'],
            'result.failure_reason' => ['nullable', 'in:dns_resolution_failed,blocked_destination,connect_timeout,connect_failed,tls_verification_failed,tls_handshake_failed,response_timeout,invalid_response,http_status_unhealthy,task_cancelled'],
        ] : [
            'result.status' => ['required', 'in:completed,failed'],
            'result.failure_reason' => ['nullable', 'string', 'max:100'],
        ];
        $data = $request->validate($rules);
        $result = array_merge($data['result'], [
            'edge_id' => $edge->id,
            ...($row->type === 'origin_test' ? ['origin_role' => $row->payload['origin_role'] ?? 'primary'] : []),
            'reported_at' => now()->toIso8601String(),
        ]);
        $attempts = $row->attempts + 1;
        $retryPurge = $row->type === 'cache_purge' && $data['status'] === 'failed' && $attempts < 5;
        $row->update([
            'status' => $retryPurge ? 'pending' : $data['status'], 'attempts' => $attempts, 'result' => $result,
            'last_error' => $data['status'] === 'failed' ? ($data['result']['failure_reason'] ?? 'edge_purge_failed') : null,
            'available_at' => $retryPurge ? now()->addSeconds(min(300, 5 * (2 ** ($attempts - 1)))) : null,
            'finished_at' => $retryPurge ? null : now(),
        ]);
        if (str_starts_with($row->type, 'cell_') && isset($row->payload['cell_id'])) {
            $cell = $edge->cells()->whereKey($row->payload['cell_id'])->first();
            if ($cell !== null && $data['status'] === 'succeeded') {
                $action = substr($row->type, 5);
                $cell->update(match ($action) {
                    'drain' => ['status' => 'drained', 'drained' => true],
                    'undrain' => ['status' => $cell->edge_pool_id === null ? 'unassigned' : 'assigned', 'drained' => false],
                    default => [],
                });
            }
            if (isset($row->payload['operation_id'])) {
                Operation::query()->whereKey($row->payload['operation_id'])->update([
                    'status' => $data['status'], 'error' => $data['status'] === 'failed' ? ($data['result']['failure_reason'] ?? 'cell_task_failed') : null,
                    'finished_at' => now(),
                ]);
            }
        }
        if ($row->type === 'origin_test' && isset($row->payload['record_id'])) {
            $record = $row->currentOriginRecord(lock: true);
            if ($record !== null) {
                $record->update(['origin_health' => $result]);
            }
            $operation = Operation::query()->find($row->payload['operation_id'] ?? null);
            if ($operation !== null && in_array($operation->status, ['pending', 'running'], true)) {
                $tasks = EdgeTask::query()->where('type', 'origin_test')->where('payload->operation_id', $operation->id)->get();
                $completed = $tasks->whereIn('status', ['succeeded', 'failed']);
                $terminal = $tasks->isNotEmpty() && $completed->count() === $tasks->count();
                $operation->update([
                    'status' => $terminal ? ($tasks->contains('status', 'failed') ? 'failed' : 'succeeded') : 'running',
                    'result' => ['tasks' => $tasks->count(), 'completed' => $completed->count(), 'edges' => $completed->map(fn (EdgeTask $task) => $task->result)->values()->all()],
                    'error' => $terminal && $tasks->contains('status', 'failed') ? 'One or more edge origin tests failed.' : null,
                    'finished_at' => $terminal ? now() : null,
                ]);
            }
        }
        if ($row->type === 'cache_purge' && $row->cache_purge_id !== null) {
            $purge = CachePurge::query()->find($row->cache_purge_id);
            if ($purge !== null) {
                $tasks = $purge->tasks()->get();
                $terminal = $tasks->isNotEmpty() && $tasks->every(fn (EdgeTask $task): bool => in_array($task->status, ['succeeded', 'failed'], true));
                $purge->update(['status' => $terminal ? ($tasks->contains('status', 'failed') ? 'failed' : 'succeeded') : 'running']);
            }
        }
        if ($row->type === 'emergency_mode' && isset($row->payload['operation_id'])) {
            $operation = Operation::query()->find($row->payload['operation_id']);
            if ($operation !== null) {
                $tasks = EdgeTask::query()->where('type', 'emergency_mode')->where('payload->operation_id', $operation->id)->get();
                $completed = $tasks->whereIn('status', ['succeeded', 'failed']);
                $terminal = $tasks->isNotEmpty() && $completed->count() === $tasks->count();
                $operation->update([
                    'status' => $terminal ? ($tasks->contains('status', 'failed') ? 'failed' : 'succeeded') : 'running',
                    'result' => ['tasks' => $tasks->count(), 'completed' => $completed->count()],
                    'error' => $terminal && $tasks->contains('status', 'failed') ? 'One or more emergency-mode deliveries failed.' : null,
                    'finished_at' => $terminal ? now() : null,
                ]);
            }
        }
        if ($row->type === 'runtime_upgrade' && isset($row->payload['rollout_edge_id'])) {
            $rolloutEdge = FleetRolloutEdge::query()->with('rollout')->find($row->payload['rollout_edge_id']);
            if ($rolloutEdge !== null) {
                $succeeded = $data['status'] === 'succeeded'
                    && $edge->runtime_versions === ($row->payload['versions'] ?? null)
                    && ($edge->capacity['listener_ready'] ?? false);
                $rolloutEdge->update([
                    'status' => $succeeded ? 'succeeded' : 'failed',
                    'failure_reason' => $succeeded ? null : ($data['result']['failure_reason'] ?? 'runtime_version_or_readiness_mismatch'),
                    'finished_at' => now(),
                ]);
                if ($succeeded) {
                    $edge->update(['desired_runtime_versions' => null]);
                }
                AdvanceFleetRollout::dispatch($rolloutEdge->fleet_rollout_id)->afterCommit();
            }
        }

        return response()->json(['data' => ['accepted' => true]]);
    }

    private function completeAcknowledgedTombstones(): void
    {
        Operation::query()->where('type', 'edge.domain_reconcile')->where('status', 'running')
            ->where('result->awaiting_acknowledgements', true)->orderBy('created_at')->limit(100)->get()
            ->each(function (Operation $operation): void {
                $domainId = (int) ($operation->input['domain_id'] ?? 0);
                $revision = (int) ($operation->result['revision'] ?? 0);
                if ($domainId < 1 || $revision < 1 || DomainEdgePlacement::query()->where('domain_id', $domainId)->exists()) {
                    return;
                }
                $edges = Edge::query()->where('enabled', true)->whereNull('identity_revoked_at')->get();
                $acknowledged = $edges->every(function (Edge $edge) use ($domainId, $revision): bool {
                    $artifact = EdgeArtifact::query()->where('edge_id', $edge->id)->where('domain_id', $domainId)
                        ->where('revision', $revision)->where('kind', 'tombstone')->first();

                    return $artifact !== null && $edge->active_sequence >= $artifact->sequence;
                });
                if (! $acknowledged) {
                    return;
                }
                $operation->update([
                    'status' => 'succeeded',
                    'result' => [...$operation->result, 'awaiting_acknowledgements' => false],
                    'error' => null, 'finished_at' => now(),
                ]);
                Domain::query()->whereKey($domainId)->update(['active_edge_revision' => $revision]);
            });
    }
}
