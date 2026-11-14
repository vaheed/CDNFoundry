<?php

namespace App\Console\Commands;

use App\Actions\RecordSecurityReport;
use App\Models\Domain;
use App\Support\Notifications\EventPublisher;
use App\Support\SecurityAlertData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class NotifySecurityMitigations extends Command
{
    protected $signature = 'cdnf:notifications:security-mitigations';

    protected $description = 'Send one notification for each domain whose mitigated requests reach their alert threshold, then stay quiet for an hour';

    public function handle(): int
    {
        Domain::query()->whereNotNull('security_alert')->where('lifecycle_state', 'active')->orderBy('id')->chunkById(200, function ($domains): void {
            foreach ($domains as $domain) {
                $alert = $domain->security_alert;
                if (! is_array($alert) || ! isset($alert['threshold'], $alert['period_minutes'])) {
                    continue;
                }
                $count = RecordSecurityReport::mitigationsInWindow($domain, (int) $alert['period_minutes']);
                // One notification per domain and hour, however long the attack keeps going.
                if ($count < (int) $alert['threshold'] || ! Cache::add("notify:security-mitigations:{$domain->id}", 1, now()->addMinutes(SecurityAlertData::COOLDOWN_MINUTES))) {
                    continue;
                }
                EventPublisher::publish('security.mitigations_high', $domain, "Mitigated requests for {$domain->name}", number_format($count)." requests were blocked in the last {$alert['period_minutes']} minutes, above your alert of ".number_format((int) $alert['threshold']).'. This alert is sent at most once an hour.');
            }
        });

        return self::SUCCESS;
    }
}
