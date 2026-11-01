<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/** Fails when a screen falls back to Filament's default theme or colors, or hard-codes a color. */
class DesignSystemConsistencyTest extends TestCase
{
    public function test_both_panels_use_the_shared_theme_and_palette(): void
    {
        foreach (['admin', 'app'] as $id) {
            $panel = Filament::getPanel($id);
            $this->assertNotNull($panel->getViteTheme(), "{$id} panel has no CDNFoundry theme");
            $this->assertStringContainsString('shared/theme.css', (string) (is_array($panel->getViteTheme()) ? implode(',', $panel->getViteTheme()) : $panel->getViteTheme()));
            $this->assertArrayHasKey('primary', $panel->getColors());
            $this->assertArrayHasKey('gray', $panel->getColors());
        }
    }

    public function test_the_stylesheet_takes_colors_only_from_tokens(): void
    {
        $css = file_get_contents(resource_path('css/filament/shared/theme.css'));
        $this->assertIsString($css);
        // Token blocks (:root and .dark) define the palette; every other rule must reference tokens.
        $withoutTokens = preg_replace('/(?:^|\n)(?::root|\.dark) \{.*?\n\}\n/s', "\n", $css);
        $this->assertDoesNotMatchRegularExpression('/rgb\(\d+ \d+ \d+(?: \/ [\d.]+)?\)/', $withoutTokens, 'raw rgb() colors outside the token blocks');
        // #0000 is the transparent reset Tailwind uses for ring shadows, not a palette color.
        $this->assertDoesNotMatchRegularExpression('/#(?!0000\b)[0-9a-fA-F]{3,8}\b/', $withoutTokens, 'raw hex colors outside the token blocks');
    }

    public function test_filament_views_have_no_inline_colors_or_hex_values(): void
    {
        $offenders = [];
        foreach (['views/filament', 'views/components/ui'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path($directory)));
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                if (preg_match('/style="[^"]*(?:color|background)\s*:|#[0-9a-fA-F]{6}\b/', $contents)) {
                    $offenders[] = str_replace(resource_path().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders, 'views hard-code colors');
    }
}
