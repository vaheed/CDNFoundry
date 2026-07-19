<?php

namespace App\Http\Controllers;

use App\Enums\DomainLifecycleState;
use App\Jobs\ReconcileAllEdgeDomains;
use App\Jobs\ReconcileDnsZone;
use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\AuditLog;
use App\Models\CachePurge;
use App\Models\DnsCluster;
use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgeTask;
use App\Models\Operation;
use App\Support\ArtifactSigner;
use App\Support\EdgeCertificateAuthority;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EdgeAgentController extends Controller
{
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

    public function heartbeat(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $data = $request->validate([
            'agent_version' => ['required', 'string', 'max:40'], 'listener_ready' => ['required', 'boolean'],
            'active_sequence' => ['required', 'integer', 'min:0'], 'cells' => ['required', 'array', 'max:32'],
            'cells.*.name' => ['required', 'string', 'max:100', 'distinct'], 'cells.*.status' => ['required', 'in:ready,degraded,failed,drained'],
            'cells.*.capacity' => ['required', 'array', 'max:20'], 'noisy_domains' => ['sometimes', 'array', 'max:20'],
            'passive_origins' => ['sometimes', 'array', 'max:100'],
            'passive_origins.*.domain' => ['required', 'string', 'max:253'],
            'passive_origins.*.hostname' => ['required', 'string', 'max:253'],
            'passive_origins.*.failure_count' => ['required', 'integer', 'between:1,2147483647'],
            'passive_origins.*.last_status' => ['required', 'integer', 'between:0,599'],
            'passive_origins.*.last_failed_at' => ['required', 'integer', 'min:0'],
        ]);
        $knownCells = $edge->cells()->pluck('id', 'name');
        foreach ($data['cells'] as $index => $cell) {
            if (! $knownCells->has($cell['name'])) {
                throw ValidationException::withMessages(["cells.$index.name" => 'The cell is not assigned to this edge.']);
            }
        }
        $latestIssuedSequence = (int) $edge->artifacts()->max('sequence');
        if ($data['active_sequence'] > $latestIssuedSequence) {
            throw ValidationException::withMessages(['active_sequence' => 'The active sequence was not issued to this edge.']);
        }
        $oldCellRouting = $edge->cells()->orderBy('id')->get(['id', 'status', 'drained', 'service_ipv4', 'service_ipv6'])->toJson();
        $wasRoutable = $edge->enabled && ! $edge->drained && $edge->last_heartbeat_at?->gte(now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds')))
            && ($edge->capacity['listener_ready'] ?? false);
        foreach ($data['cells'] as $cell) {
            $edge->cells()->where('name', $cell['name'])->limit(1)->update(['status' => $cell['status'], 'capacity' => $cell['capacity']]);
        }
        $reportedNames = collect($data['cells'])->pluck('name');
        $edge->cells()->whereNotIn('name', $reportedNames)->update(['status' => 'degraded', 'capacity' => null]);
        $computedReady = $edge->cells()->where('drained', false)->where('status', 'ready')->whereNotNull('service_ipv4')->exists();
        $listenerReady = $data['listener_ready'] && $computedReady;
        $edge->update([
            'last_heartbeat_at' => now(), 'agent_version' => $data['agent_version'],
            'active_sequence' => max($edge->active_sequence, $data['active_sequence']),
            'bootstrap_token_hash' => null, 'bootstrap_consumed_at' => null,
            'capacity' => array_merge($edge->capacity ?? [], ['listener_ready' => $listenerReady, 'cells' => $data['cells'], 'noisy_domains' => $data['noisy_domains'] ?? []]),
        ]);
        $isRoutable = $edge->enabled && ! $edge->drained && $listenerReady;
        $newCellRouting = $edge->cells()->orderBy('id')->get(['id', 'status', 'drained', 'service_ipv4', 'service_ipv6'])->toJson();
        if ($wasRoutable !== $isRoutable || $oldCellRouting !== $newCellRouting) {
            ReconcilePlatformDnsIdentity::dispatchForRoutingChange();
        }
        foreach ($data['passive_origins'] ?? [] as $failure) {
            DnsRecord::query()->where('name', $failure['hostname'])->whereHas('domain', fn ($query) => $query->where('name', $failure['domain']))
                ->where('mode', 'proxied')->limit(1)->update(['origin_health' => [
                    'status' => 'unhealthy', 'source' => 'passive', 'edge_id' => $edge->id,
                    'failure_count' => $failure['failure_count'], 'http_status' => $failure['last_status'] ?: null,
                    'reported_at' => now()->toIso8601String(),
                ]]);
        }
        $this->promoteReadyPlacements();
        $this->completeAcknowledgedTombstones();

        return response()->json(['data' => ['accepted' => true, 'server_time' => now()->toIso8601String()]]);
    }

    public function manifest(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $cursor = max(0, (int) $request->query('cursor', 0));
        $rows = $edge->artifacts()->where('sequence', '>', $cursor)->orderBy('sequence')->limit(500)->get(['sequence', 'kind', 'domain_id', 'revision', 'checksum', 'signature', 'schema_version', 'minimum_agent_version', 'maximum_agent_version']);

        return response()->json(['data' => $rows, 'cursor' => $rows->last()?->sequence ?? $cursor, 'has_more' => $rows->count() === 500]);
    }

    public function artifact(Request $request, string $checksum): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $artifact = $edge->artifacts()->where('checksum', $checksum)->firstOrFail();

        return response()->json([
            'data' => ['sequence' => $artifact->sequence, 'kind' => $artifact->kind, 'domain_id' => $artifact->domain_id, 'revision' => $artifact->revision],
            'encoded_payload' => base64_encode(ArtifactSigner::encode($artifact->payload)),
        ]);
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

        return response()->json(['data' => ['artifact_count' => $latest->count(), 'maximum_domains' => 100000], 'encoding' => 'gzip', 'encoded_snapshot' => base64_encode($compressed), 'checksum' => $checksum, 'signature' => ArtifactSigner::sign($checksum), 'signing_public_key' => ArtifactSigner::publicKey()]);
    }

    public function applied(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $data = $request->validate(['sequence' => ['required', 'integer', 'min:0']]);
        abort_if($data['sequence'] < $edge->active_sequence, 409, 'An edge cannot acknowledge a sequence older than its active state.');
        abort_if($data['sequence'] > 0 && ! $edge->artifacts()->where('sequence', $data['sequence'])->exists(), 422, 'The applied sequence was not issued to this edge.');
        $edge->update(['active_sequence' => $data['sequence']]);
        $this->promoteReadyPlacements();
        $this->completeAcknowledgedTombstones();

        return response()->json(['data' => ['accepted' => true]]);
    }

    public function rejected(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $data = $request->validate([
            'sequence' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'in:incompatible_artifact,signature_or_checksum_invalid,candidate_validation_failed'],
            'details' => ['nullable', 'string', 'max:4000'],
        ]);
        $artifact = $edge->artifacts()->where('sequence', $data['sequence'])->firstOrFail();
        $edge->update(['capacity' => array_merge($edge->capacity ?? [], ['last_rejection' => $data])]);
        if ($artifact->domain_id !== null) {
            $message = $data['reason'].(filled($data['details'] ?? null) ? ': '.$data['details'] : '');
            DomainEdgePlacement::query()->where('domain_id', $artifact->domain_id)
                ->where('desired_revision', $artifact->revision)->whereNotNull('target_pool_id')
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

        return response()->json(['data' => $edge->tasks()->where('status', 'pending')->orderBy('created_at')->limit(100)->get()]);
    }

    public function taskResult(Request $request, string $task): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $row = $edge->tasks()->findOrFail($task);
        if (in_array($row->status, ['succeeded', 'failed'], true)) {
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
        $result = array_merge($data['result'], ['edge_id' => $edge->id, 'reported_at' => now()->toIso8601String()]);
        $row->update(['status' => $data['status'], 'result' => $result, 'finished_at' => now()]);
        if (str_starts_with($row->type, 'cell_') && isset($row->payload['cell_id'])) {
            $cell = $edge->cells()->whereKey($row->payload['cell_id'])->first();
            if ($cell !== null && $data['status'] === 'succeeded') {
                $action = substr($row->type, 5);
                $cell->update(match ($action) {
                    'drain' => ['status' => 'drained', 'drained' => true],
                    'undrain' => ['status' => 'pending', 'drained' => false],
                    default => [],
                });
            }
        }
        if ($row->type === 'origin_test' && isset($row->payload['record_id'])) {
            DnsRecord::query()->whereKey($row->payload['record_id'])->update(['origin_health' => $result]);
            $operation = Operation::query()->find($row->payload['operation_id'] ?? null);
            if ($operation !== null) {
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

        return response()->json(['data' => ['accepted' => true]]);
    }

    private function promoteReadyPlacements(): void
    {
        $edges = Edge::query()->readyForTraffic()->get();
        if ($edges->isEmpty()) {
            return;
        }
        $published = [];
        DomainEdgePlacement::query()->where('state', 'deploying')->whereNotNull('target_pool_id')->orderBy('id')->limit(100)->get()->each(function (DomainEdgePlacement $placement) use ($edges, &$published): void {
            $participants = $edges->filter(function (Edge $edge) use ($placement): bool {
                $cell = $edge->cells()->where('edge_pool_id', $placement->target_pool_id)->first();

                return $cell !== null && ! $cell->drained && $cell->service_ipv4 !== null
                    && ($edge->ipv6 === null || $cell->service_ipv6 !== null);
            });
            if ($participants->isEmpty()) {
                return;
            }
            $ready = $participants->every(function (Edge $edge) use ($placement): bool {
                $cell = $edge->cells()->where('edge_pool_id', $placement->target_pool_id)->firstOrFail();
                if ($cell->status !== 'ready') {
                    return false;
                }
                $artifact = EdgeArtifact::query()->where('edge_id', $edge->id)->where('domain_id', $placement->domain_id)
                    ->where('revision', $placement->desired_revision)->latest('sequence')->first();

                return $artifact !== null && $edge->active_sequence >= $artifact->sequence;
            });
            if (! $ready) {
                return;
            }
            DB::transaction(function () use ($placement, &$published): void {
                $locked = DomainEdgePlacement::query()->lockForUpdate()->find($placement->id);
                if ($locked === null || $locked->state !== 'deploying' || $locked->target_pool_id === null) {
                    return;
                }
                $previousPool = $locked->active_pool_id;
                $targetPool = $locked->target_pool_id;
                $moving = $previousPool !== null && $previousPool !== $locked->target_pool_id;
                $locked->update($moving ? [
                    'state' => 'draining', 'drain_after' => null,
                ] : [
                    'active_pool_id' => $locked->target_pool_id, 'target_pool_id' => null, 'state' => 'active', 'drain_after' => null,
                ]);
                Domain::query()->whereKey($locked->domain_id)->update(['active_edge_revision' => $locked->desired_revision]);
                Operation::query()->where('type', 'edge.domain_reconcile')->whereIn('status', ['pending', 'running'])
                    ->where('input->domain_id', $locked->domain_id)->update([
                        'status' => $moving ? 'running' : 'succeeded',
                        'result' => [
                            'revision' => $locked->desired_revision,
                            'placement_state' => $locked->state,
                            'awaiting_dns_drain' => $moving,
                        ],
                        'error' => null, 'finished_at' => $moving ? null : now(),
                    ]);
                AuditLog::record(null, 'edge.placement_target_ready', $locked, ['active_pool_id' => $previousPool, 'target_pool_id' => $targetPool, 'state' => $locked->state]);
                $published[] = $locked->domain_id;
            });
        });
        foreach (array_unique($published) as $domainId) {
            $domain = Domain::query()->find($domainId);
            if ($domain?->lifecycle_state === DomainLifecycleState::Active && DnsCluster::query()->where('enabled', true)->exists()) {
                Operation::coalesceDomain('dns.zone_reconcile', $domain->id);
                ReconcileDnsZone::dispatch($domain->id)->afterCommit();
            }
        }
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
