<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

class ErrorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_errors_carry_stable_codes_and_never_name_internal_classes(): void
    {
        $admin = User::factory()->admin()->create();
        Route::middleware('api')->get('/api/contract-probe/throttled', fn () => abort(429, 'Slow down.'));
        Route::middleware('api')->get('/api/contract-probe/broken', fn () => throw new \RuntimeException('secret detail App\\Models\\Hidden'));
        config(['app.debug' => false]);

        $missing = $this->actingAs($admin)->getJson('/api/operations/'.Str::uuid())->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->assertSame('The requested resource was not found.', $missing->json('message'));
        $this->assertStringNotContainsString('App\\Models', $missing->getContent());

        $this->actingAs($admin)->postJson('/api/domains/1/not-a-route')->assertStatus(404);
        $this->actingAs($admin)->postJson('/api/operations/'.Str::uuid())->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
        $this->actingAs($admin)->getJson('/api/contract-probe/throttled')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $broken = $this->actingAs($admin)->getJson('/api/contract-probe/broken')->assertStatus(500)->assertJsonPath('code', 'server_error');
        $this->assertStringNotContainsString('secret detail', $broken->getContent());
    }

    public function test_an_oversized_purge_payload_has_its_own_code(): void
    {
        $user = User::factory()->create();
        $domain = Domain::query()->create(['name' => 'big.example', 'display_name' => 'Big', 'revision' => 1, 'lifecycle_state' => 'active', 'nameservers_verified_at' => now()]);
        $domain->users()->attach($user);

        $this->actingAs($user)->postJson("/api/domains/{$domain->id}/cache/purge", ['type' => 'urls', 'urls' => ['https://big.example/'.str_repeat('a', 130 * 1024)]])
            ->assertStatus(422);
        $this->actingAs($user)->call('POST', "/api/domains/{$domain->id}/cache/purge", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['type' => 'all', 'padding' => str_repeat('a', 130 * 1024)]))
            ->assertStatus(413)->assertJsonPath('code', 'payload_too_large');
    }

    public function test_every_accepted_response_hands_back_an_operation_id(): void
    {
        $missing = [];
        foreach ((new Finder)->files()->in(app_path('Http/Controllers'))->name('*.php') as $file) {
            $lines = explode("\n", $file->getContents());
            foreach ($lines as $index => $line) {
                if (! preg_match('/\b202\)/', $line) && ! preg_match('/\? 200 : 202/', $line)) {
                    continue;
                }
                $statement = implode("\n", array_slice($lines, max(0, $index - 14), 15));
                if (! preg_match('/operation_id|\$operation|OperationResource|\[.operation.\]/', $statement)) {
                    $missing[] = $file->getRelativePathname().':'.($index + 1);
                }
            }
        }

        $this->assertSame([], $missing, 'These 202 responses carry no operation id.');
    }
}
