import { expect, test } from '@playwright/test';
import { expectAccessible, settle, signIn, userPassword } from './helpers';

/*
 * The domain panel's landing page and analytics header: traffic for the last 24 hours, what needs the user's action, and
 * every assigned domain. The ClickHouse stand-in inside the disposable container answers the analytics store.
 */

test('the domain dashboard shows traffic, what needs attention, and every domain', async ({ page }) => {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app');
    await settle(page);
    await expect(page.getByTestId('app-overview')).toBeVisible();
    await expect(page.getByTestId('app-traffic')).toBeVisible();
    await expect(page.getByTestId('app-domains')).toBeVisible();
    await expect(page.getByTestId('app-domains').getByText('alpha-browser.example')).toBeVisible();
    // Each attention item names its domain and links to the page that fixes it.
    if (await page.getByTestId('app-attention').count()) {
        const link = page.getByTestId('app-attention').locator('a').first();
        await expect(link).toHaveAttribute('href', /\/app\/domains\//);
    }
    await expectAccessible(page);
    // The domain list grows and shrinks as other specs add domains, so only the top of the page is compared.
    await expect(page).toHaveScreenshot('domain-dashboard.png', { clip: { x: 0, y: 0, width: page.viewportSize()!.width, height: 560 }, mask: [page.getByTestId('app-traffic')] });
});

test('the analytics scope and the provisional note are on separate lines', async ({ page }) => {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/analytics');
    await settle(page);
    const scope = page.getByTestId('analytics-scope');
    await expect(scope).toBeVisible();
    // Each sentence is its own block, so the two never share a line.
    const tops = await scope.locator('span.block').evaluateAll((nodes) => nodes.map((node) => Math.round((node as HTMLElement).getBoundingClientRect().top)));
    expect(tops.length).toBeGreaterThan(0);
    expect(new Set(tops).size).toBe(tops.length);
    await expectAccessible(page);
    await expect(page.getByTestId('analytics-summary')).toHaveScreenshot('domain-analytics-summary.png', { mask: [page.getByTestId('analytics-summary').locator('.cdn-kpi-strip, [data-testid=kpi-strip]')] });
});

test('attention rows wrap instead of running past the card', async ({ page }) => {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app');
    await settle(page);
    const overflow = await page.getByTestId('app-attention').evaluate((card) => {
        const box = card.getBoundingClientRect();
        return Array.from(card.querySelectorAll('li a')).filter((link) => link.getBoundingClientRect().right > box.right + 1).length;
    });
    expect(overflow, 'attention links wider than their card').toBe(0);
});
