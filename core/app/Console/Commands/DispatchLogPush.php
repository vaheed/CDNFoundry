<?php

namespace App\Console\Commands;

use App\Jobs\PushDomainLogs;
use App\Models\LogPushDestination;
use App\Support\PlatformSettings;
use Illuminate\Console\Command;

class DispatchLogPush extends Command
{
    protected $signature = 'cdnf:log-push:dispatch';

    protected $description = 'Queue one bounded log push for each active destination that is due, while the administrator switch is on';

    public function handle(PlatformSettings $settings): int
    {
        if ($settings->get('log_push', 'domain_user_log_push') !== true) {
            return self::SUCCESS;
        }
        $queued = 0;
        LogPushDestination::query()->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->orderBy('id')->limit($settings->integer('log_push', 'max_destinations'))->pluck('id')
            ->each(function (string $id) use (&$queued): void {
                PushDomainLogs::dispatch($id);
                $queued++;
            });
        $this->info("Queued {$queued} log push runs.");

        return self::SUCCESS;
    }
}
