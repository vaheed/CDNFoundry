import { defineConfig } from '@playwright/test';

// The challenge page is served by a real OpenResty cell, not by Laravel, so it has its own disposable stack (challenge_server.py):
// an origin and two cells on loopback ports that differ only in difficulty. Same four projects as the panel suite.
const viewports = {
    desktop: { width: 1440, height: 900 },
    phone: { width: 390, height: 844 },
};

export default defineConfig({
    testDir: './challenge-specs',
    snapshotPathTemplate: '{testDir}/__snapshots__/{projectName}/{arg}{ext}',
    timeout: 90_000,
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: { baseURL: 'http://localhost:18090', trace: 'off' },
    expect: { toHaveScreenshot: { maxDiffPixelRatio: 0.02, animations: 'disabled' } },
    webServer: {
        command: 'python3 challenge_server.py',
        url: 'http://localhost:18090/',
        reuseExistingServer: false,
        timeout: 240_000,
        gracefulShutdown: { signal: 'SIGTERM', timeout: 30_000 },
    },
    projects: (['light', 'dark'] as const).flatMap((scheme) =>
        (['desktop', 'phone'] as const).map((size) => ({
            name: `${scheme}-${size}`,
            use: { colorScheme: scheme, viewport: viewports[size], browserName: 'chromium' as const },
        })),
    ),
});
