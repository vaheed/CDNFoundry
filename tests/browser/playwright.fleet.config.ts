import { defineConfig } from '@playwright/test';

const port = process.env.CDNF_FLEET_BROWSER_PORT ?? '18765';
const baseURL = `http://127.0.0.1:${port}`;

// The same four projects as the panel suite: light and dark theme, desktop and phone width.
const viewports = {
    desktop: { width: 1440, height: 900 },
    phone: { width: 390, height: 844 },
};

export default defineConfig({
    testDir: './fleet-specs',
    snapshotPathTemplate: '{testDir}/__snapshots__/{projectName}/{arg}{ext}',
    timeout: 60_000,
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: { baseURL, trace: 'off' },
    expect: { toHaveScreenshot: { maxDiffPixelRatio: 0.02, animations: 'disabled' } },
    webServer: {
        command: 'python3 fleet_server.py',
        url: `${baseURL}/assets/fleet.css`,
        reuseExistingServer: false,
        timeout: 30_000,
        env: { CDNF_FLEET_BROWSER_PORT: port },
    },
    projects: (['light', 'dark'] as const).flatMap((scheme) =>
        (['desktop', 'phone'] as const).map((size) => ({
            name: `${scheme}-${size}`,
            use: { colorScheme: scheme, viewport: viewports[size], browserName: 'chromium' as const },
        })),
    ),
});
