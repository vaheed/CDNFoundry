<?php

namespace App\Console\Commands;

use App\Enums\DomainLifecycleState;
use App\Jobs\DeprovisionDnsZone;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Operation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireDomainClaims extends Command
{
    protected $signature = 'cdnf:domains:expire-claims';

    protected $description = 'Deprovision expired unverified claims without changing verified ownership';

    public function handle(): int
    {
        Domain::query()->whereNull('nameservers_verified_at')
            ->whereIn('lifecycle_state', [DomainLifecycleState::PendingVerification, DomainLifecycleState::Disabled])
            ->where(function ($query): void {
                $query->where('claim_expires_at', '<=', now())
                    ->orWhere(fn ($legacy) => $legacy->whereNull('claim_expires_at')->where('created_at', '<=', now()->subDays(7)));
            })->orderBy('id')->limit(500)->pluck('id')->each(function (int $id): void {
                DB::transaction(function () use ($id): void {
                    $domain = Domain::query()->lockForUpdate()->find($id);
                    if ($domain === null || $domain->nameservers_verified_at !== null
                        || ! in_array($domain->lifecycle_state, [DomainLifecycleState::PendingVerification, DomainLifecycleState::Disabled], true)
                        || ($domain->claim_expires_at ?? $domain->created_at->addDays(7))->isFuture()) {
                        return;
                    }
                    $domain->forceFill(['lifecycle_state' => DomainLifecycleState::Deprovisioning,
                        'deprovision_after' => now(), 'revision' => $domain->revision + 1])->save();
                    Operation::query()->where('type', 'domain.nameservers_verify')->where('input->domain_id', $id)
                        ->whereIn('status', ['pending', 'running'])->update(['status' => 'failed', 'error' => 'The pending claim expired.', 'finished_at' => now()]);
                    Operation::query()->create(['type' => 'domain.deprovision', 'status' => 'pending', 'input' => ['domain_id' => $id, 'revision' => $domain->revision]]);
                    AuditLog::record(null, 'domain.claim_expired', $domain, ['revision' => $domain->revision]);
                    DeprovisionDnsZone::dispatch($id)->afterCommit();
                });
            });

        return self::SUCCESS;
    }
}
