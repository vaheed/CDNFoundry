<?php

namespace App\Providers;

use App\Support\PlatformSettings;
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
        $this->app->scoped(PlatformSettings::class, fn (): PlatformSettings => new PlatformSettings);
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
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(app(PlatformSettings::class)->integer('rate_limits', 'login_per_minute'))->by($request->ip().'|'.strtolower((string) $request->string('email'))));
        RateLimiter::for('account', function (Request $request): Limit {
            $identity = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            $limit = $request->isMethod('GET')
                ? app(PlatformSettings::class)->integer('rate_limits', 'account_reads_per_minute')
                : app(PlatformSettings::class)->integer('rate_limits', 'account_mutations_per_minute');

            return Limit::perMinute(max(1, $limit))->by($identity);
        });
        RateLimiter::for('bulk', fn (Request $request): Limit => Limit::perMinute(app(PlatformSettings::class)->integer('rate_limits', 'bulk_per_minute'))->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('origin-test', fn (Request $request): Limit => Limit::perMinute(app(PlatformSettings::class)->integer('rate_limits', 'origin_tests_per_minute'))->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('edge-register', fn (Request $request): Limit => Limit::perHour(app(PlatformSettings::class)->integer('rate_limits', 'edge_registrations_per_hour'))->by($request->ip()));
        RateLimiter::for('edge-agent', fn (Request $request): Limit => Limit::perMinute(app(PlatformSettings::class)->integer('rate_limits', 'edge_agent_per_minute'))->by((string) ($request->header('X-Edge-Certificate-Serial') ?: $request->ip())));
    }
}
