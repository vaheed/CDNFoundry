<?php

namespace App\Http\Controllers;

use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeArtifact;
use App\Models\EdgeTask;
use App\Models\Operation;
use App\Support\ArtifactSigner;
use App\Support\EdgeCertificateAuthority;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $edge = Edge::query()->findOrFail($data['edge_id']);
        abort_if($edge->bootstrap_token_hash === null || ! hash_equals($edge->bootstrap_token_hash, hash('sha256', $data['bootstrap_token'])), 401, 'The bootstrap token is invalid or already used.');
        try {
            $identity = EdgeCertificateAuthority::sign($data['certificate_request'], $edge->id);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['certificate_request' => $exception->getMessage()]);
        }
        $edge->update([
            'bootstrap_token_hash' => null, 'identity_hash' => null,
            'identity_certificate_serial' => $identity['serial'],
            'identity_certificate_expires_at' => CarbonImmutable::parse($identity['expires_at']),
            'identity_revoked_at' => null, 'registered_at' => now(), 'agent_version' => $data['agent_version'],
        ]);

        return response()->json(['data' => [
            'edge_id' => $edge->id, 'identity_certificate' => $identity['certificate'],
            'identity_certificate_serial' => $identity['serial'], 'identity_certificate_expires_at' => $identity['expires_at'],
            'signing_public_key' => ArtifactSigner::publicKey(),
        ]], 201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $data = $request->validate([
            'agent_version' => ['required', 'string', 'max:40'], 'listener_ready' => ['required', 'boolean'],
            'active_sequence' => ['required', 'integer', 'min:0'], 'cells' => ['required', 'array', 'max:32'],
            'cells.*.name' => ['required', 'string', 'max:100'], 'cells.*.status' => ['required', 'in:ready,degraded,failed,drained'],
            'cells.*.capacity' => ['required', 'array', 'max:20'], 'noisy_domains' => ['sometimes', 'array', 'max:20'],
            'passive_origins' => ['sometimes', 'array', 'max:100'],
            'passive_origins.*.domain' => ['required', 'string', 'max:253'],
            'passive_origins.*.hostname' => ['required', 'string', 'max:253'],
            'passive_origins.*.failure_count' => ['required', 'integer', 'between:1,2147483647'],
            'passive_origins.*.last_status' => ['required', 'integer', 'between:0,599'],
            'passive_origins.*.last_failed_at' => ['required', 'integer', 'min:0'],
        ]);
        $edge->update(['last_heartbeat_at' => now(), 'agent_version' => $data['agent_version'], 'active_sequence' => $data['active_sequence'], 'capacity' => ['listener_ready' => $data['listener_ready'], 'cells' => $data['cells'], 'noisy_domains' => $data['noisy_domains'] ?? []]]);
        foreach ($data['passive_origins'] ?? [] as $failure) {
            DnsRecord::query()->where('name', $failure['hostname'])->whereHas('domain', fn ($query) => $query->where('name', $failure['domain']))
                ->where('mode', 'proxied')->limit(1)->update(['origin_health' => [
                    'status' => 'unhealthy', 'source' => 'passive', 'edge_id' => $edge->id,
                    'failure_count' => $failure['failure_count'], 'http_status' => $failure['last_status'] ?: null,
                    'reported_at' => now()->toIso8601String(),
                ]]);
        }

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

        return response()->json(['data' => $artifact, 'encoded_payload' => base64_encode(ArtifactSigner::encode($artifact->payload))]);
    }

    public function full(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $latest = EdgeArtifact::query()->where('edge_id', $edge->id)->orderByDesc('sequence')->get()->unique('domain_id')->sortBy('domain_id')->values();
        $payload = [
            'schema_version' => 1,
            'minimum_agent_version' => '1.0.0',
            'maximum_agent_version' => '1.99.0',
            'artifacts' => $latest,
        ];
        $encoded = ArtifactSigner::encode($payload);
        $checksum = hash('sha256', $encoded);

        return response()->json(['data' => $payload, 'encoded_snapshot' => base64_encode($encoded), 'checksum' => $checksum, 'signature' => ArtifactSigner::sign($checksum), 'signing_public_key' => ArtifactSigner::publicKey()]);
    }

    public function applied(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $data = $request->validate(['sequence' => ['required', 'integer', 'min:0']]);
        abort_if($data['sequence'] > 0 && ! $edge->artifacts()->where('sequence', $data['sequence'])->exists(), 422, 'The applied sequence was not issued to this edge.');
        $edge->update(['active_sequence' => $data['sequence']]);
        $this->promoteReadyPlacements();

        return response()->json(['data' => ['accepted' => true]]);
    }

    public function rejected(Request $request): JsonResponse
    {
        $edge = $request->attributes->get('edge');
        $data = $request->validate(['sequence' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:100'], 'details' => ['nullable', 'string', 'max:4000']]);
        $edge->update(['capacity' => array_merge($edge->capacity ?? [], ['last_rejection' => $data])]);

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
        $data = $request->validate([
            'status' => ['required', 'in:succeeded,failed'], 'result' => ['required', 'array', 'max:30'],
            'result.status' => ['required', 'in:healthy,unhealthy'], 'result.latency_ms' => ['nullable', 'integer', 'between:0,60000'],
            'result.resolved_address' => ['nullable', 'ip'], 'result.tls_result' => ['nullable'],
            'result.http_status' => ['nullable', 'integer', 'between:100,599'],
            'result.failure_reason' => ['nullable', 'in:dns_resolution_failed,blocked_destination,connect_timeout,connect_failed,tls_verification_failed,tls_handshake_failed,response_timeout,invalid_response,http_status_unhealthy,task_cancelled'],
        ]);
        $result = array_merge($data['result'], ['edge_id' => $edge->id, 'reported_at' => now()->toIso8601String()]);
        $row->update(['status' => $data['status'], 'result' => $result, 'finished_at' => now()]);
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

        return response()->json(['data' => ['accepted' => true]]);
    }

    private function promoteReadyPlacements(): void
    {
        $edges = Edge::query()->where('enabled', true)->where('drained', false)->whereNull('identity_revoked_at')
            ->whereNotNull('registered_at')->where('last_heartbeat_at', '>=', now()->subSeconds((int) config('edge.heartbeat_fresh_seconds')))->get();
        if ($edges->isEmpty()) {
            return;
        }
        DomainEdgePlacement::query()->where('state', 'deploying')->orderBy('id')->limit(100)->get()->each(function (DomainEdgePlacement $placement) use ($edges): void {
            $ready = $edges->every(function (Edge $edge) use ($placement): bool {
                $artifact = EdgeArtifact::query()->where('edge_id', $edge->id)->where('domain_id', $placement->domain_id)
                    ->where('revision', $placement->desired_revision)->latest('sequence')->first();

                return $artifact !== null && $edge->active_sequence >= $artifact->sequence;
            });
            if (! $ready) {
                return;
            }
            $previousPool = $placement->active_pool_id;
            $moving = $previousPool !== null && $previousPool !== $placement->target_pool_id;
            $placement->update($moving ? [
                'state' => 'draining', 'drain_after' => now()->addSeconds((int) config('edge.placement_drain_seconds')),
            ] : [
                'active_pool_id' => $placement->target_pool_id, 'target_pool_id' => null, 'state' => 'active', 'drain_after' => null,
            ]);
            Domain::query()->whereKey($placement->domain_id)->update(['active_edge_revision' => $placement->desired_revision]);
        });
    }
}
