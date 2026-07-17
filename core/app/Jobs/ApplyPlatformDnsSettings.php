<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use App\Support\PowerDnsClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyPlatformDnsSettings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

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
        $current = PlatformDnsSetting::query()->find(1);
        PlatformDnsSetting::query()->updateOrCreate(['id' => 1], [
            ...$operation->input,
            'revision' => ($current?->revision ?? 0) + 1,
        ]);
        AuditLog::record($operation->actor_id ? User::find($operation->actor_id) : null, 'platform_dns_settings.applied', $operation);
        (new ReconcilePlatformDnsIdentity($operation->getKey()))->handle($client);
    }

    public function uniqueId(): string
    {
        return $this->operationId;
    }

    public function failed(Throwable $exception): void
    {
        Operation::query()->whereKey($this->operationId)->update([
            'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now(),
        ]);
    }
}
