import { expect, test } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// An administrator opens the domain panel as a domain user from the Users screen, sees who they act as, and returns.
test('an administrator logs in as a domain user and returns to the admin panel', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/users');
    await settle(page);
    const row = page.locator('table tbody tr', { hasText: 'user@browser.test' });
    await row.getByTestId('user-impersonate-action').click();
    const dialog = page.getByRole('alertdialog');
    await expect(dialog).toContainText('recorded in the audit log');
    await dialog.getByRole('button', { name: 'Log in as user', exact: true }).click();
    await page.waitForURL(/\/app/);
    await settle(page);
    const banner = page.getByTestId('impersonation-banner');
    await expect(banner).toContainText('Acting as Browser User');
    await expect(banner).toContainText('made by Browser Admin');
    await expect(page.getByText('alpha-browser.example').first()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expectAccessible(page);
    await page.getByTestId('impersonation-leave').click();
    await page.waitForURL(/\/admin\/users/);
    await expect(page.getByTestId('impersonation-banner')).toHaveCount(0);
});
