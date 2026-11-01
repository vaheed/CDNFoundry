<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

/** The shell behavior both panels share: theme, accent, search key binding, SPA navigation, and the version footer. */
class PanelDesign
{
    public static function apply(Panel $panel): Panel
    {
        return $panel
            ->viteTheme('resources/css/filament/shared/theme.css')
            ->colors(['primary' => Color::Blue, 'gray' => Color::Stone])
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchDebounce('300ms')
            ->spa()
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): string => Blade::render('<x-ui.sidebar-footer />'));
    }
}
