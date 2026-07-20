<?php

namespace App\Providers\Filament;

use App\Filament\Domain\Pages\DomainDashboard;
use App\Filament\Shared\Pages\ApiTokens;
use App\Filament\Shared\Pages\EditProfile;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class DomainPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->brandName('CDNFoundry')
            ->profile(EditProfile::class)
            ->viteTheme('resources/css/filament/shared/theme.css')
            ->readOnlyRelationManagersOnResourceViewPagesByDefault(false)
            ->colors(['primary' => Color::Blue])
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups(['Domains', 'Observe', 'Account'])
            ->discoverResources(in: app_path('Filament/Domain/Resources'), for: 'App\\Filament\\Domain\\Resources')
            ->discoverPages(in: app_path('Filament/Domain/Pages'), for: 'App\\Filament\\Domain\\Pages')
            ->pages([DomainDashboard::class, ApiTokens::class])
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
