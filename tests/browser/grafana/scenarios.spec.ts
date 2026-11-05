import { test, expect } from '@playwright/test';
import { adminUser, adminPassword, signIn, waitForPanels } from './helpers';

// Each seed scenario changes the panels that should change, fires its alert, routes it to the receiver of its team,
// and shows the alert in Grafana's alerting UI. Run with: npx playwright test -c playwright.grafana.config.ts scenarios
const exporter = process.env.SEED_EXPORTER_URL ?? 'http://127.0.0.1:9108';
const alertmanager = process.env.ALERTMANAGER_URL ?? 'http://127.0.0.1:9093';

const scenarios = [
    { name: 'origin-down', alert: 'EdgeGlobal5xxRatioHigh', team: 'noc', dashboard: '/d/cdnf-global-traffic', wait: 12 },
    { name: 'l7-flood', alert: 'DomainRequestFlood', team: 'soc', dashboard: '/d/cdnf-l7-flood', wait: 10 },
    { name: 'dns-flood', alert: 'DnsNxdomainFlood', team: 'soc', dashboard: '/d/cdnf-dns-abuse', wait: 12 },
    { name: 'pop-loss', alert: 'EdgeHeartbeatStale', team: 'noc', dashboard: '/d/cdnf-pop-fleet', wait: 14 },
    { name: 'cert-expiry', alert: 'CDNFoundryCertificateExpiry', team: 'noc', dashboard: '/d/cdnf-tls-posture', wait: 12 },
    { name: 'purge-stuck', alert: 'PurgePropagationSlow', team: 'platform', dashboard: '/d/cdnf-propagation', wait: 10 },
    { name: 'telemetry-drop', alert: 'TelemetryEventsDropped', team: 'platform', dashboard: '/d/cdnf-telemetry-pipeline', wait: 8 },
];

async function start(request: import('@playwright/test').APIRequestContext, scenario: string, minutes = 40) {
    const response = await request.post(`${exporter}/scenarios`, { data: { scenario, minutes, start: Date.now() / 1000 - 60 } });
    expect(response.ok(), `start ${scenario}`).toBeTruthy();
}

async function firing(request: import('@playwright/test').APIRequestContext, alert: string) {
    const response = await request.get(`${alertmanager}/api/v2/alerts?filter=alertname%3D%22${alert}%22&active=true`);
    return (await response.json()) as { labels: Record<string, string>; receivers: { name: string }[] }[];
}

test.describe.configure({ mode: 'serial' });

for (const scenario of scenarios) {
    test(`${scenario.name} fires ${scenario.alert} for ${scenario.team} and shows it in Grafana`, async ({ page, request }) => {
        test.setTimeout((scenario.wait + 6) * 60_000);
        await start(request, 'normal');
        await start(request, scenario.name);
        let alerts: Awaited<ReturnType<typeof firing>> = [];
        const deadline = Date.now() + scenario.wait * 60_000;
        while (Date.now() < deadline) {
            alerts = await firing(request, scenario.alert);
            if (alerts.length) break;
            await page.waitForTimeout(15_000);
        }
        expect(alerts.length, `${scenario.alert} fired within ${scenario.wait} minutes`).toBeGreaterThan(0);
        expect(alerts[0].labels.team).toBe(scenario.team);
        expect(alerts[0].receivers.map((receiver) => receiver.name)).toContain(scenario.team);

        await signIn(page, adminUser, adminPassword);
        await page.goto(`/alerting/list?view=list&search=${scenario.alert}`);
        await expect(page.getByText(scenario.alert).first()).toBeVisible({ timeout: 60_000 });

        await page.goto(`${scenario.dashboard}?orgId=1&from=now-30m&to=now`);
        await waitForPanels(page);
        await page.goto('/d/cdnf-now?orgId=1&from=now-30m&to=now');
        await waitForPanels(page);
        await expect(page.locator('section').filter({ hasText: 'Firing alerts' }).first()).toContainText(scenario.alert, { timeout: 60_000 });
        await start(request, 'normal');
    });
}
