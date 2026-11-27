import { expect, test } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// The Proxy defaults sheet: the diagnostic headers choice has a tooltip, three named options, and the sheet stays accessible.

test('the proxy defaults sheet offers the diagnostic headers choice with a tooltip', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains/1');
    await settle(page);
    await page.getByTestId('overview-action-proxy-defaults').first().click();
    const sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByText('Diagnostic headers').first()).toBeVisible();
    await expect(sheet.getByRole('combobox', { name: /^Diagnostic headers/ })).toContainText('Inherit the platform default');
    expect(await sheet.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(1);
    await sheet.getByRole('combobox', { name: /^Diagnostic headers/ }).click();
    await expect(page.getByRole('option', { name: 'On', exact: true })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Off', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await expectAccessible(page);
});
