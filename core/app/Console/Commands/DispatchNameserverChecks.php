<?php

namespace App\Console\Commands;

use App\Jobs\CheckDomainNameservers;
use App\Models\Domain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchNameserverChecks extends Command
{
    protected $signature = 'cdnf:domains:dispatch-nameserver-checks {--limit=100}';

    protected $description = 'Queue the due scheduled nameserver checks for pending and active domains, a bounded batch at a time';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $ids = DB::transaction(function () use ($limit): array {
            $ids = Domain::query()
                ->whereNotNull('delegation_nameservers')
                ->where(fn ($query) => $query
                    ->where(fn ($active) => $active->where('lifecycle_state', 'active')->whereNotNull('nameservers_verified_at'))
                    ->orWhere(fn ($pending) => $pending->where('lifecycle_state', 'pending_verification')
                        ->where(fn ($claim) => $claim->whereNotNull('nameservers_verified_at')->orWhere('claim_expires_at', '>', now()))))
                ->where(fn ($due) => $due->whereNull('ns_check_next_at')->orWhere('ns_check_next_at', '<=', now()))
                ->orderByRaw('ns_check_next_at is not null, ns_check_next_at, id')
                ->limit($limit)->lock('for update skip locked')->pluck('id')->all();
            // A check that never finishes comes back later instead of being queued every minute.
            Domain::query()->whereIn('id', $ids)->update(['ns_check_next_at' => now()->addMinutes(15)]);

            return $ids;
        });
        foreach ($ids as $id) {
            CheckDomainNameservers::dispatch($id);
        }
        $this->info('Queued '.count($ids).' nameserver checks.');

        return self::SUCCESS;
    }
}
