<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Edge;
use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WafScoringFailureMetricTest extends TestCase
{
    use RefreshDatabase;

    private function scrape(): string
    {
        config(['services.metrics.token' => 'metrics-test-token']);
        $this->app['auth']->forgetGuards();

        return $this->withToken('metrics-test-token')->get('/metrics')->assertOk()->getContent();
    }

    private function event(Domain $domain, Edge $edge, string $reason, array $minutes): void
    {
        SecurityEvent::query()->create([
            'domain_id' => $domain->id, 'edge_id' => $edge->id, 'reason_code' => $reason, 'state' => 'normal', 'occurred_at' => now(),
            'bucket_at' => now()->startOfHour(), 'details' => ['count' => array_sum($minutes), 'reported' => array_sum($minutes), 'minutes' => $minutes],
        ]);
    }

    public function test_only_scoring_failures_of_the_last_fifteen_minutes_are_counted(): void
    {
        $domain = Domain::query()->create(['name' => 'scoring.example.test', 'display_name' => 'Scoring', 'revision' => 1, 'nameservers_verified_at' => now()]);
        $edge = Edge::query()->create(['name' => 'scoring-pop', 'country_code' => 'IR', 'continent_code' => 'AS', 'enabled' => true, 'registered_at' => now(), 'last_heartbeat_at' => now(), 'active_sequence' => 0, 'capacity' => []]);
        $now = intdiv(now()->timestamp, 60);

        $this->assertStringContainsString("cdnfoundry_waf_scoring_failures_recent 0\n", $this->scrape());

        $this->event($domain, $edge, 'waf_inspection_failed', [(string) $now => 4, (string) ($now - 10) => 3, (string) ($now - 40) => 50]);
        $this->event($domain, $edge, 'waf_block', [(string) $now => 99]);

        $this->assertStringContainsString("cdnfoundry_waf_scoring_failures_recent 7\n", $this->scrape());
    }
}
