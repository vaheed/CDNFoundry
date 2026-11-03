<?php

namespace App\Console\Commands;

use App\Actions\AssignCellToPool;
use App\Actions\CreateEdge;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Support\GeoVocabulary;
use Illuminate\Console\Command;

class EnrollEdge extends Command
{
    protected $signature = 'cdnf:edges:enroll {name : Edge name} {--country= : ISO country code} {--continent= : Continent code}';

    protected $description = 'Register a PoP, put its cells in the default shared pool, and print its edge ID and one-time bootstrap token as JSON for Fleet';

    public function handle(CreateEdge $create, AssignCellToPool $assign): int
    {
        $country = strtoupper((string) $this->option('country'));
        $continent = strtoupper((string) $this->option('continent'));
        $name = (string) $this->argument('name');
        if (! in_array($country, GeoVocabulary::countries(), true) || ! in_array($continent, GeoVocabulary::CONTINENTS, true)) {
            $this->error('Give a valid --country and --continent code.');

            return self::FAILURE;
        }
        if (Edge::query()->where('name', $name)->exists()) {
            $this->error('An edge with this name already exists.');

            return self::FAILURE;
        }
        $pool = EdgePool::defaultShared();
        if ($pool === null) {
            $this->error('There is no enabled shared pool to join. Create one first.');

            return self::FAILURE;
        }
        [$edge, $token] = $create->handle(['name' => $name, 'country_code' => $country, 'continent_code' => $continent], null, null);
        foreach ($edge->cells()->orderBy('slot')->get() as $cell) {
            $assign->handle($pool, $cell, null, null);
        }
        $this->line(json_encode(['edge_id' => $edge->id, 'bootstrap_token' => $token, 'pool_id' => $pool->id], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
