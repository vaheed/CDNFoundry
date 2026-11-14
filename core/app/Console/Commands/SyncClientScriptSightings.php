<?php

namespace App\Console\Commands;

use App\Models\ClientScriptSighting;
use App\Models\Domain;
use App\Support\AnalyticsStore;
use App\Support\ClientSecurityData;
use App\Support\Notifications\EventPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SyncClientScriptSightings extends Command
{
    protected $signature = 'cdnf:client-security:sync-scripts';

    protected $description = 'Record the scripts and connections browsers reported in the last 30 minutes, and send one notification per domain when a new one appears after its baseline window';

    public function handle(AnalyticsStore $store): int
    {
        $from = CarbonImmutable::now('UTC')->subMinutes(30);
        Domain::query()->whereNotNull('client_security')->where('lifecycle_state', 'active')->orderBy('id')->chunkById(100, function ($domains) use ($store, $from): void {
            $byId = $domains->keyBy('id');
            $reports = collect($store->clientReports($byId->keys()->all(), $from))->groupBy('domain_id');
            foreach ($reports as $domainId => $rows) {
                $this->record($byId[(int) $domainId], $rows->all());
            }
        });

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $rows */
    private function record(Domain $domain, array $rows): void
    {
        $settings = $domain->client_security;
        $baselineEnds = CarbonImmutable::parse($settings['enabled_at'])->addMinutes((int) $settings['baseline_minutes']);
        $count = $domain->clientScriptSightings()->count();
        $new = [];
        foreach ($rows as $row) {
            $key = ['domain_id' => $domain->id, 'kind' => $row['kind'], 'page_path' => $row['page'], 'resource' => $row['resource']];
            $seen = CarbonImmutable::parse($row['first_seen'], 'UTC');
            $last = CarbonImmutable::parse($row['last_seen'], 'UTC');
            $existing = ClientScriptSighting::query()->where($key)->first();
            if ($existing !== null) {
                if ($last->isAfter($existing->last_seen_at)) {
                    $existing->update(['last_seen_at' => $last]);
                }

                continue;
            }
            if ($count >= ClientSecurityData::MAXIMUM_SIGHTINGS) {
                continue;
            }
            $baseline = $seen->isBefore($baselineEnds);
            $sighting = ClientScriptSighting::query()->create($key + [
                'first_seen_at' => $seen, 'last_seen_at' => $last,
                'approved_at' => $baseline ? now() : null, 'source' => $baseline ? 'baseline' : 'reported',
            ]);
            $count++;
            if (! $baseline) {
                $new[] = $sighting;
            }
        }
        if ($new === []) {
            return;
        }
        $first = $new[0];
        EventPublisher::publish('client_script.new', $domain, "New script or connection on {$domain->name}", count($new) === 1
            ? "A browser reported {$first->resource} ({$first->kind}) on {$first->page_path}. Approve it in the domain page if it is expected."
            : count($new).' new scripts or connections were reported, the first being '.$first->resource.' on '.$first->page_path.'. Approve them in the domain page if they are expected.');
    }
}
