<?php

namespace App\Support;

use App\Models\DnsRecord;
use App\Models\Domain;
use App\Models\Edge;
use App\Models\EdgeTask;
use App\Models\Operation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Turns the answers of the representatives of one scheduled check into the active signal of an origin.
 *
 * One failed probe never marks an origin down. A failure is first confirmed by one other worker, from the same group when there is
 * one and from another group otherwise, inside the same probe budget. Then:
 *  - the confirming worker succeeds: the first worker failed, not the origin. The result is healthy and the failing worker is named;
 *  - every probed group fails: a confirmed origin failure (unhealthy);
 *  - some groups succeed and some fail: a regional connectivity failure, shown as degraded with the groups that failed;
 *  - nothing could confirm it: an unconfirmed failure, which is shown as degraded, not unhealthy.
 * A group nobody could probe is listed as not monitored and never counted healthy.
 */
final class OriginProbeOutcome
{
    /**
     * Called when a task of a scheduled check has been answered. It may add one confirmation task, and it records the active
     * signal once every task has answered.
     */
    public static function taskAnswered(EdgeTask $task, Operation $operation, DnsRecord $record): void
    {
        $tasks = EdgeTask::query()->where('type', 'origin_test')->where('payload->operation_id', $operation->id)->get();
        $failed = fn (EdgeTask $one): bool => ($one->result['status'] ?? null) === 'unhealthy' || $one->status === 'failed';
        $confirming = $tasks->contains(fn (EdgeTask $one): bool => isset($one->payload['confirmation_of']));
        $pending = $tasks->where('status', 'pending');
        if ($pending->isEmpty() && ! $confirming && $tasks->contains($failed) && self::addConfirmation($operation, $record, $tasks)) {
            return;
        }
        if ($tasks->where('status', 'pending')->isNotEmpty()) {
            return;
        }
        self::record($record, $operation, $tasks->fresh() ?? $tasks);
    }

    /** @param Collection<int, EdgeTask> $tasks */
    private static function addConfirmation(Operation $operation, DnsRecord $record, Collection $tasks): bool
    {
        $role = $operation->input['origin_role'] ?? 'primary';
        $origin = OriginHealth::originFor($record, $role);
        $first = $tasks->first(fn (EdgeTask $one): bool => ($one->result['status'] ?? null) === 'unhealthy' || $one->status === 'failed');
        if ($origin === null || $first === null) {
            return false;
        }
        $asked = $tasks->pluck('edge_id')->all();
        $placement = Edge::query()->whereIn('id', Domain::query()->findOrFail($record->domain_id)->placementEdgeIds())->orderBy('id')->get();
        $fresh = now()->subSeconds(app(PlatformSettings::class)->integer('edge_runtime', 'heartbeat_fresh_seconds'));
        $usable = $placement->filter(fn (Edge $edge): bool => $edge->enabled && ! $edge->drained && $edge->identity_revoked_at === null && $edge->registered_at !== null
            && $edge->last_heartbeat_at !== null && $edge->last_heartbeat_at >= $fresh && ! in_array($edge->id, $asked, true));
        $firstEdge = $placement->firstWhere('id', $first->edge_id);
        $group = $firstEdge === null ? null : OriginProbePlanner::group($firstEdge);
        $destination = OriginMonitoring::destination($origin);
        // The same group first, so a worker fault is told from an origin fault; then another group, so a regional one is.
        $candidate = $usable->first(fn (Edge $edge): bool => OriginProbePlanner::group($edge) === $group)
            ?? $usable->sortBy(fn (Edge $edge): string => hash('sha256', $destination.'|'.$edge->id))->first();
        if ($candidate === null || OriginMonitoring::allowance($destination, (int) $record->domain_id, 1) < 1) {
            return false;
        }
        EdgeTask::query()->create(['id' => (string) Str::uuid(), 'edge_id' => $candidate->id, 'type' => 'origin_test', 'status' => 'pending', 'payload' => [
            'operation_id' => $operation->id, 'domain_id' => $record->domain_id, 'record_id' => $record->id, 'destination' => $destination, 'probe_expires_at' => OriginMonitoring::taskExpiry(true), 'confirmation_of' => $first->id,
            'origin_role' => $role, 'origin' => $origin, 'addresses' => $operation->input['addresses'] ?? [],
            'private_allowlist' => app(PlatformSettings::class)->get('origin_safety', 'private_origin_allowlist'),
            'blocked_networks' => app(PlatformSettings::class)->get('origin_safety', 'blocked_origin_networks'),
        ]]);

        return true;
    }

    /** @param Collection<int, EdgeTask> $tasks */
    private static function record(DnsRecord $record, Operation $operation, Collection $tasks): void
    {
        $role = $operation->input['origin_role'] ?? 'primary';
        $edges = Edge::query()->whereIn('id', $tasks->pluck('edge_id'))->get()->keyBy('id');
        $byGroup = [];
        foreach ($tasks as $one) {
            $edge = $edges->get($one->edge_id);
            if ($edge === null) {
                continue;
            }
            $ok = ($one->result['status'] ?? null) === 'healthy' && $one->status === 'succeeded';
            $byGroup[OriginProbePlanner::group($edge)][] = ['ok' => $ok, 'edge' => $one->edge_id, 'result' => $one->result ?? [], 'confirmation' => isset($one->payload['confirmation_of'])];
        }
        // A group is healthy when its last answer is, so a confirming worker that succeeds clears a worker fault.
        $failedGroups = [];
        $workerFaults = [];
        foreach ($byGroup as $group => $answers) {
            $last = end($answers);
            if (! $last['ok']) {
                $failedGroups[] = $group;
            } elseif (collect($answers)->contains(fn (array $answer): bool => ! $answer['ok'])) {
                $workerFaults[] = collect($answers)->first(fn (array $answer): bool => ! $answer['ok'])['edge'];
            }
        }
        $groups = array_keys($byGroup);
        $notMonitored = collect($operation->input['groups'] ?? [])->filter(fn (array $row): bool => $row['edge_id'] === null)->pluck('group')->values()->all();
        $representative = collect($byGroup)->flatten(1)->first(fn (array $answer): bool => $answer['ok']) ?? collect($byGroup)->flatten(1)->first();
        $result = $representative['result'] ?? [];
        $confirmed = $tasks->contains(fn (EdgeTask $one): bool => isset($one->payload['confirmation_of']));
        $scope = match (true) {
            $failedGroups === [] => 'reachable',
            count($failedGroups) === count($groups) && $confirmed => 'origin',
            count($failedGroups) === count($groups) => 'unconfirmed',
            default => 'regional',
        };
        OriginHealth::record($record, $role, 'active', [
            'status' => $scope === 'origin' ? 'unhealthy' : ($scope === 'reachable' ? 'healthy' : 'unhealthy'),
            'observer' => $representative['edge'] ?? null, 'at' => now()->toIso8601String(),
            'latency_ms' => $result['latency_ms'] ?? null, 'http_status' => $result['http_status'] ?? null,
            'failure_reason' => $result['failure_reason'] ?? null, 'resolved_address' => $result['resolved_address'] ?? null, 'tls_result' => $result['tls_result'] ?? null,
            'scope' => $scope, 'failed_groups' => implode(',', $failedGroups), 'not_monitored' => implode(',', $notMonitored),
            'worker_faults' => count($workerFaults),
        ]);
    }
}
