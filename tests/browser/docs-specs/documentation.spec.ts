import { expect, test, type Page } from '@playwright/test';
import { expectAccessible } from '../specs/helpers';

async function expectNoOverflow(page: Page): Promise<void> {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
}

test('a newcomer can find request rules and use the panel and API instructions', async ({ page }, info) => {
    await page.goto('guides/request-rules');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Redirects, rewrites, headers, and cache rules');
    const image = page.locator('.cdnf-shot img');
    await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
    await expect(image).toHaveAttribute('src', new RegExp(`/screenshots/${info.project.name.startsWith('dark') ? 'dark' : 'light'}/app-request-rules.png$`));
    const api = page.getByRole('tab', { name: 'API', exact: true });
    await page.getByRole('tab', { name: 'Domain panel', exact: true }).focus();
    await page.keyboard.press('ArrowRight');
    await expect(api).toBeFocused();
    await expect(page.getByRole('tabpanel', { name: 'API', exact: true })).toContainText('/request-rules');
    await api.press('ArrowLeft');
    await expect(page.getByRole('tab', { name: 'Domain panel', exact: true })).toBeFocused();
    await expect(page.getByRole('tab', { name: 'Domain panel', exact: true })).toHaveAttribute('aria-selected', 'true');
    await page.evaluate(() => window.scrollTo(0, 0));
    await expectNoOverflow(page);
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('documentation-request-rules.png');
});

test('architecture diagrams render and their larger view supports the keyboard', async ({ page }) => {
    await page.goto('architecture/data-flows');
    await expect(page.locator('.cdnf-diagram')).toHaveCount(5);
    await expect(page.locator('.cdnf-diagram svg')).toHaveCount(5, { timeout: 30_000 });
    await expect(page.locator('.cdnf-diagram-failed')).toHaveCount(0);
    const first = page.getByRole('button', { name: 'View larger', exact: true }).first();
    await first.click();
    await expect(page.getByRole('button', { name: 'Close large view' })).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Close large view' })).toHaveCount(0);
    await expectNoOverflow(page);
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('documentation-data-flows.png');
});

test('home navigation and local search expose the feature guide', async ({ page }) => {
    await page.goto('./');
    await expect(page.getByRole('link', { name: 'Choose your starting point', exact: true })).toBeVisible();
    await page.getByRole('button', { name: /Search/ }).first().click();
    const search = page.locator('.VPLocalSearchBox');
    await expect(search).toBeVisible();
    await search.locator('input').fill('redirects rewrites');
    await expect(search.locator('a[href*="/guides/request-rules"]').first()).toBeVisible({ timeout: 20_000 });
    await search.locator('a[href*="/guides/request-rules"]').first().click();
    await expect(page).toHaveURL(/\/guides\/request-rules(?:#.*)?$/);
    await expectNoOverflow(page);
});
