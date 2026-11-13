import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, settle, signIn, userPassword } from './helpers';

// The Security events tab on a domain page: mitigated requests with their rule, the filters, and the two shortcuts.

async function openEvents(page: Page): Promise<void> {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/domains');
    await settle(page);
    await page.locator('table tbody tr a').first().click();
    await settle(page);
    await page.getByRole('tab', { name: /Security events/ }).click();
    await settle(page);
}

test('the list shows each mitigated request with its rule, action, score, and path', async ({ page }) => {
    await openEvents(page);
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(4);
    await expect(rows.nth(0)).toContainText('Block the admin area for visitors');
    await expect(rows.nth(0)).toContainText('Blocked');
    await expect(rows.nth(1)).toContainText('Managed WAF');
    await expect(rows.nth(1)).toContainText('Over the anomaly threshold');
    // A platform rule is named by its ruleset only, and a rule that was deleted says so.
    await expect(rows.nth(2)).toContainText('Platform ruleset: Known scanner paths');
    await expect(rows.nth(2)).not.toContainText('/wp-admin');
    await expect(rows.nth(3)).toContainText('A rule that no longer exists');
    await expect(rows.nth(3)).toContainText('Logged');
    // Long paths are cut, never widen the table, and the rows keep their actions.
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expect(rows.nth(0).getByRole('button', { name: 'Create rule' })).toBeVisible();
    await expectAccessible(page);
});

test('filters narrow the list and every filter input has an example', async ({ page }) => {
    await openEvents(page);
    await page.getByRole('button', { name: /Filters?/ }).first().click();
    const panel = page.locator('.fi-ta-filters-dropdown:visible, .fi-dropdown-panel:visible').first();
    const inputs = panel.locator('input[type=text]:visible, input[type=number]:visible');
    const count = await inputs.count();
    expect(count).toBeGreaterThanOrEqual(6);
    for (let index = 0; index < count; index += 1) {
        const placeholder = (await inputs.nth(index).getAttribute('placeholder')) ?? '';
        expect(placeholder, `filter input ${index} needs an example placeholder`).not.toEqual('');
    }
    expect(await panel.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(2);
    await expectAccessible(page);
    await panel.getByPlaceholder('DE').fill('FR');
    await settle(page);
    await expect(page.locator('table tbody tr')).toHaveCount(1);
    await expect(page.locator('table tbody tr').first()).toContainText('Platform ruleset');
});

test('create rule starts from the request, and an exception is offered only where a domain can pass the rule', async ({ page }) => {
    await openEvents(page);
    const rows = page.locator('table tbody tr');
    await rows.nth(0).getByRole('button', { name: 'Create rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByRole('heading', { name: 'Create a rule from this request' })).toBeVisible();
    await expect(sheet.getByRole('textbox', { name: 'Value*' }).first()).toHaveValue('/admin/login');
    await expect(sheet.getByRole('heading', { name: 'Path equals /admin/login' })).toBeVisible();
    await expect(sheet.getByTestId('rule-reads-as')).toContainText('Path equals /admin/login');
    await expectAccessible(page);
    await sheet.getByRole('button', { name: 'Cancel' }).click();
    // The request stopped by a platform rule has no exception: platform rules run before the domain's own.
    await expect(rows.nth(2).getByRole('button', { name: 'Create exception' })).toHaveCount(0);
    await expect(rows.nth(1).getByRole('button', { name: 'Create exception' })).toBeVisible();
});
