<?php

namespace App\Console\Commands;

use App\Support\ServedZonePublisher;
use Illuminate\Console\Command;

class PublishServedZones extends Command
{
    protected $signature = 'cdnf:analytics:publish-zones {--force : Publish even if the list has not changed}';

    protected $description = 'Publish the served zones to ClickHouse so DNS telemetry rolls up by zone and domain';

    public function handle(ServedZonePublisher $publisher): int
    {
        $published = $publisher->publish((bool) $this->option('force'));
        $this->info($published > 0 ? "Published {$published} served zone(s)." : 'Served zones are unchanged.');

        return self::SUCCESS;
    }
}
