<?php

namespace Tests\Feature;

use App\Jobs\ReconcileEdgeDomain;
use App\Models\Domain;
use App\Models\User;
use App\Support\SecurityConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CredentialCheckTest extends TestCase
{
    use RefreshDatabase;

    private function ownedDomain(): array
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'login.example', 'display_name' => 'login.example', 'revision' => 1, 'lifecycle_state' => 'active']);
        $domain->users()->attach($user->id);

        return [$user, $domain];
    }

    private function body(array $extra = []): array
    {
        $body = ['login_paths' => [['path' => '/api/login', 'format' => 'json', 'username_field' => 'email', 'password_field' => 'password']]];
        $body['login_paths'][] = $extra;

        return $body;
    }

    public function test_login_paths_are_saved_revisioned_audited_and_compiled_for_the_edge(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();

        $this->actingAs($user)->putJson("/api/domains/{$domain->id}/security/credential-check", [
            'login_paths' => [
                ['path' => '/login', 'format' => 'form', 'username_field' => 'username', 'password_field' => 'password'],
                ['path' => '/api/login', 'format' => 'json', 'password_field' => 'user.password'],
            ],
        ])->assertAccepted()->assertJsonStructure(['data' => ['operation_id']]);

        $domain->refresh();
        $this->assertSame(2, $domain->revision);
        $this->assertSame(['/api/login', '/login'], array_column($domain->credential_check['login_paths'], 'path'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.credential_check_updated', 'subject_id' => (string) $domain->id]);
        Queue::assertPushed(ReconcileEdgeDomain::class, fn ($job): bool => $job->domainId === $domain->id);
        $compiled = SecurityConfig::compile($domain)['credential_check'];
        $this->assertSame(8192, $compiled['body_bytes']);
        $this->assertCount(2, $compiled['login_paths']);
        $this->assertStringNotContainsString('password":"', json_encode($compiled));

        $this->actingAs($user)->deleteJson("/api/domains/{$domain->id}/security/credential-check")->assertAccepted();
        $this->assertNull($domain->refresh()->credential_check);
        $this->assertSame([], SecurityConfig::compile($domain)['credential_check']['login_paths']);
        $this->assertSame(3, $domain->revision);
    }

    public function test_a_stranger_cannot_read_or_change_the_check(): void
    {
        Queue::fake();
        [, $domain] = $this->ownedDomain();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson("/api/domains/{$domain->id}/security/credential-check")->assertNotFound();
        $this->actingAs($stranger)->putJson("/api/domains/{$domain->id}/security/credential-check", $this->body(['path' => '/x', 'format' => 'form', 'password_field' => 'p']))->assertNotFound();
        $this->assertNull($domain->refresh()->credential_check);
        Queue::assertNothingPushed();
    }

    public function test_paths_and_field_names_are_validated_and_bounded(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $put = fn (array $body) => $this->actingAs($user)->putJson("/api/domains/{$domain->id}/security/credential-check", $body);

        $put(['login_paths' => []])->assertUnprocessable()->assertJsonValidationErrors('login_paths');
        $put($this->body(['path' => 'login', 'format' => 'form', 'password_field' => 'p']))->assertUnprocessable()->assertJsonValidationErrors('login_paths.1.path');
        $put($this->body(['path' => '/a?x=1', 'format' => 'form', 'password_field' => 'p']))->assertUnprocessable()->assertJsonValidationErrors('login_paths.1.path');
        $put($this->body(['path' => '/api/login', 'format' => 'form', 'password_field' => 'p']))->assertUnprocessable()->assertJsonValidationErrors('login_paths.1.path');
        $put($this->body(['path' => '/x', 'format' => 'xml', 'password_field' => 'p']))->assertUnprocessable()->assertJsonValidationErrors('login_paths.1.format');
        $put($this->body(['path' => '/x', 'format' => 'form', 'password_field' => 'bad name!']))->assertUnprocessable()->assertJsonValidationErrors('login_paths.1.password_field');
        $put(['login_paths' => array_map(fn (int $i): array => ['path' => "/p$i", 'format' => 'form', 'password_field' => 'p'], range(1, 6))])->assertUnprocessable()->assertJsonValidationErrors('login_paths');
        $this->assertNull($domain->refresh()->credential_check);
        Queue::assertNothingPushed();
    }

    public function test_leaked_password_is_a_flag_field_for_custom_rules(): void
    {
        Queue::fake();
        [$user, $domain] = $this->ownedDomain();
        $rule = fn ($value, $operator = 'equals') => ['name' => 'Leaked', 'action' => 'block', 'priority' => 1, 'conditions' => [['field' => 'leaked_password', 'operator' => $operator, 'value' => $value]]];

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $rule(true))->assertCreated();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $rule('yes'))->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/security/rules", $rule(true, 'contains'))->assertUnprocessable();
    }
}
