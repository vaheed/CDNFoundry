<?php

use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\IdempotencyKey;
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
Schedule::command('cdnf:domains:finalize-deprovisioning')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:edge:complete-placement-drains')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:edge:reconcile-stale-placements')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:edge:dispatch-origin-checks')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:edge:prune-revisions')->dailyAt('02:30')->withoutOverlapping();
Schedule::job(new ReconcilePlatformDnsIdentity)->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:tls:dispatch-maintenance')->hourly()->withoutOverlapping();
Schedule::command('cdnf:security:reconcile-readiness')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:waf:expire-exclusions')->everyMinute()->withoutOverlapping();
Schedule::command('cdnf:usage:finalize')->hourlyAt(20)->withoutOverlapping();
Schedule::command('cdnf:audit:prune')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('cdnf:backups:create')->dailyAt('01:30')->withoutOverlapping();
Schedule::call(fn () => Cache::put('operations:scheduler_heartbeat', now()->toIso8601String(), now()->addMinutes(10)))->name('operations.scheduler-heartbeat')->everyMinute()->withoutOverlapping();
