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

    protected $description = 'Send one notification for each attack episode of a domain whose mitigated requests reach their alert threshold';

    public function handle(): int
    {
        Domain::query()->whereNotNull('security_alert')->where('lifecycle_state', 'active')->orderBy('id')->chunkById(200, function ($domains): void {
            foreach ($domains as $domain) {
                $alert = $domain->security_alert;
                if (! is_array($alert) || ! isset($alert['threshold'], $alert['period_minutes'])) {
                    continue;
                }
                $count = RecordSecurityReport::mitigationsInWindow($domain, (int) $alert['period_minutes']);
                // An episode lasts while mitigated requests keep arriving and ends after a quiet spell, so one attack is one alert
                // however long it runs and a later attack alerts again.
                $marker = "notify:security-episode:{$domain->id}";
                $active = RecordSecurityReport::mitigationsInWindow($domain, SecurityAlertData::EPISODE_QUIET_MINUTES) > 0;
                if (Cache::has($marker)) {
                    if ($active) {
                        Cache::put($marker, 1, now()->addMinutes(SecurityAlertData::EPISODE_QUIET_MINUTES));
                    }

                    continue;
                }
                if ($count < (int) $alert['threshold']) {
                    continue;
                }
                Cache::put($marker, 1, now()->addMinutes(SecurityAlertData::EPISODE_QUIET_MINUTES));
                EventPublisher::publish('security.mitigations_high', $domain, "Mitigated requests for {$domain->name}", number_format($count)." requests were blocked in the last {$alert['period_minutes']} minutes, above your alert of ".number_format((int) $alert['threshold']).'. You get one alert for each attack, and another only after five quiet minutes.');
            }
        });

        return self::SUCCESS;
    }
}
