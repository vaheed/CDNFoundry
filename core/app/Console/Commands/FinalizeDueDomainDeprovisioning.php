<?php

namespace App\Console\Commands;

use App\Enums\DomainLifecycleState;
use App\Jobs\FinalizeDomainDeprovisioning;
use App\Models\Domain;
use Illuminate\Console\Command;

class FinalizeDueDomainDeprovisioning extends Command
{
    protected $signature = 'domains:finalize-deprovisioning {--limit=100}';

    protected $description = 'Finalize bounded domain retirement after runtime tombstones are safe';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        Domain::query()->where('lifecycle_state', DomainLifecycleState::Deprovisioning->value)
            ->where('deprovision_after', '<=', now())->orderBy('id')->limit($limit)->pluck('id')
            ->each(fn (int $id) => FinalizeDomainDeprovisioning::dispatch($id));

        return self::SUCCESS;
    }
}
