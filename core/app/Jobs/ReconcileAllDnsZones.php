<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\Domain;
use App\Models\Operation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileAllDnsZones implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public string $operationId)
    {
        $this->onQueue('bulk_maintenance');
    }

    public function handle(): void
    {
        $operation = Operation::query()->findOrFail($this->operationId);
        if ($operation->status === 'succeeded') {
            return;
        }
        $operation->update(['status' => 'running', 'started_at' => now(), 'attempts' => $operation->attempts + 1]);
        $dispatched = 0;
        Domain::query()->where('lifecycle_state', DomainLifecycleState::Active->value)->orderBy('id')
            ->chunkById(100, function ($domains) use (&$dispatched): void {
                foreach ($domains as $domain) {
                    ReconcileDnsZone::dispatch($domain->id);
                    $dispatched++;
                }
            });
        $operation->update(['status' => 'succeeded', 'result' => ['domains_dispatched' => $dispatched], 'error' => null, 'finished_at' => now()]);
    }
}
