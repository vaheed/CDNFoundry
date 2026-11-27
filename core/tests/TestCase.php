<?php

namespace Tests;

use App\Models\SystemSetting;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['edge.proxy_secret' => 'test-edge-proxy-secret-0123456789abcdef']);
    }

    /** Active origin monitoring is off for a new installation; tests that exercise checks turn it on the way an administrator would. */
    protected function enableOriginMonitoring(bool $enabled = true): void
    {
        $row = SystemSetting::query()->findOrFail('origin_monitoring');
        $row->update(['values' => [...$row->values, 'enabled' => $enabled]]);
        app()->forgetInstance(PlatformSettings::class);
    }

    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! app()->environment('testing') || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(sprintf(
                'Refusing to run tests outside isolated in-memory SQLite (environment=%s, connection=%s, database=%s).',
                app()->environment(),
                $connection,
                (string) $database,
            ));
        }

        return parent::setUpTraits();
    }
}
