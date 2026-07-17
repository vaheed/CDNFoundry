<?php

namespace App\Console\Commands;

use App\Enums\DomainLifecycleState;
use App\Jobs\DeprovisionDnsZone;
use App\Models\Domain;
use Illuminate\Console\Command;

class DispatchDueDnsDeprovisioning extends Command
{
    protected $signature = 'dns:deprovision-due';

    protected $description = 'Dispatch bounded DNS deprovisioning work whose delay has elapsed';

    public function handle(): int
    {
        Domain::query()->where('lifecycle_state', DomainLifecycleState::Deprovisioning->value)
            ->where('deprovision_after', '<=', now())->orderBy('id')->limit(1000)->chunkById(100, function ($domains): void {
                $domains->each(fn (Domain $domain) => DeprovisionDnsZone::dispatch($domain->id));
            });

        return self::SUCCESS;
    }
}
