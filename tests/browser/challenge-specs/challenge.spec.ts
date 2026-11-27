import { expect, test, type Page } from '@playwright/test';
import { expectAccessible } from '../specs/helpers';

// The browser check a visitor sees while a site is under attack mode or a challenge rule matches. The cells are real OpenResty cells:
// base is 16 bits, max is the default cap of 17 bits.
const base = 'http://localhost:18090';
const max = 'http://localhost:18091';

/** Holds the answer to the challenge, so the page stays in the state the visitor sees while the work is being done. */
async function holdAnswer(page: Page): Promise<void> {
    await page.route('**/.cdnf/challenge', () => undefined);
}

test('the check page says what is happening and fits every width', async ({ page }) => {
    await holdAnswer(page);
    await page.goto(`${base}/`);
    await expect(page.getByRole('heading', { name: 'Checking your browser' })).toBeVisible();
    await expect(page.getByRole('status')).toHaveText('This takes a few seconds. The page continues on its own.');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('checking.png');
});

test('the page solves the check on its own and lands on the site with a token cookie it cannot read', async ({ page, context }) => {
    await page.goto(`${base}/`);
    await expect(page.getByRole('heading', { name: 'Origin reached' })).toBeVisible();
    const [cookie] = (await context.cookies()).filter((item) => item.name === '_chk');
    expect(cookie.httpOnly).toBe(true);
    expect(cookie.sameSite).toBe('Lax');
    expect(await page.evaluate(() => document.cookie)).not.toContain('_chk');
});

test('a refused answer is said plainly and the page stays usable', async ({ page }) => {
    await page.route('**/.cdnf/challenge', (route) => route.fulfill({ status: 403, body: '' }));
    await page.goto(`${base}/`);
    await expect(page.getByRole('status')).toHaveText('Verification failed. Reload the page to try again.');
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('refused.png');
});

test('a site that cannot be reached says so instead of waiting forever', async ({ page }) => {
    await page.route('**/.cdnf/challenge', (route) => route.abort());
    await page.goto(`${base}/`);
    await expect(page.getByRole('status')).toHaveText('The check could not reach the site. Reload the page to try again.');
});

test('without JavaScript the page says why it needs it and what to do', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto(`${base}/`, { waitUntil: 'domcontentloaded' });
    // Text inside noscript is not an element Playwright's text search reaches, so the rendered text (innerText) of the page is read instead.
    await expect(page.locator('body')).toContainText('needs JavaScript to confirm that a person is visiting', { useInnerText: true });
    await expect(page.locator('body')).toContainText('Turn JavaScript on and reload, or contact the site owner.', { useInnerText: true });
    await expect(page.locator('body')).not.toContainText('This takes a few seconds', { useInnerText: true });
    // The accessibility scan needs JavaScript, which this page is without; the page is one heading and one paragraph of plain text.
    await expect(page.getByRole('heading', { name: 'Checking your browser' })).toBeVisible();
    await context.close();
});

test('a browser that cannot start workers still solves the check on its own thread', async ({ page }) => {
    await page.addInitScript(() => { Object.defineProperty(window, 'Worker', { value: undefined }); });
    await page.goto(`${base}/`);
    await expect(page.getByRole('heading', { name: 'Origin reached' })).toBeVisible({ timeout: 30_000 });
});

test('a visitor who opens the site in two tabs at once gets through in both', async ({ browser }) => {
    const context = await browser.newContext();
    const tabs = await Promise.all([context.newPage(), context.newPage()]);
    await Promise.all(tabs.map((tab) => tab.goto(`${base}/`)));
    for (const tab of tabs) await expect(tab.getByRole('heading', { name: 'Origin reached' })).toBeVisible({ timeout: 30_000 });
    await context.close();
});

// Solve time at the highest default difficulty. The slowest device of the matrix is one worker (a phone with one or two cores) on a
// CPU six times slower than this host's. Chromium's CPU throttle slows the page's own thread but not a worker, so the slow device is
// measured two ways: end to end with one worker, and by the hash rate of the solver on a throttled thread, from which the 95th
// percentile of the search follows (the number of attempts is geometric, so p95 is -ln(0.05) = 3.0 times the mean).
test.describe('solve time at maximum difficulty', () => {
    test.beforeEach(({}, testInfo) => {
        test.skip(testInfo.project.name !== 'light-desktop', 'a timing measurement, run once');
    });

    test('one worker solves 17 bits in 3 seconds or less at the 95th percentile', async ({ browser }) => {
        const times: number[] = [];
        for (let run = 0; run < 15; run++) {
            const context = await browser.newContext();
            await context.addInitScript(() => Object.defineProperty(navigator, 'hardwareConcurrency', { value: 2 }));
            const page = await context.newPage();
            const started = Date.now();
            await page.goto(`${max}/`);
            await expect(page.getByRole('heading', { name: 'Origin reached' })).toBeVisible({ timeout: 30_000 });
            times.push((Date.now() - started) / 1000);
            await context.close();
            // The cell issues a limited number of seeds a minute to one client.
            
        }
        times.sort((a, b) => a - b);
        const p95 = times[Math.ceil(times.length * 0.95) - 1];
        console.log(`one worker, 17 bits, 15 solves: p95 ${p95.toFixed(2)} s, median ${times[7].toFixed(2)} s, max ${times[14].toFixed(2)} s`);
        expect(p95).toBeLessThanOrEqual(3);
    });

    test('a throttled thread at six times slower still solves the highest difficulty in 3 seconds at p95', async ({ page, context }) => {
        await page.goto('about:blank');
        const solver = await (await import('node:fs/promises')).readFile(new URL('../../../docker/openresty/challenge_solver.js', import.meta.url), 'utf8');
        await page.addScriptTag({ content: solver });
        const cdp = await context.newCDPSession(page);
        await cdp.send('Emulation.setCPUThrottlingRate', { rate: 6 });
        const perSecond = await page.evaluate(() => {
            const seed = 'k1.801.1700000000.17.aabbccddeeff.' + 'f'.repeat(64);
            const started = performance.now();
            (window as unknown as { cdnfSolver: { search: (...a: unknown[]) => number } }).cdnfSolver.search(seed, 40, 0, 1, 300000);
            return 300000 / ((performance.now() - started) / 1000);
        });
        const p95 = (3 * 2 ** 17) / perSecond;
        console.log(`6x throttled thread: ${Math.round(perSecond)} hashes per second, p95 at 17 bits ${p95.toFixed(2)} s`);
        expect(p95).toBeLessThanOrEqual(3);
    });
});
