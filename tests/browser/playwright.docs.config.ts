import { defineConfig } from '@playwright/test';

// Serve only the built documentation. This suite has no application or database connection.
export default defineConfig({
    testDir: './docs-specs',
    snapshotPathTemplate: '{testDir}/../specs/__snapshots__/{projectName}/{arg}{ext}',
    timeout: 60_000,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: { baseURL: 'http://127.0.0.1:18473/CDNFoundry/', trace: 'off' },
    expect: { toHaveScreenshot: { maxDiffPixelRatio: 0.02, animations: 'disabled' } },
    webServer: {
        command: 'npm --prefix ../../docs run preview -- --host 127.0.0.1 --port 18473 --strictPort',
        url: 'http://127.0.0.1:18473/CDNFoundry/',
        reuseExistingServer: false,
        timeout: 30_000,
    },
    projects: (['light', 'dark'] as const).flatMap((scheme) =>
        (['desktop', 'phone'] as const).map((size) => ({
            name: `${scheme}-${size}`,
            use: {
                colorScheme: scheme,
                viewport: size === 'desktop' ? { width: 1440, height: 900 } : { width: 390, height: 844 },
                browserName: 'chromium' as const,
            },
        })),
    ),
});
