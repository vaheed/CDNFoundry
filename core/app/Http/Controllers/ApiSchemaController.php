<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\ApiSchemaData;

class ApiSchemaController extends DetectionSettingsController
{
    protected function column(): string
    {
        return 'api_schema';
    }

    protected function auditName(): string
    {
        return 'security.api_schema';
    }

    protected function validated(array $input): array
    {
        return ApiSchemaData::validate($input);
    }

    protected function payload(Domain $domain): array
    {
        $schema = $domain->api_schema;

        return [
            'api_schema' => is_array($schema) ? [
                'title' => $schema['title'], 'base_path' => $schema['base_path'], 'maximum_body_bytes' => $schema['maximum_body_bytes'],
                'operation_count' => count($schema['operations']), 'operations' => $schema['operations'],
            ] : null,
            'maximum_operations' => ApiSchemaData::MAXIMUM_OPERATIONS, 'maximum_document_bytes' => ApiSchemaData::MAXIMUM_DOCUMENT_BYTES,
            'violations' => ApiSchemaData::VIOLATIONS,
        ];
    }
}
