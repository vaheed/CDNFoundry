<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * A domain's API profile: an OpenAPI 3 document in JSON, compiled into a small typed table of paths, methods, and
 * parameters. Only the table is stored and sent to the cell; the document itself is not kept. The cell compares each request
 * under the base path with the table and sets the `schema_violation` rule field. Nothing here blocks by itself.
 */
final class ApiSchemaData
{
    public const MAXIMUM_DOCUMENT_BYTES = 262144;

    public const MAXIMUM_OPERATIONS = 200;

    public const MAXIMUM_PARAMETERS = 20;

    public const DEFAULT_BODY_BYTES = 65536;

    public const MAXIMUM_BODY_BYTES = 1048576;

    public const VIOLATIONS = ['unknown_path', 'wrong_method', 'missing_parameter', 'invalid_parameter', 'body_too_large'];

    private const METHODS = ['get', 'put', 'post', 'delete', 'patch', 'head'];

    private const TYPES = ['string', 'integer', 'number', 'boolean'];

    /** Headers OpenAPI says a parameter must not redefine. */
    private const IGNORED_HEADERS = ['accept', 'content-type', 'authorization'];

    /**
     * @param  array{document?: mixed, base_path?: mixed, maximum_body_bytes?: mixed}  $input
     * @return array{title: string, base_path: string, maximum_body_bytes: int, operations: list<array{path: string, method: string, parameters: list<array{name: string, in: string, required: bool, type: string}>}>}
     */
    public static function validate(array $input): array
    {
        $document = $input['document'] ?? null;
        if (is_string($document)) {
            if (strlen($document) > self::MAXIMUM_DOCUMENT_BYTES) {
                throw self::refuse('document', 'The document is larger than '.number_format(self::MAXIMUM_DOCUMENT_BYTES / 1024).' KB.');
            }
            try {
                $document = json_decode($document, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw self::refuse('document', 'The document is not valid JSON. Upload an OpenAPI 3 document as JSON; YAML is not accepted.');
            }
        } elseif (is_array($document)) {
            if (strlen((string) json_encode($document)) > self::MAXIMUM_DOCUMENT_BYTES) {
                throw self::refuse('document', 'The document is larger than '.number_format(self::MAXIMUM_DOCUMENT_BYTES / 1024).' KB.');
            }
        }
        if (! is_array($document) || array_is_list($document)) {
            throw self::refuse('document', 'The document must be an OpenAPI 3 object.');
        }
        if (! is_string($document['openapi'] ?? null) || ! str_starts_with($document['openapi'], '3.')) {
            throw self::refuse('document', 'The document must declare an OpenAPI 3 version, such as "openapi": "3.0.3".');
        }
        $paths = $document['paths'] ?? null;
        if (! is_array($paths) || $paths === [] || array_is_list($paths)) {
            throw self::refuse('document', 'The document has no paths.');
        }

        $base = self::basePath($input['base_path'] ?? '/');
        $bytes = $input['maximum_body_bytes'] ?? self::DEFAULT_BODY_BYTES;
        if (! is_int($bytes) && ! (is_string($bytes) && ctype_digit($bytes)) && ! (is_float($bytes) && floor($bytes) === $bytes)) {
            throw self::refuse('maximum_body_bytes', 'The body limit must be a whole number of bytes.');
        }
        $bytes = (int) $bytes;
        if ($bytes < 1 || $bytes > self::MAXIMUM_BODY_BYTES) {
            throw self::refuse('maximum_body_bytes', 'The body limit must be between 1 and '.number_format(self::MAXIMUM_BODY_BYTES).' bytes.');
        }

        $operations = [];
        foreach ($paths as $path => $item) {
            $path = (string) $path;
            if (! preg_match('#^/[A-Za-z0-9._~!$&\'()*+,;=:@%/{}-]{0,199}$#', $path) || str_contains($path, '//') || self::brokenTemplate($path)) {
                throw self::refuse('document', "The path $path is not valid. It must start with / and use {name} for variable parts.");
            }
            if (! is_array($item)) {
                throw self::refuse('document', "The path $path must be an object.");
            }
            $shared = self::parameters($document, $item['parameters'] ?? [], $path);
            foreach (self::METHODS as $method) {
                if (! isset($item[$method])) {
                    continue;
                }
                if (! is_array($item[$method])) {
                    throw self::refuse('document', "The $method operation of $path must be an object.");
                }
                if (count($operations) >= self::MAXIMUM_OPERATIONS) {
                    throw self::refuse('document', 'The document has more than '.self::MAXIMUM_OPERATIONS.' operations. Split it into smaller documents or remove unused paths.');
                }
                $merged = $shared;
                foreach (self::parameters($document, $item[$method]['parameters'] ?? [], $path) as $key => $parameter) {
                    $merged[$key] = $parameter;
                }
                foreach (self::templateNames($path) as $name) {
                    $merged["path:$name"] ??= ['name' => $name, 'in' => 'path', 'required' => true, 'type' => 'string'];
                }
                if (count($merged) > self::MAXIMUM_PARAMETERS) {
                    throw self::refuse('document', "The $method operation of $path has more than ".self::MAXIMUM_PARAMETERS.' parameters.');
                }
                $parameters = array_values($merged);
                usort($parameters, fn (array $a, array $b): int => [$a['in'], $a['name']] <=> [$b['in'], $b['name']]);
                $operations[] = ['path' => $path, 'method' => strtoupper($method), 'parameters' => $parameters];
            }
        }
        if ($operations === []) {
            throw self::refuse('document', 'The document has no operations. Each path needs at least a get, post, put, patch, delete, or head entry.');
        }
        usort($operations, fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);
        $title = is_string($document['info']['title'] ?? null) ? mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $document['info']['title']), 0, 100) : '';

        return ['title' => $title, 'base_path' => $base, 'maximum_body_bytes' => $bytes, 'operations' => $operations];
    }

    public static function forEdge(?array $stored): array
    {
        if (! is_array($stored) || ($stored['operations'] ?? []) === []) {
            return ['operations' => []];
        }

        return ['base_path' => $stored['base_path'], 'maximum_body_bytes' => $stored['maximum_body_bytes'], 'operations' => $stored['operations']];
    }

    private static function basePath(mixed $value): string
    {
        $base = is_string($value) ? trim($value) : '';
        if ($base === '') {
            $base = '/';
        }
        if (! preg_match('#^/[A-Za-z0-9._~/-]{0,99}$#', $base) || str_contains($base, '//')) {
            throw self::refuse('base_path', 'The base path must start with / and hold only letters, digits, and . _ ~ - /, such as /api/v1.');
        }

        return $base === '/' ? '/' : rtrim($base, '/');
    }

    private static function brokenTemplate(string $path): bool
    {
        // Every segment is either plain text or exactly one {name}.
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if (str_contains($segment, '{') || str_contains($segment, '}')) {
                if (! preg_match('/^\{[A-Za-z0-9_.-]{1,64}\}$/', $segment)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function templateNames(string $path): array
    {
        preg_match_all('/\{([A-Za-z0-9_.-]+)\}/', $path, $matches);

        return $matches[1];
    }

    /** @return array<string, array{name: string, in: string, required: bool, type: string}> */
    private static function parameters(array $document, mixed $list, string $path): array
    {
        if (! is_array($list) || ! array_is_list($list)) {
            throw self::refuse('document', "The parameters of $path must be a list.");
        }
        $found = [];
        foreach ($list as $parameter) {
            if (is_array($parameter) && isset($parameter['$ref'])) {
                $reference = $parameter['$ref'];
                if (! is_string($reference) || ! preg_match('#^\#/components/parameters/([A-Za-z0-9._-]+)$#', $reference, $match) || ! is_array($document['components']['parameters'][$match[1]] ?? null)) {
                    throw self::refuse('document', 'Only references to #/components/parameters/Name inside the document are accepted. Remove external references.');
                }
                $parameter = $document['components']['parameters'][$match[1]];
            }
            if (! is_array($parameter) || ! is_string($parameter['name'] ?? null) || ! is_string($parameter['in'] ?? null)) {
                throw self::refuse('document', "A parameter of $path needs a name and a location (in).");
            }
            $location = $parameter['in'];
            if ($location === 'cookie') {
                continue;
            }
            if (! in_array($location, ['query', 'header', 'path'], true)) {
                throw self::refuse('document', "The parameter {$parameter['name']} of $path has an unknown location.");
            }
            $name = $location === 'header' ? strtolower($parameter['name']) : $parameter['name'];
            if (! preg_match('/^[A-Za-z0-9_.\[\]-]{1,64}$/', $name)) {
                throw self::refuse('document', "The parameter name {$parameter['name']} of $path is not supported.");
            }
            if ($location === 'header' && in_array($name, self::IGNORED_HEADERS, true)) {
                continue;
            }
            $type = $parameter['schema']['type'] ?? 'string';
            $found["$location:$name"] = [
                'name' => $name, 'in' => $location,
                'required' => $location === 'path' || ($parameter['required'] ?? false) === true,
                'type' => in_array($type, self::TYPES, true) ? $type : 'string',
            ];
        }

        return $found;
    }

    private static function refuse(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }
}
