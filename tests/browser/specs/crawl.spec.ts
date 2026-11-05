import { expect, test, type Locator, type Page } from '@playwright/test';
import { adminPassword, settle, signIn, userPassword } from './helpers';

/*
 * Crawls every link in each panel's sidebar and the first record of every list, then opens every action on each page:
 * header actions, the items inside action groups, the first table row's actions, and every tab. An action that opens a
 * dialog or sheet must show a heading and close with Escape; nothing is ever submitted. Any server error (HTTP 5xx,
 * including Livewire updates) or uncaught JavaScript error fails the run.
 * Behavior does not depend on theme or width, so the crawl runs in the light desktop project only; every-screen.spec.ts
 * covers accessibility in all four projects.
 */

type Problem = string;

function watch(page: Page, problems: Problem[]): void {
    page.on('pageerror', (error) => problems.push(`${page.url()} script error: ${error.message}`));
    page.on('response', (response) => {
        if (response.status() >= 500) {
            problems.push(`${page.url()} ${response.request().method()} ${new URL(response.url()).pathname} returned ${response.status()}`);
        }
    });
    page.on('download', (download) => void download.cancel().catch(() => undefined));
}

async function sidebarLinks(page: Page, panel: string): Promise<string[]> {
    const hrefs = await page.locator('.fi-sidebar-nav a[href]').evaluateAll((links) => links.map((link) => (link as HTMLAnchorElement).href));
    return [...new Set(hrefs)]
        .filter((href) => new URL(href).origin === new URL(page.url()).origin)
        .map((href) => new URL(href).pathname + new URL(href).search)
        .filter((path) => path.startsWith(`/${panel}`) && !path.endsWith('/logout'));
}

async function openModal(page: Page): Promise<Locator | null> {
    const modal = page.locator('.fi-modal-window:visible').last();
    try {
        await modal.waitFor({ state: 'visible', timeout: 4_000 });
        return modal;
    } catch {
        return null;
    }
}

async function closeModal(page: Page, modal: Locator): Promise<void> {
    await page.keyboard.press('Escape');
    if (await modal.isVisible().catch(() => false)) {
        await page.locator('.fi-modal-window:visible .fi-modal-close-btn, .fi-modal-window:visible button:has-text("Cancel")').last().click({ timeout: 2_000 }).catch(() => undefined);
    }
    await expect(page.locator('.fi-modal-window:visible')).toHaveCount(0, { timeout: 5_000 });
}

/** Clicks one action and checks the result: a dialog that opens and closes, a navigation, or an immediate run without errors. */
async function tryAction(page: Page, target: Locator, label: string, origin: string, problems: Problem[]): Promise<void> {
    // A disabled action (for example deleting your own account) is correct behavior, not a failure: it is not clicked.
    if (await target.isDisabled().catch(() => false) || (await target.getAttribute('aria-disabled')) === 'true') {
        return;
    }
    await target.click({ timeout: 5_000 });
    const modal = await openModal(page);
    if (modal) {
        const heading = modal.locator('.fi-modal-heading');
        if ((await heading.count()) === 0 || (await heading.first().innerText()).trim() === '') {
            problems.push(`${origin}: dialog for "${label}" has no heading`);
        }
        await closeModal(page, modal);
    }
    await page.waitForLoadState('networkidle');
    if (new URL(page.url()).pathname !== new URL(origin, page.url()).pathname) {
        await page.goto(origin);
        await settle(page);
    }
}

async function exerciseActions(page: Page, path: string, problems: Problem[]): Promise<void> {
    // Header actions shown directly.
    const header = page.locator('.fi-header .fi-ac > :is(button, a):visible, .fi-header .fi-ac .fi-btn:visible:not(.fi-dropdown-trigger *)');
    const headerLabels = (await header.allInnerTexts()).map((text) => text.trim()).filter(Boolean);
    for (const label of [...new Set(headerLabels)]) {
        const button = page.locator('.fi-header .fi-ac').getByRole('button', { name: label, exact: true }).first();
        if (!(await button.isVisible().catch(() => false))) continue;
        await tryAction(page, button, label, path, problems).catch((error) => problems.push(`${path}: header action "${label}" failed: ${String(error).split('\n')[0]}`));
    }

    // Action groups ("Domain actions", "More", "…"): open each group and try every item in it.
    const groups = page.locator('.fi-header .fi-dropdown-trigger:visible');
    for (let index = 0; index < (await groups.count()); index++) {
        await groups.nth(index).click();
        const items = page.locator('.fi-dropdown-panel:visible .fi-dropdown-list-item');
        const labels = (await items.allInnerTexts()).map((text) => text.trim()).filter(Boolean);
        await page.keyboard.press('Escape');
        for (const label of labels) {
            await groups.nth(index).click();
            const item = page.locator('.fi-dropdown-panel:visible .fi-dropdown-list-item', { hasText: label }).first();
            if (!(await item.isVisible().catch(() => false))) {
                await page.keyboard.press('Escape');
                continue;
            }
            await tryAction(page, item, label, path, problems).catch((error) => problems.push(`${path}: menu item "${label}" failed: ${String(error).split('\n')[0]}`));
        }
    }

    // First table row: its direct actions and the items of its row menu.
    const row = page.locator('.fi-ta-record, .fi-ta-row').first();
    if (await row.isVisible().catch(() => false)) {
        const rowButtons = row.locator('.fi-ta-actions :is(button, a):visible:not(.fi-dropdown-trigger *)');
        const names = await rowButtons.evaluateAll((buttons) => buttons.map((button) => button.getAttribute('aria-label') ?? button.textContent?.trim() ?? '').filter(Boolean));
        for (const name of [...new Set(names)]) {
            const button = page.locator('.fi-ta-record, .fi-ta-row').first().locator('.fi-ta-actions').getByRole('button', { name, exact: true }).first();
            if (!(await button.isVisible().catch(() => false))) continue;
            await tryAction(page, button, name, path, problems).catch((error) => problems.push(`${path}: row action "${name}" failed: ${String(error).split('\n')[0]}`));
        }
    }

    // Tabs (detail pages and relation managers).
    const tabs = page.locator('[role=tab]:visible, .fi-tabs-item:visible');
    const tabLabels = (await tabs.allInnerTexts()).map((text) => text.trim().split('\n')[0]).filter(Boolean);
    for (const label of [...new Set(tabLabels)]) {
        const tab = page.locator('[role=tab]:visible, .fi-tabs-item:visible', { hasText: label }).first();
        await tab.click({ timeout: 5_000 }).catch((error) => problems.push(`${path}: tab "${label}" failed: ${String(error).split('\n')[0]}`));
        await page.waitForLoadState('networkidle');
    }
}

async function crawl(page: Page, panel: 'admin' | 'app'): Promise<Problem[]> {
    const problems: Problem[] = [];
    watch(page, problems);
    await page.goto(`/${panel}`);
    await settle(page);
    const queue = await sidebarLinks(page, panel);
    expect(queue.length).toBeGreaterThan(0);
    const visited = new Set<string>();

    while (queue.length > 0) {
        const path = queue.shift() as string;
        if (visited.has(path)) continue;
        visited.add(path);
        const response = await page.goto(path);
        if ((response?.status() ?? 500) >= 400) {
            problems.push(`${path} returned ${response?.status()}`);
            continue;
        }
        await settle(page);
        await expect(page.locator('main, .fi-main').first()).toBeVisible();

        // The first record of a list leads to its detail or edit page, which is crawled too.
        const record = page.locator('.fi-ta-record a[href], .fi-ta-row a[href]').first();
        if (await record.isVisible().catch(() => false)) {
            const href = await record.getAttribute('href');
            if (href) {
                const next = new URL(href, page.url());
                if (next.origin === new URL(page.url()).origin && next.pathname.startsWith(`/${panel}`)) queue.push(next.pathname + next.search);
            }
        }

        await exerciseActions(page, path, problems);
    }

    return problems;
}

test.describe('crawl every link and action', () => {
    test.beforeEach(({}, info) => {
        test.skip(info.project.name !== 'light-desktop', 'the functional crawl runs once, in the light desktop project');
        test.setTimeout(30 * 60_000);
    });

    test('administrator panel', async ({ page }) => {
        await signIn(page, 'admin', 'admin@browser.test', adminPassword);
        expect(await crawl(page, 'admin')).toEqual([]);
    });

    test('domain panel', async ({ page }) => {
        await signIn(page, 'app', 'user@browser.test', userPassword);
        expect(await crawl(page, 'app')).toEqual([]);
    });

    test('public pages and their links', async ({ page }) => {
        const problems: Problem[] = [];
        watch(page, problems);
        for (const path of ['/', '/health']) {
            const response = await page.goto(path);
            expect(response?.status()).toBeLessThan(400);
            const links = await page.locator('a[href]').evaluateAll((anchors) => anchors.map((anchor) => (anchor as HTMLAnchorElement).href));
            for (const href of links.filter((link) => new URL(link).origin === new URL(page.url()).origin)) {
                const linked = await page.request.get(href);
                if (linked.status() >= 400) problems.push(`${path} links to ${href}, which returned ${linked.status()}`);
            }
        }
        expect(problems).toEqual([]);
    });
});
