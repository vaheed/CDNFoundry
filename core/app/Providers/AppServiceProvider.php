<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Keep console commands run as root from creating compiled views that
        // the unprivileged PHP-FPM process cannot later refresh.
        $effectiveUserId = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $compiledViewPath = storage_path('framework/views/'.$effectiveUserId);
        if (! is_dir($compiledViewPath)
            && ! mkdir($compiledViewPath, 0775, true)
            && ! is_dir($compiledViewPath)) {
            throw new \RuntimeException("Unable to create compiled view directory: {$compiledViewPath}");
        }
        $this->app['config']->set(
            'view.compiled',
            $compiledViewPath,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip().'|'.$request->string('email')));
    }
}
