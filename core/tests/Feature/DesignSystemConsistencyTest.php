<?php

namespace Tests\Feature;

use App\Providers\Filament\PanelDesign;
use App\Providers\Filament\PhosphorIcons;
use Filament\Facades\Filament;
use Tests\TestCase;

/** Fails when a screen falls back to Filament's default theme, colors, or icons, or hard-codes a color. */
class DesignSystemConsistencyTest extends TestCase
{
    public function test_both_panels_use_the_shared_theme_and_palette(): void
    {
        foreach (['admin', 'app'] as $id) {
            $panel = Filament::getPanel($id);
            $this->assertNotNull($panel->getViteTheme(), "{$id} panel has no CDNFoundry theme");
            $this->assertStringContainsString('shared/theme.css', (string) (is_array($panel->getViteTheme()) ? implode(',', $panel->getViteTheme()) : $panel->getViteTheme()));
            $this->assertArrayHasKey('primary', $panel->getColors());
            $this->assertSame(PanelDesign::GRAY, $panel->getColors()['gray'], "{$id} panel greys must be the shared neutral palette");
        }
    }

    public function test_the_stylesheet_takes_colors_only_from_tokens(): void
    {
        $css = file_get_contents(resource_path('css/filament/shared/theme.css'));
        $this->assertIsString($css);
        // Token blocks (:root and .dark) define the palette; every other rule must reference tokens.
        $withoutTokens = preg_replace('/(?:^|\n)(?::root|\.dark) \{.*?\n\}\n/s', "\n", $css);
        $this->assertDoesNotMatchRegularExpression('/\b(?:rgba?|hsla?|oklch|oklab|lab|lch)\(\s*[\d.]/', $withoutTokens, 'raw color functions outside the token blocks');
        // #0000 is the transparent reset Tailwind uses for ring shadows, not a palette color.
        $this->assertDoesNotMatchRegularExpression('/#(?!0000\b)[0-9a-fA-F]{3,8}\b/', $withoutTokens, 'raw hex colors outside the token blocks');
        $this->assertDoesNotMatchRegularExpression('/\b(?:white|black)\s*;/', $withoutTokens, 'named colors outside the token blocks');
    }

    public function test_filament_views_have_no_inline_colors_or_hex_values(): void
    {
        $offenders = [];
        foreach ($this->files(['views/filament', 'views/components/ui'], '.blade.php', resource_path()) as $path => $contents) {
            if (preg_match('/style="[^"]*(?:color|background)\s*:|#[0-9a-fA-F]{6}\b/', $contents)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'views hard-code colors');
    }

    public function test_screens_use_phosphor_icons_only(): void
    {
        $offenders = [];
        $sources = $this->files(['Filament', 'Providers/Filament', 'Support'], '.php', app_path())
            + $this->files(['views/filament', 'views/components/ui'], '.blade.php', resource_path());
        foreach ($sources as $path => $contents) {
            if (preg_match('/heroicon-|Heroicon::/', $contents)) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'screens still reference Heroicons');
        $this->assertNotEmpty(PhosphorIcons::aliases());
    }

    public function test_every_phosphor_icon_name_exists(): void
    {
        $names = array_map(fn (string $name): string => 'phosphor-'.$name, PhosphorIcons::aliases());
        $sources = $this->files(['Filament', 'Support'], '.php', app_path())
            + $this->files(['views/filament', 'views/components/ui'], '.blade.php', resource_path());
        foreach ($sources as $contents) {
            preg_match_all('/phosphor-[a-z0-9-]+/', $contents, $matches);
            $names = [...$names, ...$matches[0]];
        }

        $directory = base_path('vendor/codeat3/blade-phosphor-icons/resources/svg');
        $missing = array_values(array_filter(array_unique($names), fn (string $name): bool => ! is_file($directory.'/'.substr($name, strlen('phosphor-')).'.svg')));

        $this->assertSame([], $missing, 'unknown Phosphor icon names');
    }

    /** @return array<string, string> relative path => contents */
    private function files(array $directories, string $suffix, string $base): array
    {
        $files = [];
        foreach ($directories as $directory) {
            if (! is_dir($base.'/'.$directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base.'/'.$directory));
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                    $files[str_replace($base.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $files;
    }
}
