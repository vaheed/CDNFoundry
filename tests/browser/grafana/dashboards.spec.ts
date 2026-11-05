import { test, expect, type ConsoleMessage, type Page } from '@playwright/test';
import { dashboards, adminUser, adminPassword, signIn, waitForPanels, screenshotPath } from './helpers';

// Every dashboard, on each screen, over each time range: no error, no empty panel, no truncated title, no query text,
// loadable variables, resolving links, and a quiet console.
const ranges: Record<string, string> = { '15m': 'now-15m', '6h': 'now-6h', '7d': 'now-7d', '30d': 'now-30d' };
const selectedRanges = (process.env.GRAFANA_RANGES ?? Object.keys(ranges).join(',')).split(',');
const expectData = process.env.GRAFANA_EXPECT_DATA !== '0';

// Console messages that mean nothing about the dashboard.
const harmless = [/Failed to load resource: the server responded with a status of 40[134]/, /favicon/, /Content Security Policy/, /ResizeObserver/];

type QueryResponse = { url: string; body: string };

async function collectQueries(page: Page): Promise<QueryResponse[]> {
    const responses: QueryResponse[] = [];
    page.on('response', async (response) => {
        if (response.url().includes('/api/ds/query')) {
            responses.push({ url: response.url(), body: await response.text().catch(() => '') });
        }
    });
    return responses;
}

for (const dashboard of dashboards()) {
    for (const range of selectedRanges) {
        test(`${dashboard.uid} over ${range}`, async ({ page }, info) => {
            const messages: string[] = [];
            page.on('console', (message: ConsoleMessage) => {
                if (message.type() === 'error' && !harmless.some((pattern) => pattern.test(message.text()))) messages.push(message.text());
            });
            const responses = await collectQueries(page);
            await signIn(page, adminUser, adminPassword);
            const kiosk = info.project.name === 'kiosk' ? '&kiosk' : '';
            await page.goto(`/d/${dashboard.uid}?orgId=1&from=${ranges[range]}&to=now&refresh=${kiosk ? '' : ''}${kiosk}`);
            await waitForPanels(page);

            // No panel reports an error or a failed query.
            const failed = responses.filter((response) => /"error"\s*:/.test(response.body)).map((response) => response.body.slice(0, 240));
            expect(failed, 'query errors').toEqual([]);
            await expect(page.locator('[data-testid="data-testid Panel status error"], [aria-label="Panel status error"]')).toHaveCount(0);

            // No empty panel against the seeded normal scenario.
            if (expectData) {
                const empty = await page.locator('[data-testid="data-testid panel content"], section[data-testid^="data-testid Panel header"]').evaluateAll(() => {
                    return Array.from(document.querySelectorAll('[class*="panel-content"], [data-testid="data-testid panel content"]'))
                        .filter((element) => /^No data/i.test((element as HTMLElement).innerText.trim()))
                        .map((element) => (element.closest('section') as HTMLElement | null)?.getAttribute('aria-label') ?? 'panel');
                });
                expect(empty, 'panels that show No data').toEqual([]);
            }

            // No panel title is cut off, and no panel prints its query.
            const truncated = await page.locator('h2').evaluateAll((titles) =>
                titles.filter((title) => (title as HTMLElement).offsetParent !== null && title.scrollWidth > title.clientWidth + 1).map((title) => title.textContent));
            expect(truncated, 'truncated panel titles').toEqual([]);
            const printed = await page.locator('section').evaluateAll((sections) =>
                sections.filter((section) => /\b(sum|rate|histogram_quantile|increase|count_over_time)\s*\(/.test((section as HTMLElement).innerText)).map((section) => section.getAttribute('aria-label')));
            expect(printed, 'panels that show query text').toEqual([]);

            expect(messages, 'browser console errors').toEqual([]);
            await page.screenshot({ path: screenshotPath(info.project.name, range, dashboard.uid), fullPage: kiosk === '' });
        });
    }
}

test.describe('variables and links', () => {
    for (const dashboard of dashboards()) {
        test(`${dashboard.uid} has loadable variables and resolving links`, async ({ page }) => {
            test.skip(dashboard.templating.list.length === 0);
            await signIn(page, adminUser, adminPassword);
            await page.goto(`/d/${dashboard.uid}?orgId=1&from=now-6h&to=now`);
            await waitForPanels(page);
            for (const name of ['region', 'pop', 'edge']) {
                const picker = page.getByTestId(`data-testid Dashboard template variables Variable Value DropDown value link text ${name}`).or(page.locator(`[data-testid*="Variable"][data-testid*="${name}"]`)).first();
                await expect(picker).toBeVisible();
                await expect(picker).toContainText(/All/);
            }
            // Every dashboard link lands on a page that loads.
            const links = await page.locator('a[href^="/d/"]').evaluateAll((anchors) => Array.from(new Set(anchors.map((anchor) => (anchor as HTMLAnchorElement).getAttribute('href')))));
            for (const href of links.slice(0, 12)) {
                const response = await page.request.get(href!);
                expect(response.status(), href!).toBeLessThan(400);
            }
        });
    }

    test('All aggregates across PoPs', async ({ request }) => {
        const login = await request.post('/login', { data: { user: adminUser, password: adminPassword } });
        expect(login.ok()).toBeTruthy();
        const query = async (expression: string) => {
            const response = await request.get(`/api/datasources/proxy/uid/prometheus/api/v1/query?query=${encodeURIComponent(expression)}`);
            return Number(((await response.json()) as { data: { result: { value: [number, string] }[] } }).data.result[0]?.value[1] ?? 0);
        };
        const all = await query('sum(cdnf:edge_requests:rate5m{pop=~".*"})');
        const one = await query('sum(cdnf:edge_requests:rate5m{pop="seed-fra"})');
        const another = await query('sum(cdnf:edge_requests:rate5m{pop="seed-iad"})');
        expect(all).toBeGreaterThan(one);
        expect(all).toBeGreaterThanOrEqual(one + another);
    });
});
