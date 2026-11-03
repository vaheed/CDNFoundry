<?php

namespace App\Console\Commands;

use App\Actions\AssignCellToPool;
use App\Actions\CreateEdge;
use App\Actions\CreatePoolEndpoint;
use App\Models\Edge;
use App\Models\EdgePool;
use App\Support\GeoVocabulary;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class EnrollEdge extends Command
{
    protected $signature = 'cdnf:edges:enroll {name : Edge name} {--country= : ISO country code} {--continent= : Continent code} {--ipv4= : Public IPv4 service address} {--ipv6= : Public IPv6 service address}';

    protected $description = 'Register a PoP, put its cells in the default shared pool, and print its edge ID and one-time bootstrap token as JSON for Fleet';

    public function handle(CreateEdge $create, AssignCellToPool $assign, CreatePoolEndpoint $endpoints): int
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
        $ipv4 = (string) $this->option('ipv4');
        $ipv6 = (string) $this->option('ipv6');
        [$edge, $token] = $create->handle(['name' => $name, 'country_code' => $country, 'continent_code' => $continent], null, null);
        foreach ($edge->cells()->orderBy('slot')->get() as $cell) {
            $assign->handle($pool, $cell, null, null);
        }
        $endpoint = null;
        if (($ipv4 !== '' || $ipv6 !== '') && ! $pool->isSimpleAnycast()) {
            try {
                $endpoint = $endpoints->handle($pool, $edge, ['ipv4' => $ipv4 ?: null, 'ipv6' => $ipv6 ?: null, 'withdrawn' => false], null, null);
            } catch (ValidationException $exception) {
                $this->error('The service address was refused: '.collect($exception->errors())->flatten()->implode(' '));

                return self::FAILURE;
            }
        }
        $this->line(json_encode(['edge_id' => $edge->id, 'bootstrap_token' => $token, 'pool_id' => $pool->id, 'endpoint_id' => $endpoint?->id], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
