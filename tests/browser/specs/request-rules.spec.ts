import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, settle, signIn, userPassword } from './helpers';

// The Rules tab on a domain page: each type's list read in words, its sheet with examples and tooltips, and its presets.

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
    const menu = await rows.first().getByTestId('request-rule-row-actions').boundingBox();
    expect(menu).not.toBeNull();
    expect(menu!.x + menu!.width).toBeLessThanOrEqual(page.viewportSize()!.width);
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
    await row.getByTestId('request-rule-row-actions').click();
    await page.getByRole('menuitem', { name: 'Delete', exact: true }).click();
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

async function openType(page: Page, tab: RegExp): Promise<void> {
    await openRules(page);
    await page.locator('.fi-resource-relation-manager').getByRole('button', { name: tab }).click();
    await settle(page);
}

test('one click switches the rule type, active indicator, create action, and presets together', async ({ page }) => {
    await openRules(page);
    const manager = page.locator('.fi-resource-relation-manager');
    for (const [tab, add, preset] of [
        [/^URL rewrites/, 'Add rewrite', 'Add a path prefix before the origin'],
        [/^Headers/, 'Add header rule', 'Add security headers'],
        [/^Cache/, 'Add cache rule', 'Cache static files longer'],
        [/^Redirects/, 'Add redirect', 'Redirect HTTP to HTTPS'],
        [/^Cache/, 'Add cache rule', 'Cache static files longer'],
        [/^URL rewrites/, 'Add rewrite', 'Add a path prefix before the origin'],
    ] as const) {
        const button = manager.locator('.fi-tabs').getByRole('button', { name: tab });
        await button.click();
        await expect(button).toHaveAttribute('aria-pressed', 'true');
        await expect(button).toHaveClass(/\bfi-active\b/);
        await expect(manager.locator('button[aria-pressed="true"]')).toHaveCount(1);
        await expect(manager.getByRole('button', { name: add, exact: true })).toBeVisible();
        await manager.getByRole('button', { name: 'Presets', exact: true }).click();
        await expect(page.getByRole('menuitem', { name: preset, exact: true })).toBeVisible();
        await page.keyboard.press('Escape');
    }
});

test('the rewrite list reads what the origin receives, and its presets ask for one prefix', async ({ page }) => {
    await openType(page, /^URL rewrites/);
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(3);
    await expect(rows.first()).toContainText('Hostname equals xn--bcher-kva.example');
    await expect(rows.first()).toContainText('Path to /index.php, query string to store=legacy&lang=de');
    await expect(rows.nth(1)).toContainText('Remove the query string');
    await expect(rows.nth(1).getByText('Disabled', { exact: true })).toBeVisible();
    await expect(rows.nth(2)).toContainText('Prefix /api to nothing');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expect(page.locator('.fi-ta')).toHaveScreenshot('request-rules-rewrites.png');
    await expectAccessible(page);

    await page.getByRole('button', { name: 'Presets' }).click();
    await expect(page.getByRole('menuitem', { name: 'Remove the prefix removal' })).toBeVisible();
    await page.getByRole('menuitem', { name: 'Add a path prefix before the origin' }).click();
    const dialog = page.locator('.fi-modal-window:visible');
    await expect(dialog).toContainText('/users becomes /app/users');
    await expect(dialog.getByRole('textbox', { name: /^Prefix/ })).toHaveAttribute('placeholder', '/app');
    await expectAccessible(page);
    await dialog.getByRole('button', { name: 'Cancel' }).click();

    await page.getByRole('button', { name: 'Add rewrite' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByRole('textbox', { name: /^Old prefix/ })).toHaveAttribute('placeholder', '/api');
    await sheet.getByRole('combobox', { name: /^Path/ }).selectOption('set');
    await settle(page);
    await expect(sheet.getByRole('textbox', { name: /^Old prefix/ })).toHaveCount(0);
    await expect(sheet.getByRole('textbox', { name: /^New path/ })).toHaveAttribute('placeholder', '/index.php');
    await sheet.getByRole('combobox', { name: /^Query string/ }).selectOption('set');
    await settle(page);
    await expect(sheet.getByRole('textbox', { name: /^New query string/ })).toHaveAttribute('placeholder', 'lang=en');
    await expectAccessible(page);
});

test('the header list reads each change, and the security header dialog names every header it sends', async ({ page }) => {
    await openType(page, /^Headers/);
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(3);
    await expect(rows.first()).toContainText('Client IP is one of 2001:db8:abcd::/48, 198.51.100.0/24');
    await expect(rows.first()).toContainText('Request: set x-client-type. Response: set cache-control');
    await expect(rows.nth(1)).toContainText('Security headers');
    await expect(rows.nth(2)).toContainText('Response: remove server, remove x-powered-by');
    await expect(page.locator('.fi-ta')).toHaveScreenshot('request-rules-headers.png');
    await expectAccessible(page);

    await page.getByRole('button', { name: 'Presets' }).click();
    await page.getByRole('menuitem', { name: 'Add security headers' }).click();
    const dialog = page.locator('.fi-modal-window:visible');
    await expect(dialog).toContainText('Strict-Transport-Security, X-Content-Type-Options, X-Frame-Options, Referrer-Policy, and Permissions-Policy');
    // The dialog opens on what the seeded preset saved.
    await expect(dialog.getByRole('combobox', { name: /^HTTPS only/ })).toHaveValue('31536000');
    await expect(dialog.getByRole('combobox', { name: /^Frames/ })).toHaveValue('SAMEORIGIN');
    expect(await dialog.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(2);
    await expectAccessible(page);
    await dialog.getByRole('button', { name: 'Cancel' }).click();

    await page.getByRole('button', { name: 'Add header rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await sheet.getByRole('button', { name: 'Add header' }).first().click();
    await settle(page);
    await expect(sheet.getByRole('textbox', { name: /^Header/ }).first()).toHaveAttribute('placeholder', 'X-Frame-Options');
    await expect(sheet.getByRole('textbox', { name: /^Value/ }).first()).toHaveAttribute('placeholder', 'SAMEORIGIN');
    await sheet.getByRole('textbox', { name: /^Header/ }).first().fill('X-Forwarded-For');
    await sheet.getByRole('textbox', { name: /^Value/ }).first().fill('198.51.100.7');
    await sheet.getByRole('button', { name: 'Add header rule' }).click();
    await settle(page);
    await expect(sheet).toContainText('x-forwarded-for is set by the edge and cannot be changed by a rule.');
    await expect(sheet).toHaveScreenshot('request-rules-header-sheet.png');
    await expectAccessible(page);
});

test('the cache list reads bypasses and lifetimes, and a bypass path without a slash is refused on the field', async ({ page }) => {
    await openType(page, /^Cache/);
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(5);
    await expect(rows.first()).toContainText('Bypass the cache');
    await expect(rows.nth(1)).toContainText('Edge TTL 7 d, ignore origin cache headers');
    await expect(rows.nth(1).getByText('Disabled', { exact: true })).toBeVisible();
    await expect(rows.nth(2)).toContainText('Cookie wordpress_logged_in');
    await expect(rows.nth(4)).toContainText('Edge TTL 30 d, browser TTL 7 d');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expect(page.locator('.fi-ta')).toHaveScreenshot('request-rules-caches.png');
    await expectAccessible(page);

    await page.getByRole('button', { name: 'Presets' }).click();
    await page.getByRole('menuitem', { name: 'Bypass the cache for admin and sign-in paths' }).click();
    const dialog = page.locator('.fi-modal-window:visible');
    await expect(dialog.getByText('/wp-admin', { exact: true })).toBeVisible();
    const tags = dialog.locator('.fi-fo-tags-input input');
    await tags.fill('account');
    await tags.press('Enter');
    await dialog.getByRole('button', { name: 'Save preset' }).click();
    await settle(page);
    await expect(dialog).toContainText('account does not start with /. A path prefix looks like /admin.');
    await expectAccessible(page);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(rows).toHaveCount(5);

    await page.getByRole('button', { name: 'Add cache rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByRole('spinbutton', { name: /^Edge TTL/ })).toHaveAttribute('placeholder', '86400');
    await sheet.getByRole('combobox', { name: /^Cache/ }).selectOption('bypass');
    await settle(page);
    await expect(sheet.getByRole('spinbutton', { name: /^Edge TTL/ })).toHaveCount(0);
    await expectAccessible(page);
});
