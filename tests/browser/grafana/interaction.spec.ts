import { test, expect } from '@playwright/test';
import { adminUser, adminPassword, signIn, waitForPanels } from './helpers';

test.describe('interaction', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, adminUser, adminPassword);
    });

    test('a panel opens Explore with its query', async ({ page }) => {
        await page.goto('/d/cdnf-global-traffic?orgId=1&from=now-6h&to=now');
        await waitForPanels(page);
        const panel = page.locator('section').filter({ hasText: 'Requests by region' }).first();
        await panel.hover();
        // Grafana's panel shortcut for Explore is p then x.
        await page.keyboard.press('p');
        await page.keyboard.press('x');
        await expect(page).toHaveURL(/\/explore/);
        await expect(page.getByTestId('data-testid Query field').or(page.locator('.monaco-editor, textarea')).first()).toBeVisible({ timeout: 30_000 });
    });

    test('live tail streams new log lines in Explore', async ({ page }) => {
        const panes = JSON.stringify({ a: { datasource: 'loki', queries: [{ refId: 'A', expr: '{service=~".+"}', queryType: 'range' }], range: { from: 'now-1h', to: 'now' } } });
        await page.goto(`/explore?schemaVersion=1&panes=${encodeURIComponent(panes)}&orgId=1`);
        // The button reads Live; its accessible name is "Start live stream your logs".
        await page.getByTestId('data-testid explore-toolbar-live-button').click();
        await expect(page.getByText(/sample|info|error/i).first()).toBeVisible({ timeout: 60_000 });
        await page.getByRole('button', { name: /Stop|Pause/i }).first().click();
    });

    test('the NOC wall drills down to PoP detail', async ({ page }) => {
        await page.goto('/d/cdnf-noc-wall?orgId=1&from=now-1h&to=now');
        await waitForPanels(page);
        // Grafana draws a panel only when it scrolls into view; on a phone the PoP panels are below the first screen.
        const link = page.locator('a[href*="/d/cdnf-pop-detail"]').first();
        for (let step = 0; step < 20 && (await link.count()) === 0; step++) {
            await page.mouse.wheel(0, 800);
            await page.waitForTimeout(400);
        }
        await link.scrollIntoViewIfNeeded();
        await expect(link).toBeVisible();
        await link.click();
        await expect(page).toHaveURL(/\/d\/cdnf-pop-detail/);
        await waitForPanels(page);
        await expect(page.getByText('PoP detail').first()).toBeVisible();
    });

    test('the Now dashboard opens a firing alert on its dashboard at its start time', async ({ page }) => {
        await page.goto('/d/cdnf-now?orgId=1&from=now-1h&to=now');
        await waitForPanels(page);
        const alerts = page.locator('section').filter({ hasText: 'Firing alerts' }).first();
        const link = alerts.locator('a[href*="/d/"]').first();
        test.skip((await link.count()) === 0, 'no alert is firing, so there is nothing to open');
        const href = await link.getAttribute('href');
        expect(href).toMatch(/from=\d+/);
        await link.click();
        await expect(page).toHaveURL(/\/d\/[a-z0-9-]+/);
    });

    test('the NOC and SOC wall playlists exist', async ({ page }) => {
        await page.goto('/playlists');
        await expect(page.getByText('NOC wall')).toBeVisible();
        await expect(page.getByText('SOC wall')).toBeVisible();
    });

    test('a request ID in a log line opens the request trace', async ({ request }) => {
        await request.post('/login', { data: { user: adminUser, password: adminPassword } });
        const loki = await request.get('/api/datasources/uid/loki');
        const fields = ((await loki.json()) as { jsonData: { derivedFields: { name: string; url: string }[] } }).jsonData.derivedFields;
        const byName = Object.fromEntries(fields.map((field) => [field.name, field.url]));
        expect(byName.request_id).toContain('/d/cdnf-request-trace');
        expect(byName.domain_id).toContain('/d/cdnf-domain-overview');
        expect(byName.pop).toContain('/d/cdnf-pop-detail');
        expect(byName.edge).toContain('/d/cdnf-pop-detail');
    });
});
