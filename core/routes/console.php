<?php

use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\IdempotencyKey;
use App\Support\ControlPlane;
use App\Support\ResticBackupRepository;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => IdempotencyKey::class])->hourly()->withoutOverlapping();
Schedule::command('cdnf:dns:deprovision-due')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:analytics:publish-zones')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:dns:repair-drift')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('cdnf:domains:expire-claims')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:domains:finalize-deprovisioning')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:edge:complete-placement-drains')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:edge:promote-placements')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:edge:reconcile-stale-placements')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:edge:dispatch-origin-checks')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:edge:prune-revisions')->dailyAt('02:30')->withoutOverlapping();
Schedule::job(new ReconcilePlatformDnsIdentity)->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:tls:dispatch-maintenance')->hourly()->withoutOverlapping();
Schedule::command('cdnf:tls:dispatch-maintenance --orders-only')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('cdnf:security:reconcile-readiness')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:health:publish')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:waf:expire-exclusions')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:usage:finalize')->hourlyAt(20)->withoutOverlapping();
Schedule::command('cdnf:audit:prune')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('cdnf:backups:create')->dailyAt('01:30')->withoutOverlapping()
    ->when(fn (): bool => app(ResticBackupRepository::class)->configured());
Schedule::command('cdnf:backups:maintain prune')->dailyAt('04:10')->withoutOverlapping()
    ->when(fn (): bool => app(ResticBackupRepository::class)->configured());
Schedule::command('cdnf:backups:maintain check')->weeklyOn(0, '05:00')->withoutOverlapping()
    ->when(fn (): bool => app(ResticBackupRepository::class)->configured());
Schedule::call(fn () => Cache::put('operations:scheduler_heartbeat', now()->toIso8601String(), now()->addMinutes(10)))->name('operations.scheduler-heartbeat')->everyMinute()->withoutOverlapping();

Schedule::command('cdnf:control:observe')->everyMinute()->withoutOverlapping();

// A node that is not the writable active control node runs no scheduled work: a returning old active
// node or a standby with its scheduler started must not repeat backups, renders, or TLS maintenance.
foreach (Schedule::events() as $event) {
    $event->when(fn (): bool => ControlPlane::mayRun())->onOneServer();
}
Schedule::command('cdnf:control:enforce')->everyMinute()->withoutOverlapping();
