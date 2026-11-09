<?php

namespace App\Console\Commands;

use App\Jobs\CheckDomainRegistration;
use App\Models\Domain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchRegistrationChecks extends Command
{
    protected $signature = 'cdnf:domains:dispatch-registration-checks {--limit=50}';

    protected $description = 'Queue the due registration expiry lookups for verified domains, a bounded batch spread over the interval';

    public function handle(): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $ids = DB::transaction(function () use ($limit): array {
            $ids = Domain::query()
                ->whereNotNull('nameservers_verified_at')->where('lifecycle_state', '<>', 'deprovisioning')
                ->where(fn ($due) => $due->whereNull('registration_next_check_at')->orWhere('registration_next_check_at', '<=', now()))
                ->orderByRaw('registration_next_check_at is not null, registration_next_check_at, id')
                ->limit($limit)->lock('for update skip locked')->pluck('id')->all();
            Domain::query()->whereIn('id', $ids)->update(['registration_next_check_at' => now()->addHour()]);

            return $ids;
        });
        foreach ($ids as $index => $id) {
            // One lookup every few seconds keeps registries from seeing a burst.
            CheckDomainRegistration::dispatch($id)->delay(now()->addSeconds($index * 3));
        }
        $this->info('Queued '.count($ids).' registration checks.');

        return self::SUCCESS;
    }
}
