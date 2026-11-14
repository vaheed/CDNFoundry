import { expect, test, type Page } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// Platform rulesets in the admin panel: the list, the create form, and the rules of a ruleset.

async function openAdmin(page: Page, path: string): Promise<void> {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto(path);
    await settle(page);
}

test('the list shows each ruleset with its reach, rule count, and state', async ({ page }) => {
    await openAdmin(page, '/admin/platform-rulesets');
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(2);
    const scanner = rows.filter({ hasText: 'Known scanner paths and credential-stuffing endpoints' });
    await expect(scanner).toContainText('1 domain');
    await expect(scanner.getByText('Enabled', { exact: true })).toBeVisible();
    const disabled = rows.filter({ hasText: 'Disabled test ruleset' });
    await expect(disabled).toContainText('All domains');
    await expect(disabled.getByText('Disabled', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expectAccessible(page);
});

test('the create form has an example in every input and a tooltip on the complex items', async ({ page }) => {
    await openAdmin(page, '/admin/platform-rulesets/create');
    const inputs = page.locator('main input[type=text]:visible');
    const count = await inputs.count();
    expect(count).toBeGreaterThan(0);
    for (let index = 0; index < count; index += 1) {
        const input = inputs.nth(index);
        const placeholder = (await input.getAttribute('placeholder')) ?? '';
        expect(placeholder, `input ${await input.getAttribute('id')} needs an example placeholder`).not.toEqual('');
    }
    expect(await page.locator('main [x-tooltip]').count()).toBeGreaterThanOrEqual(3);
    // Choosing every domain hides the domain list.
    await expect(page.locator('.fi-select-input-placeholder', { hasText: 'for example example.com' })).toBeVisible();
    await page.getByText('Apply to all domains').click();
    await settle(page);
    await expect(page.locator('.fi-select-input-placeholder', { hasText: 'for example example.com' })).toHaveCount(0);
    await expectAccessible(page);
});

test('a ruleset page lists its rules in words, including the rate limit', async ({ page }) => {
    await openAdmin(page, '/admin/platform-rulesets');
    await page.locator('table tbody tr').filter({ hasText: 'Known scanner paths' }).getByRole('link', { name: 'Edit' }).click();
    await settle(page);
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(2);
    await expect(rows.first()).toContainText('Block admin page probes');
    await expect(rows.first()).toContainText('Path starts with /wp-admin');
    await expect(rows.nth(1)).toContainText('Limit: 20 requests per 1 minute per client ip and path, then block for 5 min.');
    await expectAccessible(page);
    await page.getByRole('button', { name: 'Add rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByRole('combobox', { name: /^Field/ })).toBeVisible();
    await sheet.getByText('Only above a request rate').click();
    await settle(page);
    await expect(sheet.getByRole('spinbutton', { name: /^Requests/ })).toHaveAttribute('placeholder', '100');
    await expectAccessible(page);
});

test('a domain owner sees which platform rulesets apply but not their rules', async ({ page }) => {
    await signIn(page, 'app', 'user@browser.test', (await import('./helpers')).userPassword);
    await page.goto('/app/domains');
    await settle(page);
    await page.locator('table tbody tr a').first().click();
    await settle(page);
    await page.getByRole('tab', { name: /Custom rules/ }).click();
    await settle(page);
    await expect(page.getByText('Applied first by your provider:')).toBeVisible();
    await expect(page.getByText('Known scanner paths and credential-stuffing endpoints')).toBeVisible();
    await expect(page.getByText('/wp-admin')).toHaveCount(0);
    await expectAccessible(page);
});
