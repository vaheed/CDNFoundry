<?php

namespace Tests\Feature;

use App\Filament\Domain\Resources\Domains\Pages\ViewDomain;
use App\Filament\Domain\Resources\Domains\RelationManagers\SecurityEventsRelationManager;
use App\Models\Domain;
use App\Models\User;
use App\Support\AttackEpisodes;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AttackEpisodesTest extends TestCase
{
    use RefreshDatabase;

    /** Minutes with their blocked counts, from a start time and offsets in minutes. */
    private function minutes(array $offsets, string $start = '2026-11-20 10:00:00'): array
    {
        $base = strtotime($start.' UTC');

        return array_map(fn (array $pair): array => ['minute' => gmdate('Y-m-d H:i:s', $base + $pair[0] * 60), 'blocked' => $pair[1]], $offsets);
    }

    public function test_blocked_minutes_group_into_one_episode_until_a_quiet_spell_and_small_ones_do_not_count(): void
    {
        $episodes = AttackEpisodes::group($this->minutes([
            [0, 10], [1, 30], [2, 5], [7, 20],     // a gap of five minutes keeps one episode
            [20, 10], [21, 15],                    // a gap of thirteen minutes starts another
            [60, 4], [61, 3],                      // too few blocked requests to be an episode
        ]));

        $this->assertCount(2, $episodes);
        $this->assertSame([25, 65], [$episodes[0]['blocked'], $episodes[1]['blocked']]);
        $this->assertSame('2026-11-20 10:20:00', $episodes[0]['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-20 10:07:00', $episodes[1]['end']->format('Y-m-d H:i:s'));
        $this->assertSame(30, $episodes[1]['peak_per_minute']);
        $this->assertSame([], AttackEpisodes::group([]));
    }

    public function test_the_list_is_bounded_to_the_newest_episodes(): void
    {
        $offsets = array_map(fn (int $index): array => [$index * 60, 25], range(0, 29));

        $this->assertCount(20, AttackEpisodes::group($this->minutes($offsets)));
        $this->assertSame('2026-11-21 15:00:00', AttackEpisodes::group($this->minutes($offsets))[0]['start']->format('Y-m-d H:i:s'));
    }

    public function test_the_api_names_each_episode_with_its_top_rules_paths_and_client_keys_for_the_domain_user_only(): void
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'episodes.example', 'display_name' => 'E', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user->id);
        $now = now('UTC')->toImmutable()->startOfMinute();
        $minutes = [['minute' => $now->subMinutes(30)->format('Y-m-d H:i:s'), 'blocked' => 40], ['minute' => $now->subMinutes(29)->format('Y-m-d H:i:s'), 'blocked' => 60]];
        Http::fake([config('services.clickhouse.url').'*' => function ($request) use ($minutes) {
            $body = $request->body();
            $rows = match (true) {
                str_contains($body, 'GROUP BY minute') => $minutes,
                str_contains($body, 'AS value') && str_contains($body, 'client_key AS value') => [['value' => '0a1b2c3d4e5f', 'blocked' => 70]],
                str_contains($body, 'path AS value') => [['value' => '/login', 'blocked' => 90]],
                str_contains($body, 'security_rule AS value') => [['value' => 'r1', 'blocked' => 100]],
                default => [],
            };

            return Http::response(collect($rows)->map(fn ($row) => json_encode($row))->implode("\n"));
        }]);

        $response = $this->actingAs($user)->getJson("/api/domains/{$domain->id}/security/episodes")->assertOk();
        $this->assertCount(1, $response->json('data'));
        $episode = $response->json('data.0');
        $this->assertSame([100, 60, 2], [$episode['blocked'], $episode['peak_per_minute'], $episode['duration_minutes']]);
        $this->assertSame([['value' => '0a1b2c3d4e5f', 'blocked' => 70]], $episode['top_client_keys']);
        $this->assertSame('/login', $episode['top_paths'][0]['value']);
        $this->assertSame(20, $response->json('meta.maximum_episodes'));
        $this->assertSame(5, $response->json('meta.quiet_minutes'));

        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/security/client-keys")->assertOk()->assertJsonPath('data.0.value', '0a1b2c3d4e5f');
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/security/episodes?from=2020-01-01T00:00:00Z")->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson("/api/domains/{$domain->id}/security/episodes")->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson("/api/domains/{$domain->id}/security/client-keys")->assertNotFound();
    }

    public function test_false_positive_candidates_are_scoped_to_the_domain_user_and_say_which_signals_are_unavailable(): void
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'candidates.example', 'display_name' => 'C', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user->id);
        $sent = [];
        Http::fake([config('services.clickhouse.url').'*' => function ($request) use (&$sent) {
            $sent[] = $request->body();

            return Http::response(json_encode(['client_key' => '0a1b2c3d4e5f', 'blocked_requests' => 4, 'solved_challenges' => 1, 'first_blocked' => '2026-11-20 10:00:00.000', 'last_solved' => '2026-11-20 10:02:00.000', 'top_rule' => 'r7']));
        }]);

        $response = $this->actingAs($user)->getJson("/api/domains/{$domain->id}/security/false-positive-candidates")->assertOk();
        $this->assertSame([['client_key' => '0a1b2c3d4e5f', 'blocked' => 4, 'solved' => 1, 'first_blocked' => '2026-11-20 10:00:00.000', 'last_solved' => '2026-11-20 10:02:00.000', 'top_rule' => 'r7']], $response->json('data'));
        $this->assertSame(20, $response->json('meta.maximum_rows'));
        $this->assertArrayHasKey('bot_breakdown', $response->json('meta.unavailable'));
        $this->assertStringContainsString("'challenge_solved'", $sent[0]);
        $this->assertStringContainsString("NOT IN ('challenge_required'", $sent[0], 'being asked for a challenge is not a block');
        $this->assertStringContainsString('LIMIT', $sent[0]);
        $this->actingAs($user)->getJson("/api/domains/{$domain->id}/security/false-positive-candidates?from=2020-01-01T00:00:00Z")->assertUnprocessable();
        $this->actingAs(User::factory()->create())->getJson("/api/domains/{$domain->id}/security/false-positive-candidates")->assertNotFound();
    }

    public function test_the_panel_offers_the_episodes_and_says_so_when_the_store_does_not_answer(): void
    {
        Queue::fake();
        Filament::setCurrentPanel(Filament::getPanel('app'));
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'episodes-ui.example', 'display_name' => 'E', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);
        $this->actingAs($user);
        Http::fake([config('services.clickhouse.url').'*' => Http::response('', 503)]);

        Livewire::test(SecurityEventsRelationManager::class, ['ownerRecord' => $domain, 'pageClass' => ViewDomain::class])
            ->assertTableActionExists('episodes')->callTableAction('episodes')->assertHasNoTableActionErrors();
        $html = view('filament.domain.security-episodes', ['state' => 'unavailable', 'episodes' => [], 'minimum' => 20, 'quiet' => 5])->render();
        $this->assertStringContainsString('did not answer', $html);
        $this->assertStringContainsString('No attack in the last 24 hours', view('filament.domain.security-episodes', ['state' => 'ready', 'episodes' => [], 'minimum' => 20, 'quiet' => 5])->render());
    }
}
