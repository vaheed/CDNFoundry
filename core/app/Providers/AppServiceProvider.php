<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Support\PlatformSettings;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

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
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);
        // An empty cell in a clickable row would be a link with no name, so every empty value shows a dash.
        TextColumn::configureUsing(fn (TextColumn $column) => $column->placeholder('—'));
        FilamentTimezone::set(fn (): string => app(PlatformSettings::class)->displayTimezone());

        Queue::before(function (JobProcessing $event): void {
            Context::add('job_id', $event->job->uuid() ?? $event->job->getJobId());
        });
        $clearJobContext = static fn (JobProcessed|JobExceptionOccurred $event) => Context::forget('job_id');
        Queue::after($clearJobContext);
        Queue::exceptionOccurred($clearJobContext);

        Password::defaults(fn (): Password => Password::min(12)->letters()->mixedCase()->numbers());
        // Spraying one password over many accounts is bounded per address; guessing one account's password
        // from many addresses is bounded per account.
        RateLimiter::for('login', function (Request $request): array {
            $perMinute = max(1, app(PlatformSettings::class)->integer('rate_limits', 'login_per_minute'));

            return [
                Limit::perMinute($perMinute)->by('account|'.strtolower((string) $request->string('email'))),
                Limit::perMinute($perMinute * 10)->by('address|'.$request->ip()),
            ];
        });
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
