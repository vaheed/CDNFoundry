<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdempotencyReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_the_throttle_runs_before_idempotency_so_a_429_is_never_a_receipt(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $idempotent = array_search('idempotent', $middleware, true);
            if ($idempotent === false) {
                continue;
            }
            foreach ($middleware as $position => $name) {
                if (is_string($name) && str_starts_with($name, 'throttle:') && $name !== 'throttle:account') {
                    $this->assertLessThan($idempotent, $position, $route->uri().' must throttle before it stores receipts');
                }
            }
        }
    }

    public function test_a_success_is_stored_and_replayed_but_a_transient_refusal_is_not(): void
    {
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $this->actingAs($user)->withHeader('Idempotency-Key', $key)->postJson('/api/domains', ['name' => 'stored-one.example'])->assertCreated();
        $this->assertSame(1, IdempotencyKey::query()->where('key', $key)->count());
        $this->actingAs($user)->withHeader('Idempotency-Key', $key)->postJson('/api/domains', ['name' => 'stored-one.example'])
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

        $other = User::factory()->create();
        $forbidden = (string) Str::uuid();
        $this->actingAs($other)->withHeader('Idempotency-Key', $forbidden)->postJson('/api/admin/users', [])->assertForbidden();
        $this->assertSame(0, IdempotencyKey::query()->where('key', $forbidden)->count());
    }

    public function test_an_expired_receipt_is_ignored_and_replaced(): void
    {
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $this->actingAs($user)->withHeader('Idempotency-Key', $key)->postJson('/api/domains', ['name' => 'expiring-one.example'])->assertCreated();
        IdempotencyKey::query()->where('key', $key)->update(['expires_at' => now()->subMinute()]);

        $second = $this->actingAs($user)->withHeader('Idempotency-Key', $key)->postJson('/api/domains', ['name' => 'expiring-two.example'])->assertCreated();
        $this->assertNull($second->headers->get('Idempotency-Replayed'));
        $this->assertTrue(IdempotencyKey::query()->where('key', $key)->firstOrFail()->expires_at->isFuture());
    }
}
