import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, settle, signIn, userPassword } from './helpers';

// The Rules tab on a domain page: redirects read in words, the redirect sheet with its examples and tooltips, and the presets.

async function openRules(page: Page): Promise<void> {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/domains');
    await settle(page);
    await page.locator('table tbody tr a').first().click();
    await settle(page);
    await page.getByRole('tab', { name: /^Rules/ }).click();
    await settle(page);
}

test('the redirect list reads each rule and its target in words, in order', async ({ page }) => {
    await openRules(page);
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(4);
    await expect(rows.first()).toContainText('Path starts with /docs/v1');
    await expect(rows.first()).toContainText('308 from /docs/v1 to /guides, keeping the query string');
    await expect(rows.nth(1)).toContainText('Client IP is one of 2001:db8:abcd::/48, 203.0.113.0/24');
    await expect(rows.nth(1)).toContainText('302 to https://xn--bcher-kva.example/sale?campaign=winter, without the query string');
    await expect(rows.nth(2).getByText('Disabled', { exact: true })).toBeVisible();
    await expect(rows.nth(3)).toContainText('to the same path on');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expect(page.locator('.fi-ta')).toHaveScreenshot('request-rules-redirects.png');
    await expectAccessible(page);
});

test('the redirect sheet shows only the fields of the chosen target, each with an example', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Add redirect' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByRole('heading', { name: 'Add redirect' })).toBeVisible();
    await expect(sheet.getByRole('textbox', { name: /^URL/ })).toHaveAttribute('placeholder', 'https://www.example.com/new');
    await expect(sheet.getByRole('textbox', { name: /^Host/ })).toHaveCount(0);

    await sheet.getByRole('combobox', { name: /^Redirect to/ }).selectOption('prefix');
    await settle(page);
    await expect(sheet.getByRole('textbox', { name: /^URL/ })).toHaveCount(0);
    await expect(sheet.getByRole('textbox', { name: /^Old prefix/ })).toHaveAttribute('placeholder', '/blog');
    await expect(sheet.getByRole('textbox', { name: /^New prefix/ })).toHaveAttribute('placeholder', '/news');
    await sheet.getByRole('textbox', { name: /^Old prefix/ }).fill('/blog');
    await sheet.getByRole('textbox', { name: /^New prefix/ }).fill('/news');
    await sheet.getByRole('textbox', { name: /^New prefix/ }).press('Tab');
    await settle(page);
    await expect(sheet.getByTestId('request-rule-reads-as')).toContainText('Path starts with /blog, then redirect 301 from /blog to /news, keeping the query string.');

    const inputs = sheet.locator('input[type=text]:visible, input[type=number]:visible');
    for (let index = 0; index < (await inputs.count()); index += 1) {
        expect(await inputs.nth(index).getAttribute('placeholder'), `input ${await inputs.nth(index).getAttribute('id')} needs an example placeholder`).not.toEqual('');
    }
    expect(await sheet.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(3);
    await expect(sheet).toHaveScreenshot('request-rules-sheet.png');
    await expectAccessible(page);
});

test('a redirect added in the sheet appears in the list', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Add redirect' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await sheet.getByRole('combobox', { name: /^Field/ }).selectOption('path');
    await settle(page);
    await sheet.getByRole('textbox', { name: 'Value*' }).fill('/browser-old');
    await sheet.getByRole('textbox', { name: /^URL/ }).fill('/browser-new');
    await sheet.getByRole('button', { name: 'Add redirect' }).click();
    await settle(page);
    await expect(page.locator('table tbody tr', { hasText: 'Path equals /browser-old' })).toContainText('301 to /browser-new, keeping the query string');
    const row = page.locator('table tbody tr', { hasText: 'Path equals /browser-old' });
    await row.getByRole('button', { name: 'Delete' }).click();
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'Delete' }).click();
    await settle(page);
    await expect(page.locator('table tbody tr', { hasText: 'Path equals /browser-old' })).toHaveCount(0);
});

test('the presets explain what they write and ask only for what they need', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Presets' }).click();
    await expect(page.getByRole('menuitem', { name: 'Remove the www redirect' })).toBeVisible();
    await expect(page.getByRole('menuitem', { name: 'Remove the path moves' })).toHaveCount(0);
    await page.getByRole('menuitem', { name: 'Move paths to new prefixes' }).click();
    const dialog = page.locator('.fi-modal-window:visible');
    await expect(dialog).toContainText('Each old prefix redirects to its new one');
    await expect(dialog.getByRole('textbox', { name: /^Old prefix/ })).toHaveAttribute('placeholder', '/blog');
    await expect(dialog.getByRole('textbox', { name: /^New prefix/ })).toHaveAttribute('placeholder', '/news');
    await expectAccessible(page);
});
