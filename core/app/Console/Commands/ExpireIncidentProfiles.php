<?php

namespace App\Console\Commands;

use App\Actions\EndIncidentProfile;
use App\Models\Domain;
use Illuminate\Console\Command;

class ExpireIncidentProfiles extends Command
{
    protected $signature = 'cdnf:security:expire-incidents {--limit=200}';

    protected $description = 'End incident profiles whose time is up, in a bounded batch';

    public function handle(): int
    {
        $ended = 0;
        Domain::query()->whereNotNull('incident_expires_at')->where('incident_expires_at', '<=', now())->orderBy('incident_expires_at')
            ->limit(max(1, min(1000, (int) $this->option('limit'))))->get()
            ->each(function (Domain $domain) use (&$ended): void {
                $ended += EndIncidentProfile::execute($domain, null, 'expired') ? 1 : 0;
            });
        $this->info("Ended {$ended} incident profiles.");

        return self::SUCCESS;
    }
}
