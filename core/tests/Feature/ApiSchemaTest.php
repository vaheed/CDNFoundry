<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\User;
use App\Support\ApiSchemaData;
use App\Support\SecurityConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ApiSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function ownedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'api.example', 'display_name' => 'api.example', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user->id);

        return [$user, $domain];
    }

    private function document(array $overrides = []): array
    {
        return [...[
            'openapi' => '3.0.3', 'info' => ['title' => 'Orders', 'version' => '1'],
            'components' => ['parameters' => ['Limit' => ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer']]]],
            'paths' => [
                '/orders' => [
                    'get' => ['parameters' => [['$ref' => '#/components/parameters/Limit']]],
                    'post' => ['parameters' => [['name' => 'X-Request-Id', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']]], 'requestBody' => ['content' => ['application/json' => []]]],
                ],
                '/orders/{id}' => ['parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]], 'get' => [], 'delete' => []],
            ],
        ], ...$overrides];
    }

    public function test_a_document_is_compiled_into_a_typed_table_and_sent_to_the_cell(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/security/api-schema";

        $this->actingAs($user)->putJson($url, ['document' => json_encode($this->document()), 'base_path' => '/api/v1/', 'maximum_body_bytes' => 4096])->assertAccepted();
        $domain->refresh();
        $this->assertSame(2, $domain->revision);
        $this->assertSame('/api/v1', $domain->api_schema['base_path']);
        $this->assertSame(['/orders:GET', '/orders:POST', '/orders/{id}:DELETE', '/orders/{id}:GET'], array_map(fn (array $operation): string => "{$operation['path']}:{$operation['method']}", $domain->api_schema['operations']));
        $get = $domain->api_schema['operations'][0]['parameters'][0];
        $this->assertSame(['name' => 'limit', 'in' => 'query', 'required' => false, 'type' => 'integer'], $get);
        $post = $domain->api_schema['operations'][1]['parameters'][0];
        $this->assertSame(['name' => 'x-request-id', 'in' => 'header', 'required' => true, 'type' => 'string'], $post);
        $path = $domain->api_schema['operations'][3]['parameters'][0];
        $this->assertSame(['name' => 'id', 'in' => 'path', 'required' => true, 'type' => 'integer'], $path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.api_schema_updated', 'subject_id' => (string) $domain->id]);
        Queue::assertPushed(ReconcileEdgeDomain::class);

        $compiled = SecurityConfig::compile($domain)['api_schema'];
        $this->assertSame(4096, $compiled['maximum_body_bytes']);
        $this->assertCount(4, $compiled['operations']);
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('data.api_schema.operation_count', 4);

        $this->actingAs($user)->deleteJson($url)->assertAccepted();
        $this->assertNull($domain->refresh()->api_schema);
        $this->assertSame(['operations' => []], SecurityConfig::compile($domain)['api_schema']);
    }

    public function test_an_invalid_document_is_refused_with_a_clear_error_and_the_old_table_stays(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/security/api-schema";
        $this->actingAs($user)->putJson($url, ['document' => $this->document()])->assertAccepted();
        $before = $domain->refresh()->api_schema;
        Queue::fake();

        $tooMany = ['openapi' => '3.0.0', 'paths' => []];
        for ($i = 0; $i <= ApiSchemaData::MAXIMUM_OPERATIONS; $i++) {
            $tooMany['paths']["/p$i"] = ['get' => []];
        }
        $cases = [
            'not json' => [['document' => '{nope'], 'not valid JSON'],
            'swagger 2' => [['document' => ['swagger' => '2.0', 'paths' => ['/a' => ['get' => []]]]], 'OpenAPI 3 version'],
            'no paths' => [['document' => ['openapi' => '3.0.0', 'paths' => []]], 'no paths'],
            'no operations' => [['document' => ['openapi' => '3.0.0', 'paths' => ['/a' => ['summary' => 'x']]]], 'no operations'],
            'bad path' => [['document' => ['openapi' => '3.0.0', 'paths' => ['orders' => ['get' => []]]]], 'not valid'],
            'bad template' => [['document' => ['openapi' => '3.0.0', 'paths' => ['/a/x{id}' => ['get' => []]]]], 'not valid'],
            'external reference' => [['document' => ['openapi' => '3.0.0', 'paths' => ['/a' => ['get' => ['parameters' => [['$ref' => 'https://evil.example/p.json']]]]]]], 'external references'],
            'too many operations' => [['document' => $tooMany], 'more than 200 operations'],
            'oversized' => [['document' => json_encode(['openapi' => '3.0.0', 'x' => str_repeat('a', ApiSchemaData::MAXIMUM_DOCUMENT_BYTES)])], 'larger than'],
            'bad base path' => [['document' => $this->document(), 'base_path' => 'api'], 'base path'],
            'body limit too high' => [['document' => $this->document(), 'maximum_body_bytes' => 2000000], 'body limit'],
        ];
        foreach ($cases as $label => [$body, $message]) {
            $response = $this->actingAs($user)->putJson($url, $body);
            $response->assertUnprocessable();
            $this->assertStringContainsString($message, json_encode($response->json('errors')), $label);
        }
        $this->assertSame($before, $domain->refresh()->api_schema);
        Queue::assertNothingPushed();
    }

    public function test_a_stranger_cannot_read_or_change_the_schema_and_the_violation_field_is_a_rule_field(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $url = "/api/domains/{$domain->id}/security/api-schema";
        $this->actingAs(User::factory()->create())->putJson($url, ['document' => $this->document()])->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson($url)->assertNotFound();
        $this->assertNull($domain->refresh()->api_schema);

        $rule = fn (mixed $value, int $priority) => ['name' => 'schema', 'action' => 'block', 'priority' => $priority, 'conditions' => [['field' => 'schema_violation', 'operator' => 'equals', 'value' => $value]]];
        $post = fn (array $body) => $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $body);
        $post($rule('any', 1))->assertUnprocessable()->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['conditions.0.field' => 'API schema violation needs API schema. Set up API schema under Security first.']);
        $this->actingAs($user)->putJson($url, ['document' => $this->document()])->assertAccepted();
        $post($rule('any', 1))->assertCreated();
        $post($rule('unknown_path', 2))->assertCreated();
        $post($rule('teapot', 3))->assertUnprocessable();

        $this->actingAs($user)->deleteJson($url)->assertConflict()->assertJsonPath('message', 'API schema is used by 2 firewall rules. Delete or change them first.');
        $this->assertNotNull($domain->refresh()->api_schema);
        $domain->securityRules()->delete();
        $this->actingAs($user)->deleteJson($url)->assertAccepted();
    }

    public function test_a_json_body_schema_is_compiled_into_a_bounded_tree_and_cookie_parameters_are_kept(): void
    {
        $document = $this->document(['components' => ['schemas' => ['Line' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer']]]]],
            'paths' => ['/orders' => ['post' => [
                'parameters' => [['name' => 'session', 'in' => 'cookie', 'required' => true, 'schema' => ['type' => 'string']]],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['sku', 'ghost'], 'additionalProperties' => false,
                    'properties' => ['sku' => ['type' => 'string', 'maxLength' => 12], 'kind' => ['type' => 'string', 'enum' => ['pickup', 'delivery']],
                        'lines' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Line']], 'any' => ['oneOf' => [['type' => 'string']]]]]]]],
            ]]]]);
        $table = ApiSchemaData::validate(['document' => $document]);
        $operation = $table['operations'][0];
        $this->assertSame([['name' => 'session', 'in' => 'cookie', 'required' => true, 'type' => 'string']], $operation['parameters']);
        $this->assertTrue($operation['body']['required']);
        $schema = $operation['body']['schema'];
        $this->assertSame(['sku'], $schema['required'], 'a required name that is not a property is dropped');
        $this->assertTrue($schema['closed']);
        $this->assertSame(['pickup', 'delivery'], $schema['properties']['kind']['enum']);
        $this->assertSame(12, $schema['properties']['sku']['max_length']);
        $this->assertSame(['id'], $schema['properties']['lines']['items']['required']);
        $this->assertSame([], (array) $schema['properties']['any'], 'a combinator accepts any value');
        $this->assertFalse(isset($operation['body']['nodes']));
    }

    public function test_body_schemas_that_are_too_deep_recursive_wide_or_external_are_refused(): void
    {
        $deep = ['type' => 'string'];
        for ($level = 0; $level < ApiSchemaData::MAXIMUM_BODY_DEPTH + 1; $level++) {
            $deep = ['type' => 'object', 'properties' => ['a' => $deep]];
        }
        $wide = ['type' => 'object', 'properties' => array_combine(array_map(fn ($i) => "f$i", range(1, ApiSchemaData::MAXIMUM_PROPERTIES + 1)), array_fill(0, ApiSchemaData::MAXIMUM_PROPERTIES + 1, ['type' => 'string']))];
        $cases = [
            'nested' => [$deep, []],
            'recursive' => [['$ref' => '#/components/schemas/Node'], ['schemas' => ['Node' => ['type' => 'object', 'properties' => ['next' => ['$ref' => '#/components/schemas/Node']]]]]],
            'wide' => [$wide, []],
            'external' => [['$ref' => 'https://example.test/schema.json'], []],
            'enum' => [['type' => 'string', 'enum' => array_map('strval', range(1, ApiSchemaData::MAXIMUM_ENUM_VALUES + 1))], []],
            'odd name' => [['type' => 'object', 'properties' => ['a b' => ['type' => 'string']]], []],
        ];
        foreach ($cases as $label => [$schema, $components]) {
            $document = $this->document(['components' => $components, 'paths' => ['/orders' => ['post' => ['requestBody' => ['content' => ['application/json' => ['schema' => $schema]]]]]]]);
            try {
                ApiSchemaData::validate(['document' => $document]);
                $this->fail("$label was accepted");
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $this->assertArrayHasKey('document', $exception->errors(), $label);
            }
        }
    }

    public function test_the_body_violations_are_rule_values_and_event_reasons(): void
    {
        $this->assertContains('invalid_body', ApiSchemaData::VIOLATIONS);
        $this->assertContains('unknown_field', \App\Support\SecurityRuleData::SCHEMA_VIOLATIONS);
        $this->assertContains('schema_invalid_body', SecurityConfig::reasonCodes());
        $this->assertContains('schema_unknown_field', SecurityConfig::reasonCodes());
    }
}
