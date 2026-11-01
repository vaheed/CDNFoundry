<?php

namespace Tests\Feature;

use App\Jobs\MaintainBackupRepository;
use App\Models\Backup;
use App\Models\Operation;
use App\Support\ResticBackupRepository;
use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class BackupMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_and_check_are_queued_operations_and_refused_without_a_repository(): void
    {
        Queue::fake();
        $this->mock(ResticBackupRepository::class, fn (MockInterface $mock) => $mock->shouldReceive('configured')->andReturn(false, true, true));

        $this->artisan('cdnf:backups:maintain prune')->assertFailed();
        $this->artisan('cdnf:backups:maintain prune')->assertSuccessful();
        $this->artisan('cdnf:backups:maintain check')->assertSuccessful();
        $this->artisan('cdnf:backups:maintain defrag')->assertFailed();

        Queue::assertPushed(MaintainBackupRepository::class, 2);
        $this->assertDatabaseHas('operations', ['type' => 'backup.prune', 'status' => 'pending']);
        $this->assertDatabaseHas('operations', ['type' => 'backup.check', 'status' => 'pending']);
    }

    public function test_prune_keeps_a_bounded_history_and_a_failed_check_degrades_backup_health(): void
    {
        $repository = $this->mock(ResticBackupRepository::class);
        $repository->shouldReceive('prune')->once();
        $repository->shouldReceive('check')->once()->andThrow(new RuntimeException('pack 12ab is damaged'));
        $prune = Operation::query()->create(['type' => 'backup.prune', 'status' => 'pending', 'input' => []]);
        $check = Operation::query()->create(['type' => 'backup.check', 'status' => 'pending', 'input' => []]);
        Backup::query()->create(['status' => 'succeeded', 'snapshot_id' => str_repeat('a', 64), 'verified_at' => now()]);

        (new MaintainBackupRepository($prune->id, 'prune'))->handle($repository);
        $this->assertSame('succeeded', $prune->refresh()->status);

        try {
            (new MaintainBackupRepository($check->id, 'check'))->handle($repository);
        } catch (RuntimeException $exception) {
            (new MaintainBackupRepository($check->id, 'check'))->failed($exception);
        }
        $this->assertSame('failed', $check->refresh()->status);
        $components = app(SystemHealth::class)->components();
        $this->assertSame('degraded', $components['backups']['status']);
        $this->assertSame('failed', $components['backups']['details']['last_integrity_check']);
    }
}
