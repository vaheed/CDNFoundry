<?php

namespace App\Support;

use App\Models\Edge;
use Illuminate\Support\Collection;

/**
 * Chooses which edges check one origin. The number of checks follows the number of reachability groups, never the number of edges.
 *
 * A reachability group is the administrator's `probe_group` label when an edge has one, else its continent. The groups present in
 * the domain's placement are ranked for the destination by rendezvous hashing and the first few are monitored; in each, one
 * representative is chosen from the edges that can work now, again by rendezvous hashing, so it changes only when it becomes
 * unavailable or the rotation epoch changes. A group with no usable edge is reported as not monitored and is never counted healthy.
 * Egress address families are not modelled: no field says which family an edge can use.
 */
final class OriginProbePlanner
{
    /** Representatives rotate once a day so one edge does not carry a destination's checks for ever. */
    public const ROTATION_SECONDS = 86400;

    /** @return string the reachability group of an edge */
    public static function group(Edge $edge): string
    {
        return filled($edge->probe_group) ? 'group:'.$edge->probe_group : 'continent:'.strtoupper((string) $edge->continent_code);
    }

    /**
     * @param  Collection<int, Edge>  $placement  every edge that serves the domain
     * @param  Collection<int, Edge>  $usable  the edges of the placement that are enabled, registered and heartbeat-fresh
     * @return array{representatives: list<Edge>, groups: list<array{group: string, edge_id: ?string}>}
     */
    public static function plan(Collection $placement, Collection $usable, string $destination, int $maxGroups, ?int $epoch = null): array
    {
        $epoch ??= intdiv(time(), self::ROTATION_SECONDS);
        $usableIds = $usable->pluck('id')->flip();
        $groups = $placement->groupBy(fn (Edge $edge): string => self::group($edge))->all();
        uksort($groups, fn (string $a, string $b): int => strcmp(self::score($destination, $b), self::score($destination, $a)) ?: strcmp($a, $b));
        $representatives = [];
        $report = [];
        foreach (array_slice(array_keys($groups), 0, max(1, $maxGroups)) as $group) {
            $candidates = collect($groups[$group])->filter(fn (Edge $edge): bool => $usableIds->has($edge->id))
                ->sort(fn (Edge $a, Edge $b): int => strcmp(self::score("$destination|$group|$epoch", $b->id), self::score("$destination|$group|$epoch", $a->id)) ?: strcmp($a->id, $b->id));
            $chosen = $candidates->first();
            $report[] = ['group' => $group, 'edge_id' => $chosen?->id];
            if ($chosen !== null) {
                $representatives[] = $chosen;
            }
        }

        return ['representatives' => $representatives, 'groups' => $report];
    }

    private static function score(string $seed, string $item): string
    {
        return hash('sha256', $seed.'|'.$item);
    }
}
