<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\Operation;
use App\Models\PlatformDnsSetting;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ApplyPlatformDnsSettings implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $operationId)
    {
        $this->onQueue('runtime');
    }

    public function handle(): void
    {
        $operation = Operation::query()->findOrFail($this->operationId);
        $operation->update(['status' => 'running', 'started_at' => now(), 'attempts' => $operation->attempts + 1]);
        PlatformDnsSetting::query()->updateOrCreate(['id' => 1], $operation->input);
        $operation->update(['status' => 'succeeded', 'result' => ['settings_id' => 1], 'finished_at' => now(), 'error' => null]);
        AuditLog::record($operation->actor_id ? User::find($operation->actor_id) : null, 'platform_dns_settings.applied', $operation);
    }

    public function failed(Throwable $exception): void
    {
        Operation::query()->whereKey($this->operationId)->update([
            'status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 4000), 'finished_at' => now(),
        ]);
    }
}
