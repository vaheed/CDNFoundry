<?php

namespace App\Filament\Admin\Pages;

use App\Support\SystemHealth;
use App\Support\TopologyGraph;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Cache;

class Topology extends Page
{
    private const NODE_WIDTH = 188;

    private const NODE_HEIGHT = 40;

    private const ROW = 52;

    private const COLUMN_X = ['control' => 16, 'dns' => 276, 'edge' => 536, 'cell' => 796, 'telemetry' => 536];

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-share';

    protected static ?string $navigationLabel = 'Topology';

    protected static string|\UnitEnum|null $navigationGroup = 'Observe';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Topology';

    protected string $view = 'filament.admin.pages.topology';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getSubheading(): ?string
    {
        return 'Control, DNS, PoPs, cells, and telemetry.';
    }

    /** @return array{nodes: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>, width: int, height: int, truncated: bool, counts: array<string, int>} */
    public function getGraphProperty(): array
    {
        $components = Cache::remember('topology:components', 10, fn (): array => app(SystemHealth::class)->components());
        $graph = app(TopologyGraph::class)->build($components);
        $nodes = collect($graph['nodes']);
        $position = [];

        $y = 0;
        foreach ($nodes->where('kind', 'dns') as $node) {
            $position[$node['id']] = [self::COLUMN_X['dns'], $y];
            $y += self::ROW;
        }
        $dnsBottom = $y;

        $y = 0;
        $cellsByEdge = collect($graph['links'])->filter(fn (array $link): bool => str_starts_with($link['from'], 'edge:') && str_starts_with($link['to'], 'cell:'))->groupBy('from');
        foreach ($nodes->where('kind', 'edge') as $edge) {
            $cells = $cellsByEdge->get($edge['id'], collect());
            $rows = max(1, $cells->count());
            $blockHeight = $rows * (self::NODE_HEIGHT / 1.4 + 6);
            $position[$edge['id']] = [self::COLUMN_X['edge'], $y + ($blockHeight - self::NODE_HEIGHT) / 2];
            foreach ($cells->values() as $index => $link) {
                $position[$link['to']] = [self::COLUMN_X['cell'], $y + $index * (self::NODE_HEIGHT / 1.4 + 6)];
            }
            $y += max($blockHeight, self::NODE_HEIGHT) + 12;
        }
        $edgeBottom = max($y, $dnsBottom);

        $control = $nodes->where('kind', 'control')->values();
        foreach ($control as $index => $node) {
            $position[$node['id']] = [self::COLUMN_X['control'], max(0, $edgeBottom / 2 - self::NODE_HEIGHT) + $index * self::ROW];
        }
        $telemetryY = $edgeBottom + 24;
        foreach ($nodes->where('kind', 'telemetry')->values() as $index => $node) {
            $position[$node['id']] = [self::COLUMN_X['telemetry'] + $index * 260, $telemetryY];
        }

        $nodes = $nodes->map(fn (array $node): array => $node + ['x' => $position[$node['id']][0] ?? 0, 'y' => (int) ($position[$node['id']][1] ?? 0)])->all();
        $links = array_map(function (array $link) use ($position): array {
            [$fx, $fy] = $position[$link['from']] ?? [0, 0];
            [$tx, $ty] = $position[$link['to']] ?? [0, 0];
            $sameColumn = abs($fx - $tx) < 1;
            $x1 = $sameColumn ? $fx + self::NODE_WIDTH / 2 : ($fx < $tx ? $fx + self::NODE_WIDTH : $fx);
            $x2 = $sameColumn ? $tx + self::NODE_WIDTH / 2 : ($fx < $tx ? $tx : $tx + self::NODE_WIDTH);
            $y1 = $fy + ($sameColumn ? self::NODE_HEIGHT : self::NODE_HEIGHT / 2);
            $y2 = $ty + ($sameColumn ? 0 : self::NODE_HEIGHT / 2);
            $mid = ($x1 + $x2) / 2;

            return $link + ['path' => $sameColumn
                ? "M{$x1},{$y1} L{$x2},{$y2}"
                : "M{$x1},{$y1} C{$mid},{$y1} {$mid},{$y2} {$x2},{$y2}"];
        }, $graph['links']);

        $width = self::COLUMN_X['cell'] + self::NODE_WIDTH + 16;

        return [
            'nodes' => $nodes, 'links' => $links, 'truncated' => $graph['truncated'],
            'width' => $width, 'height' => (int) ($telemetryY + self::NODE_HEIGHT + 16),
            'nodeWidth' => self::NODE_WIDTH, 'nodeHeight' => self::NODE_HEIGHT,
            'counts' => collect($nodes)->countBy('status')->all(),
        ];
    }
}
