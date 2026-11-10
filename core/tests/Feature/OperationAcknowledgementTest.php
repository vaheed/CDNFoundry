<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private function failedOperation(string $type = 'edge.global_reconcile'): Operation
    {
        return Operation::query()->create([
            'type' => $type, 'status' => 'failed', 'input' => [], 'error' => 'boom',
            'created_at' => now()->subHours(3), 'finished_at' => now()->subHours(2),
        ]);
    }

    public function test_acknowledging_a_failure_removes_it_from_health_but_keeps_the_record(): void
    {
        $admin = User::factory()->admin()->create();
        $failure = $this->failedOperation();
        $this->assertSame(1, Operation::query()->unresolvedFailures()->count());

        $this->actingAs($admin)->postJson("/api/admin/operations/{$failure->id}/acknowledge", [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.acknowledged_at', fn ($value): bool => $value !== null);

        $this->assertSame(0, Operation::query()->unresolvedFailures()->count());
        $this->assertSame('failed', $failure->fresh()->status);
        $this->assertSame($admin->id, $failure->fresh()->acknowledged_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'operation.failure_acknowledged']);
    }

    public function test_only_administrators_can_acknowledge_and_only_failed_operations(): void
    {
        $failure = $this->failedOperation();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson("/api/admin/operations/{$failure->id}/acknowledge", [], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->assertNull($failure->fresh()->acknowledged_at);

        $running = Operation::query()->create(['type' => 'edge.global_reconcile', 'status' => 'running', 'input' => []]);
        $this->actingAs(User::factory()->admin()->create())->postJson("/api/admin/operations/{$running->id}/acknowledge", [], ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(409);
    }

    public function test_a_later_success_of_the_same_global_type_resolves_the_failure(): void
    {
        $this->failedOperation();
        $this->failedOperation('dns.global_reconcile');
        Operation::query()->create(['type' => 'edge.global_reconcile', 'status' => 'succeeded', 'input' => [], 'created_at' => now()->subHour(), 'finished_at' => now()->subMinutes(30)]);

        $remaining = Operation::query()->unresolvedFailures()->pluck('type')->all();
        $this->assertSame(['dns.global_reconcile'], $remaining);
    }

    public function test_acknowledged_failures_do_not_count_in_the_failed_operations_metric(): void
    {
        $this->failedOperation()->update(['finished_at' => now()]);
        $second = $this->failedOperation('dns.global_reconcile');
        $second->update(['finished_at' => now(), 'acknowledged_at' => now()]);

        config(['services.metrics.token' => 'metrics-test-token']);
        $body = $this->withToken('metrics-test-token')->get('/metrics')->assertOk()->getContent();
        $this->assertStringContainsString("cdnfoundry_operations_failed_recent 1\n", $body);
    }
}
