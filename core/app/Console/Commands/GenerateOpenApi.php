<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

class GenerateOpenApi extends Command
{
    protected $signature = 'api:openapi {--check : Fail when the committed contract is stale}';

    protected $description = 'Generate the control-plane OpenAPI route contract';

    public function handle(): int
    {
        $paths = [];
        collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/'))
            ->sortBy(fn (Route $route): string => $route->uri().'|'.implode(',', $route->methods()))
            ->each(function (Route $route) use (&$paths): void {
                $path = '/'.substr($route->uri(), 4);
                foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                    $action = $route->getActionName();
                    $operationId = str($action)->afterLast('\\')->replace('@', '.')->replace('__invoke', 'index')->snake('.')->value();
                    $authenticated = in_array('auth:sanctum', $route->gatherMiddleware(), true);
                    $paths[$path][strtolower($method)] = array_filter([
                        'operationId' => $operationId,
                        'tags' => [str_starts_with($path, '/admin/') ? 'Administrator' : 'Account'],
                        'security' => $authenticated ? [['bearerAuth' => []]] : null,
                        'responses' => [
                            '200' => ['description' => 'Successful response'],
                            '422' => ['$ref' => '#/components/responses/ValidationError'],
                        ],
                    ], fn ($value): bool => $value !== null);
                }
            });
        ksort($paths);

        $document = [
            'openapi' => '3.1.0',
            'info' => ['title' => 'CDNFoundry Control Plane API', 'version' => '1.0.0'],
            'servers' => [['url' => '/api']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer']],
                'responses' => [
                    'ValidationError' => [
                        'description' => 'The provided data is invalid.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]],
                    ],
                ],
                'schemas' => [
                    'ValidationError' => [
                        'type' => 'object',
                        'required' => ['message', 'code', 'errors'],
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'code' => ['type' => 'string', 'const' => 'validation_failed'],
                            'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
        ];

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        $path = base_path('../docs/openapi.json');
        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $json) {
                $this->error('docs/openapi.json is stale. Run php artisan api:openapi.');

                return self::FAILURE;
            }

            $this->info('OpenAPI contract is current.');

            return self::SUCCESS;
        }

        file_put_contents($path, $json);
        $this->info('Generated docs/openapi.json.');

        return self::SUCCESS;
    }
}
