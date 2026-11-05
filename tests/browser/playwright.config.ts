import { defineConfig } from '@playwright/test';

const baseURL = process.env.CDNF_BROWSER_BASE_URL ?? 'http://127.0.0.1:18080';

// Four projects: light and dark theme, each at desktop and phone width.
const viewports = {
    desktop: { width: 1440, height: 900 },
    phone: { width: 390, height: 844 },
};

export default defineConfig({
    testDir: './specs',
    snapshotPathTemplate: '{testDir}/__snapshots__/{projectName}/{arg}{ext}',
    timeout: 60_000,
    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],
    use: { baseURL, trace: 'off' },
    expect: { toHaveScreenshot: { maxDiffPixelRatio: 0.02, animations: 'disabled' } },
    projects: (['light', 'dark'] as const).flatMap((scheme) =>
        (['desktop', 'phone'] as const).map((size) => ({
            name: `${scheme}-${size}`,
            use: { colorScheme: scheme, viewport: viewports[size], browserName: 'chromium' as const },
        })),
    ),
});
