<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;

/** The shell behavior both panels share: theme, accent, search key binding, SPA navigation, and the version footer. */
class PanelDesign
{
    /** Kumo's brand blue as Filament's primary 600, the shade Filament fills buttons with. */
    public const BRAND = 'oklch(0.5772 0.2324 260)';

    /** Neutral greys whose darkest shades are a soft charcoal, so dark mode is never pure black. */
    public const GRAY = [
        50 => 'oklch(0.985 0 0)',
        100 => 'oklch(0.97 0 0)',
        200 => 'oklch(0.922 0 0)',
        300 => 'oklch(0.87 0 0)',
        400 => 'oklch(0.708 0 0)',
        500 => 'oklch(0.556 0 0)',
        600 => 'oklch(0.439 0 0)',
        700 => 'oklch(0.371 0.005 286)',
        800 => 'oklch(0.32 0.005 286)',
        900 => 'oklch(0.235 0.004 286)',
        950 => 'oklch(0.18 0.004 286)',
    ];

    public static function apply(Panel $panel): Panel
    {
        PhosphorIcons::register();

        return $panel
            ->viteTheme('resources/css/filament/shared/theme.css')
            ->colors([
                'primary' => array_replace(Color::Blue, [600 => self::BRAND]),
                'gray' => self::GRAY,
                'danger' => Color::Red,
                'warning' => Color::Orange,
                'success' => Color::Emerald,
                'info' => Color::Blue,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->globalSearchDebounce('300ms')
            ->spa()
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): string => Blade::render('<x-ui.sidebar-footer />'));
    }
}
