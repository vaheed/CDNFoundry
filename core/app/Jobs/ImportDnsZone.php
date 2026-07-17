<?php

namespace App\Jobs;

use App\Enums\DomainLifecycleState;
use App\Models\DnsCluster;
use App\Models\Domain;
use App\Models\Operation;
use App\Models\User;
use App\Support\BindZone;
use App\Support\DnsZoneImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportDnsZone implements ShouldQueue
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
        try {
            $domain = Domain::query()->findOrFail((int) $operation->input['domain_id']);
            $records = BindZone::parse((string) $operation->input['zone'], $domain->name);
            $result = DnsZoneImporter::apply(
                $domain->id, $records, (bool) ($operation->input['replace_existing'] ?? false),
                $operation->actor_id ? User::find($operation->actor_id) : null, $operation->input['ip_address'] ?? null,
            );
            $operation->update(['status' => 'succeeded', 'result' => $result, 'finished_at' => now(), 'error' => null]);
            if ($domain->refresh()->lifecycle_state === DomainLifecycleState::Active && DnsCluster::query()->where('enabled', true)->exists()) {
                ReconcileDnsZone::dispatch($domain->id)->afterCommit();
            }
        } catch (Throwable $exception) {
            $operation->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now()]);
            throw $exception;
        }
    }
}
