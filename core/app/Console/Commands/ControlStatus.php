<?php

namespace App\Console\Commands;

use App\Models\Edge;
use App\Support\ControlPlane;
use Illuminate\Console\Command;

class ControlStatus extends Command
{
    protected $signature = 'cdnf:control:status';

    protected $description = 'Print the high availability state as JSON';

    public function handle(): int
    {
        $fresh = now()->subSeconds(120);
        $edges = ['enabled' => Edge::query()->where('enabled', true)->count(), 'recent_heartbeat' => Edge::query()->where('enabled', true)->where('last_heartbeat_at', '>=', $fresh)->count()];
        $this->line(json_encode([...ControlPlane::status(), 'addresses' => ControlPlane::addresses(), 'fenced' => ControlPlane::fencedReason(), 'edges' => $edges], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
