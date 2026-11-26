<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use App\Support\SecurityConfig;
use App\Support\SecurityRuleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RateLimitOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function domain(): Domain
    {
        return Domain::query()->create(['name' => 'rates.example', 'display_name' => 'rates.example', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
    }

    private function createRule(Domain $domain, array $rate, string $action = 'block')
    {
        Queue::fake();
        $body = ['conditions' => [['field' => 'path', 'operator' => 'equals', 'value' => '/login']], 'action' => $action, 'priority' => 10, 'rate_limit' => [...['threshold' => 10, 'period_seconds' => 60, 'duration_seconds' => 60], ...$rate]];

        return $this->actingAs(User::factory()->admin()->create())->postJson("/api/domains/{$domain->id}/security/rules", $body);
    }

    public function test_a_rule_can_slide_count_two_keys_and_count_only_chosen_statuses(): void
    {
        $domain = $this->domain();
        $response = $this->createRule($domain, ['key' => 'cookie', 'key_name' => 'session', 'second_key' => 'country', 'window' => 'sliding', 'scope' => 'shared', 'period_seconds' => 3600, 'count_statuses' => [403, 401, 401]])->assertCreated();

        $stored = $domain->securityRules()->findOrFail($response->json('data.id'))->rate_limit;
        $this->assertSame(['key' => 'cookie', 'threshold' => 10, 'period_seconds' => 3600, 'duration_seconds' => 60, 'window' => 'sliding', 'scope' => 'shared', 'key_name' => 'session', 'second_key' => 'country', 'count_statuses' => [401, 403]], $stored);
        $this->assertSame('10 responses with status 401, 403 per 1 hour per cookie value session and country, sliding, shared among the cells, then block for 1 min', SecurityRuleData::describeRateLimit($stored, 'block'));
    }

    public function test_every_key_a_cell_can_count_by_is_accepted_and_named_keys_need_a_name(): void
    {
        $domain = $this->domain();
        foreach (['asn', 'country', 'client_ip', 'client_ip_path'] as $key) {
            $this->createRule($domain, ['key' => $key, 'threshold' => 5, 'period_seconds' => 86400, 'duration_seconds' => 100 + array_search($key, ['asn', 'country', 'client_ip', 'client_ip_path'])])->assertCreated();
        }
        $this->createRule($domain, ['key' => 'query_arg', 'key_name' => 'token'])->assertCreated();
        $this->createRule($domain, ['key' => 'query_arg'])->assertUnprocessable()->assertJsonValidationErrors('rate_limit.key_name');
        $this->createRule($domain, ['key' => 'cookie', 'key_name' => 'bad name'])->assertUnprocessable();
        $this->createRule($domain, ['key' => 'header', 'header_name' => 'X-Api-Key', 'threshold' => 6])->assertCreated();
    }

    public function test_combinations_options_and_statuses_are_validated(): void
    {
        $domain = $this->domain();
        $this->createRule($domain, ['key' => 'asn', 'second_key' => 'asn'])->assertUnprocessable()->assertJsonValidationErrors('rate_limit.second_key');
        $this->createRule($domain, ['key' => 'client_ip_path', 'second_key' => 'client_ip'])->assertUnprocessable();
        $this->createRule($domain, ['key' => 'client_ip', 'second_key' => 'path'])->assertUnprocessable();
        $this->createRule($domain, ['key' => 'client_ip', 'window' => 'rolling'])->assertUnprocessable();
        $this->createRule($domain, ['key' => 'client_ip', 'scope' => 'global'])->assertUnprocessable();
        $this->createRule($domain, ['key' => 'client_ip', 'period_seconds' => 7200])->assertUnprocessable();
        $this->createRule($domain, ['key' => 'client_ip', 'count_statuses' => [99]])->assertUnprocessable();
        $this->createRule($domain, ['key' => 'client_ip', 'count_statuses' => range(400, 410)])->assertUnprocessable();
        $this->createRule($domain, ['key' => 'client_ip', 'count_statuses' => [401]], 'allow')->assertUnprocessable();
    }

    public function test_the_snapshot_says_how_many_cells_serve_the_domain_and_at_least_one(): void
    {
        $domain = $this->domain();
        $this->assertSame(1, SecurityConfig::compile($domain)['cells_serving']);

    }
}
