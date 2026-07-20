<?php

namespace Tests\Feature;

use App\Jobs\CreateControlBackup;
use App\Jobs\DeleteControlBackup;
use App\Jobs\PreflightBackupRestore;
use App\Models\Backup;
use App\Models\Operation;
use App\Models\User;
use App\Support\ResticBackupRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BackupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_creation_is_admin_only_async_and_audited(): void
    {
        Queue::fake();
        $this->mock(ResticBackupRepository::class, fn (MockInterface $mock) => $mock->shouldReceive('configured')->once()->andReturnTrue());
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/admin/backups')->assertForbidden();
        $response = $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/admin/backups')->assertAccepted();
        Queue::assertPushed(CreateControlBackup::class, fn ($job) => $job->backupId === $response->json('data.backup_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.create_requested']);
        $this->actingAs($admin)->getJson('/api/admin/backups')->assertOk()->assertJsonPath('data.0.status', 'pending');
    }

    public function test_restore_requires_exact_confirmation_reauthentication_and_preflight(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create(['password' => 'correct horse battery staple']);
        $backup = Backup::query()->create(['requested_by' => $admin->id, 'status' => 'succeeded', 'snapshot_id' => str_repeat('a', 64), 'verified_at' => now()]);
        $url = "/api/admin/backups/{$backup->id}/restore";
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['confirmation' => 'wrong', 'current_password' => 'correct horse battery staple'])->assertUnprocessable();
        $response = $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, ['confirmation' => "RESTORE {$backup->id}", 'current_password' => 'correct horse battery staple'])->assertAccepted();
        Queue::assertPushed(PreflightBackupRestore::class, fn ($job) => $job->operationId === $response->json('data.operation_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.restore_preflight_requested']);
    }

    public function test_delete_is_async_and_running_backup_is_preserved(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $running = Backup::query()->create(['requested_by' => $admin->id, 'status' => 'running']);
        $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->deleteJson("/api/admin/backups/{$running->id}")->assertConflict();
        $backup = Backup::query()->create(['requested_by' => $admin->id, 'status' => 'succeeded', 'snapshot_id' => str_repeat('b', 64), 'verified_at' => now()]);
        $response = $this->actingAs($admin)->withHeader('Idempotency-Key', (string) Str::uuid())->deleteJson("/api/admin/backups/{$backup->id}")->assertAccepted();
        Queue::assertPushed(DeleteControlBackup::class, fn ($job) => $job->operationId === $response->json('data.operation_id'));
        $this->assertSame('deleting', $backup->refresh()->status);
    }

    public function test_backup_job_records_verified_snapshot_and_operation_receipt(): void
    {
        $backup = Backup::query()->create(['status' => 'pending']);
        $operation = Operation::query()->create(['type' => 'backup.create', 'status' => 'pending', 'input' => ['backup_id' => $backup->id]]);
        $snapshot = str_repeat('c', 64);
        $repository = $this->mock(ResticBackupRepository::class, fn (MockInterface $mock) => $mock->shouldReceive('create')->once()->andReturn([
            'snapshot_id' => $snapshot,
            'size_bytes' => 1234,
            'manifest_sha256' => str_repeat('d', 64),
        ]));

        (new CreateControlBackup($backup->id, $operation->id))->handle($repository);

        $this->assertSame('succeeded', $backup->refresh()->status);
        $this->assertSame($snapshot, $backup->snapshot_id);
        $this->assertNotNull($backup->verified_at);
        $this->assertSame('succeeded', $operation->refresh()->status);
        $this->assertSame($snapshot, $operation->result['snapshot_id']);
    }

    public function test_backup_job_failure_is_bounded_and_preserves_durable_failure_state(): void
    {
        $backup = Backup::query()->create(['status' => 'running']);
        $operation = Operation::query()->create(['type' => 'backup.create', 'status' => 'running', 'input' => ['backup_id' => $backup->id]]);
        $job = new CreateControlBackup($backup->id, $operation->id);

        $job->failed(new RuntimeException(str_repeat('x', 5000)));

        $this->assertSame('failed', $backup->refresh()->status);
        $this->assertSame(4000, mb_strlen($backup->last_error));
        $this->assertSame('failed', $operation->refresh()->status);
        $this->assertSame(4000, mb_strlen($operation->error));
    }

    public function test_restore_executor_fails_closed_without_explicit_maintenance_permission(): void
    {
        putenv('BACKUP_RESTORE_ALLOWED');

        $this->artisan('backups:restore', ['operation' => (string) Str::uuid()])
            ->expectsOutput('Set BACKUP_RESTORE_ALLOWED=true only in the one-off maintenance container.')
            ->assertFailed();
    }

    public function test_restore_preflight_remains_running_until_maintenance_executor_finishes(): void
    {
        $snapshot = str_repeat('e', 64);
        $backup = Backup::query()->create(['status' => 'succeeded', 'snapshot_id' => $snapshot, 'verified_at' => now()]);
        $operation = Operation::query()->create(['type' => 'backup.restore', 'status' => 'pending', 'input' => ['backup_id' => $backup->id]]);
        $repository = $this->mock(ResticBackupRepository::class, fn (MockInterface $mock) => $mock->shouldReceive('snapshotExists')->once()->with($snapshot)->andReturnTrue());

        (new PreflightBackupRestore($backup->id, $operation->id))->handle($repository);

        $operation->refresh();
        $this->assertSame('running', $operation->status);
        $this->assertSame('passed', $operation->result['preflight']);
        $this->assertNull($operation->finished_at);
    }
}
