<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\DnsCluster;
use App\Models\Operation;
use App\Support\PowerDnsClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class TestDnsCluster implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public string $operationId)
    {
        $this->onQueue('dns');
    }

    public function handle(PowerDnsClient $client): void
    {
        $operation = Operation::query()->findOrFail($this->operationId);
        if ($operation->status === 'succeeded') {
            return;
        }
        $operation->update(['status' => 'running', 'started_at' => now(), 'attempts' => $operation->attempts + 1]);
        $cluster = DnsCluster::query()->findOrFail((int) $operation->input['cluster_id']);
        try {
            $client->health($cluster);
            $cluster->update(['last_health_status' => 'healthy', 'last_health_error' => null, 'last_health_at' => now()]);
            $operation->update(['status' => 'succeeded', 'result' => ['cluster_id' => $cluster->id, 'healthy' => true], 'error' => null, 'finished_at' => now()]);
            $this->enableWhenRequested($operation, $cluster);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 4000);
            $cluster->update(['last_health_status' => 'unhealthy', 'last_health_error' => $message, 'last_health_at' => now()]);
            $operation->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            throw $exception;
        }
    }

    /** A registration by Fleet asks for the cluster to be enabled once healthy, unless another primary already is. */
    private function enableWhenRequested(Operation $operation, DnsCluster $cluster): void
    {
        if (($operation->input['auto_enable'] ?? false) !== true || $cluster->enabled
            || DnsCluster::query()->where('enabled', true)->whereKeyNot($cluster->id)->exists()) {
            return;
        }
        $cluster->update(['enabled' => true]);
        AuditLog::record(null, 'dns.cluster_enabled', $cluster, ['source' => 'fleet']);
        ReconcilePlatformDnsIdentity::dispatch();
        $reconcile = Operation::query()->where('type', 'dns.global_reconcile')->whereIn('status', ['pending', 'running'])->first()
            ?? Operation::query()->create(['type' => 'dns.global_reconcile', 'status' => 'pending', 'input' => ['cluster_id' => $cluster->id]]);
        ReconcileAllDnsZones::dispatch($reconcile->id);
    }
}
