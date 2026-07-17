<?php

namespace App\Jobs;

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
        $this->onQueue('runtime');
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
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 4000);
            $cluster->update(['last_health_status' => 'unhealthy', 'last_health_error' => $message, 'last_health_at' => now()]);
            $operation->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            throw $exception;
        }
    }
}
