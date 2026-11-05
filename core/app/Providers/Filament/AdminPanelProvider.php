<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\AdminDashboard;
use App\Filament\Domain\Resources\Domains\DomainResource;
use App\Filament\Shared\Pages\ApiTokens;
use App\Filament\Shared\Pages\EditProfile;
use App\Support\GrafanaExploreUrl;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return PanelDesign::apply($panel)
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('CDNFoundry Administration')
            ->favicon(asset('favicon.svg'))
            ->profile(EditProfile::class)
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()])
            ->readOnlyRelationManagersOnResourceViewPagesByDefault(false)
            ->databaseNotifications()
            ->navigationGroups(['Observe', 'Operate', 'Infrastructure', 'Customers', 'Governance', 'Account'])
            ->navigationItems([
                NavigationItem::make('Live Logs')
                    ->group('Observe')
                    ->icon('phosphor-file-magnifying-glass')
                    ->sort(100)
                    ->url(fn (): ?string => app(GrafanaExploreUrl::class)->configuredOperationalLogs(), shouldOpenInNewTab: true)
                    ->visible(fn (): bool => filled(app(GrafanaExploreUrl::class)->configuredOperationalLogs())),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->resources([DomainResource::class])
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([AdminDashboard::class, ApiTokens::class])
            ->widgets([AccountWidget::class])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
