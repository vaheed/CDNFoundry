<?php

namespace Tests\Feature;

use App\Models\Edge;
use App\Support\OriginProbePlanner;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The planner is pure, so the topologies are built in memory: no database and no host. */
class OriginProbePlannerTest extends TestCase
{
    /** @return Collection<int, Edge> */
    private function edges(int $count, array $continents = ['EU'], ?string $group = null): Collection
    {
        return collect(range(1, $count))->map(function (int $index) use ($continents, $group): Edge {
            $edge = new Edge(['name' => "edge-$index", 'continent_code' => $continents[($index - 1) % count($continents)], 'probe_group' => $group]);
            $edge->id = (string) Str::uuid();

            return $edge;
        });
    }

    public function test_the_number_of_checks_follows_the_groups_and_not_the_edges(): void
    {
        $counts = [];
        foreach ([3, 10, 80, 100, 200, 400] as $size) {
            $edges = $this->edges($size, ['EU', 'AS', 'NA', 'SA', 'AF', 'OC']);
            $counts[$size] = count(OriginProbePlanner::plan($edges, $edges, 'origin.example:443', 3, 1)['representatives']);
        }
        $this->assertSame([3 => 3, 10 => 3, 80 => 3, 100 => 3, 200 => 3, 400 => 3], $counts);
        $one = $this->edges(3);
        $this->assertCount(1, OriginProbePlanner::plan($one, $one, 'origin.example:443', 3, 1)['representatives'], 'one region is one group');
    }

    public function test_a_representative_changes_only_when_it_is_unavailable_or_the_epoch_changes(): void
    {
        $edges = $this->edges(12, ['EU', 'AS', 'NA']);
        $first = OriginProbePlanner::plan($edges, $edges, 'origin.example:443', 3, 5);
        $again = OriginProbePlanner::plan($edges, $edges, 'origin.example:443', 3, 5);
        $this->assertSame(array_column($first['groups'], 'edge_id'), array_column($again['groups'], 'edge_id'));

        $lost = $first['representatives'][0];
        $remaining = $edges->reject(fn (Edge $edge): bool => $edge->id === $lost->id);
        $after = OriginProbePlanner::plan($edges, $remaining, 'origin.example:443', 3, 5);
        $this->assertSame(
            array_column(array_slice($first['groups'], 1), 'edge_id'),
            array_column(array_filter($after['groups'], fn (array $row): bool => $row['group'] !== $first['groups'][0]['group']), 'edge_id'),
            'only the group of the lost worker changes its representative'
        );
        $this->assertNotSame($lost->id, $after['groups'][0]['edge_id']);
    }

    public function test_a_group_with_no_usable_edge_is_reported_as_not_monitored(): void
    {
        $edges = $this->edges(6, ['EU', 'AS']);
        $usable = $edges->filter(fn (Edge $edge): bool => $edge->continent_code === 'EU');
        $plan = OriginProbePlanner::plan($edges, $usable, 'origin.example:443', 3, 1);
        $this->assertCount(1, $plan['representatives']);
        $this->assertContains(null, array_column($plan['groups'], 'edge_id'), 'a group nobody can probe stays visible instead of reading healthy');
    }

    public function test_an_administrator_label_splits_a_region_into_networks(): void
    {
        $transitA = $this->edges(4, ['EU'], 'transit-a');
        $transitB = $this->edges(4, ['EU'], 'transit-b');
        $all = $transitA->concat($transitB);
        $plan = OriginProbePlanner::plan($all, $all, 'origin.example:443', 3, 1);
        $this->assertEqualsCanonicalizing(['group:transit-a', 'group:transit-b'], array_column($plan['groups'], 'group'));
        $this->assertCount(2, $plan['representatives']);
    }

    public function test_different_destinations_spread_over_the_workers_of_a_group(): void
    {
        $edges = $this->edges(20);
        $chosen = [];
        foreach (range(1, 200) as $index) {
            $chosen[OriginProbePlanner::plan($edges, $edges, "origin-$index.example:443", 1, 1)['representatives'][0]->id] = true;
        }
        $this->assertGreaterThan(15, count($chosen), 'the load of many origins is spread, not put on one worker');
    }
}
