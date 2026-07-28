<?php

namespace App\Actions;

use App\Models\Domain;
use App\Models\DomainEdgeCell;
use App\Models\DomainEdgePlacement;
use App\Models\Edge;
use App\Models\EdgeCell;
use App\Models\EdgePool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PlanDomainEdgeCells
{
    public static function execute(Domain $domain, DomainEdgePlacement $placement, EdgePool $target): Collection
    {
        $target->refresh();
        $edges = Edge::query()->where('enabled', true)->where('drained', false)->whereNull('identity_revoked_at')->orderBy('id')->get();
        $planned = collect();

        foreach ($edges as $edge) {
            $candidates = $edge->cells()->where('edge_pool_id', $target->id)->where('drained', false)
                ->whereHas('edge.poolEndpoints', fn ($endpoints) => $endpoints->where('edge_pool_id', $target->id))
                ->whereNotIn('status', ['degraded', 'drained', 'stopped'])->orderBy('id')->get();
            if ($candidates->isEmpty()) {
                DomainEdgeCell::query()->where('domain_id', $domain->id)->where('edge_id', $edge->id)
                    ->update(['target_cell_id' => null, 'desired_revision' => $placement->desired_revision, 'state' => 'deploying', 'drain_after' => null, 'last_error' => null]);

                continue;
            }
            if ($candidates->count() < $target->replicas_per_edge) {
                throw new RuntimeException('pool_insufficient_participating_cells');
            }

            $existing = DomainEdgeCell::query()->where('domain_id', $domain->id)->where('edge_id', $edge->id)
                ->orderBy('replica')->get()->keyBy('replica');
            $selected = collect();
            for ($replica = 1; $replica <= $target->replicas_per_edge; $replica++) {
                $row = $existing->get($replica);
                $currentTarget = $row?->targetCell;
                $currentActive = $row?->activeCell;
                $stable = collect([$currentTarget, $currentActive])->filter()->first(fn (EdgeCell $cell): bool => $cell->edge_pool_id === $target->id && $candidates->contains('id', $cell->id) && ! $selected->contains($cell->id)
                );
                $available = $candidates->reject(fn (EdgeCell $cell): bool => $selected->contains($cell->id));
                if ($stable === null) {
                    $available = $available->filter(fn (EdgeCell $cell): bool => self::assignedDomains($cell) < $target->maximum_domains_per_cell);
                }
                $selectedId = self::selectStableCellId($stable?->id, $domain->id, $edge->id, $replica, $available->pluck('id')->all());
                $cell = $candidates->firstWhere('id', $selectedId);
                if ($cell === null) {
                    throw new RuntimeException('pool_cell_domain_capacity_exhausted');
                }
                $selected->push($cell->id);
                $planned->push(DB::transaction(function () use ($cell, $domain, $edge, $placement, $replica, $target): DomainEdgeCell {
                    $cell = EdgeCell::query()->lockForUpdate()->findOrFail($cell->id);
                    $row = DomainEdgeCell::query()->where([
                        'domain_id' => $domain->id, 'edge_id' => $edge->id, 'replica' => $replica,
                    ])->lockForUpdate()->first();
                    $alreadyAssigned = $row !== null && in_array($cell->id, [$row->active_cell_id, $row->target_cell_id], true);
                    if (! $alreadyAssigned && self::assignedDomains($cell) >= $target->maximum_domains_per_cell) {
                        throw new RuntimeException('pool_cell_domain_capacity_exhausted');
                    }
                    if ($row === null) {
                        return DomainEdgeCell::query()->create([
                            'domain_id' => $domain->id, 'edge_id' => $edge->id, 'replica' => $replica,
                            'target_cell_id' => $cell->id, 'desired_revision' => $placement->desired_revision, 'state' => 'deploying',
                        ]);
                    }
                    if ($row->active_cell_id !== $cell->id || $row->desired_revision !== $placement->desired_revision) {
                        $row->update(['target_cell_id' => $cell->id, 'desired_revision' => $placement->desired_revision, 'state' => 'deploying', 'drain_after' => null, 'last_error' => null]);
                    }

                    return $row;
                }));
            }
            DomainEdgeCell::query()->where('domain_id', $domain->id)->where('edge_id', $edge->id)
                ->where('replica', '>', $target->replicas_per_edge)->delete();
        }

        return $planned;
    }

    private static function assignedDomains(EdgeCell $cell): int
    {
        return DomainEdgeCell::query()->where(fn ($query) => $query->where('active_cell_id', $cell->id)->orWhere('target_cell_id', $cell->id))
            ->distinct('domain_id')->count('domain_id');
    }

    public static function selectStableCellId(?int $currentCellId, int $domainId, string $edgeId, int $replica, array $candidateIds): ?int
    {
        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        if ($currentCellId !== null && in_array($currentCellId, $candidateIds, true)) {
            return $currentCellId;
        }
        usort($candidateIds, fn (int $left, int $right): int => strcmp(
            hash('sha256', "{$domainId}:{$edgeId}:{$replica}:{$right}"),
            hash('sha256', "{$domainId}:{$edgeId}:{$replica}:{$left}"),
        ));

        return $candidateIds[0] ?? null;
    }
}
