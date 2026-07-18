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
                    $method = strtolower($method);
                    $action = $route->getActionName();
                    $operationId = str($action)->afterLast('\\')->replace('@', '.')->replace('__invoke', 'index')->snake('.')->value();
                    $authenticated = in_array('auth:sanctum', $route->gatherMiddleware(), true);
                    $mutation = in_array($method, ['post', 'put', 'patch', 'delete'], true);
                    $parameters = $this->pathParameters($path);
                    if (in_array('idempotent', $route->gatherMiddleware(), true)) {
                        $parameters[] = ['$ref' => '#/components/parameters/IdempotencyKey'];
                    }
                    if ($method === 'get' && $this->usesCursorPagination($path, $action)) {
                        $parameters[] = ['$ref' => '#/components/parameters/Cursor'];
                    }
                    $responses = ['200' => ['$ref' => '#/components/responses/Success']];
                    if ($mutation) {
                        $responses += [
                            '201' => ['$ref' => '#/components/responses/Created'],
                            '202' => ['$ref' => '#/components/responses/Accepted'],
                            '204' => ['description' => 'The mutation completed with no response body.'],
                        ];
                    }
                    if ($authenticated) {
                        $responses += [
                            '401' => ['$ref' => '#/components/responses/StableError'],
                            '403' => ['$ref' => '#/components/responses/StableError'],
                        ];
                    }
                    if ($mutation) {
                        $responses += [
                            '409' => ['$ref' => '#/components/responses/StableError'],
                            '422' => ['$ref' => '#/components/responses/ValidationError'],
                        ];
                    }
                    $paths[$path][$method] = array_filter([
                        'operationId' => $operationId,
                        'tags' => [str_starts_with($path, '/admin/') ? 'Administrator' : 'Account'],
                        'security' => $authenticated ? [['bearerAuth' => []]] : null,
                        'parameters' => $parameters === [] ? null : $parameters,
                        'requestBody' => $mutation && $method !== 'delete' ? [
                            'required' => false,
                            'content' => ['application/json' => ['schema' => ['type' => 'object', 'maxProperties' => 100, 'additionalProperties' => true]]],
                        ] : null,
                        'responses' => $responses,
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
                'parameters' => [
                    'IdempotencyKey' => [
                        'name' => 'Idempotency-Key', 'in' => 'header', 'required' => false,
                        'description' => 'UUID used to safely replay a mutation with the same method, path, and body.',
                        'schema' => ['type' => 'string', 'format' => 'uuid'],
                    ],
                    'Cursor' => [
                        'name' => 'cursor', 'in' => 'query', 'required' => false,
                        'description' => 'Opaque cursor returned by the preceding page.', 'schema' => ['type' => 'string', 'maxLength' => 2048],
                    ],
                ],
                'responses' => [
                    'Success' => [
                        'description' => 'Successful response.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DataEnvelope']]],
                    ],
                    'Created' => [
                        'description' => 'A desired-state resource was created.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DataEnvelope']]],
                    ],
                    'Accepted' => [
                        'description' => 'Asynchronous work was accepted.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DataEnvelope']]],
                    ],
                    'StableError' => [
                        'description' => 'A stable machine-readable API error.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/StableError']]],
                    ],
                    'ValidationError' => [
                        'description' => 'The provided data is invalid.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]],
                    ],
                ],
                'schemas' => [
                    'DataEnvelope' => [
                        'type' => 'object', 'required' => ['data'],
                        'properties' => [
                            'data' => true,
                            'meta' => ['type' => 'object', 'additionalProperties' => true],
                            'links' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                        'additionalProperties' => false,
                    ],
                    'StableError' => [
                        'type' => 'object', 'required' => ['message', 'code', 'details'],
                        'properties' => [
                            'message' => ['type' => 'string'], 'code' => ['type' => 'string'],
                            'details' => ['type' => 'object', 'additionalProperties' => true],
                        ],
                        'additionalProperties' => false,
                    ],
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

    private function pathParameters(string $path): array
    {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $matches);

        return collect($matches[1])->map(function (string $name): array {
            $schema = match ($name) {
                'operation', 'edge' => ['type' => 'string', 'format' => 'uuid'],
                'checksum' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'group' => ['type' => 'string', 'enum' => array_keys(config('platform.groups', []))],
                default => ['type' => 'integer', 'minimum' => 1],
            };

            return ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $schema];
        })->all();
    }

    private function usesCursorPagination(string $path, string $action): bool
    {
        if (str_contains($action, '@index') || str_ends_with($action, '__invoke')) {
            return ! in_array($path, ['/nameservers'], true);
        }

        return collect(['deployments', 'failures', 'cells', 'revisions', 'tokens', 'domains'])
            ->contains(fn (string $method): bool => str_ends_with($action, '@'.$method));
    }
}
