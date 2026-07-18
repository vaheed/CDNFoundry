<?php

use App\Jobs\ReconcilePlatformDnsIdentity;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => IdempotencyKey::class])->hourly()->withoutOverlapping();
Schedule::command('dns:deprovision-due')->everyMinute()->withoutOverlapping();
Schedule::command('edge:complete-placement-drains')->everyMinute()->withoutOverlapping();
Schedule::command('edge:dispatch-origin-checks')->everyMinute()->withoutOverlapping();
Schedule::job(new ReconcilePlatformDnsIdentity)->everyMinute()->withoutOverlapping();
