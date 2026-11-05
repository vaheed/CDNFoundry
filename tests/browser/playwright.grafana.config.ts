import { defineConfig } from '@playwright/test';

const baseURL = process.env.GRAFANA_URL ?? 'http://127.0.0.1:3000';

// Three screens: a desktop browser, a phone, and a 1080p kiosk. The dashboards suite loads every dashboard on each.
const viewports = {
    desktop: { width: 1440, height: 900 },
    phone: { width: 390, height: 844 },
    kiosk: { width: 1920, height: 1080 },
};

export default defineConfig({
    testDir: './grafana',
    outputDir: './test-results/grafana',
    timeout: 180_000,
    fullyParallel: true,
    workers: Number(process.env.GRAFANA_BROWSER_WORKERS ?? 4),
    retries: 0,
    reporter: [['list'], ['json', { outputFile: 'test-results/grafana/report.json' }]],
    use: { baseURL, trace: 'off', ignoreHTTPSErrors: true },
    projects: (['desktop', 'phone', 'kiosk'] as const).map((size) => ({
        name: size,
        use: { viewport: viewports[size], browserName: 'chromium' as const },
    })),
});
