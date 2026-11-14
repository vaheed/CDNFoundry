import { expect, test, type Page } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// The Security alert dialog on the domain page: an example in every input and a tooltip on each field.

async function openDialog(page: Page) {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains/1');
    await settle(page);
    await page.getByTestId('overview-action-security-alert').click();
    const modal = page.locator('.fi-modal-window:visible');
    await expect(modal.getByRole('heading', { name: 'Alert on mitigated requests' })).toBeVisible();
    return modal;
}

test('the dialog explains the alert, shows an example, and offers three periods', async ({ page }) => {
    const modal = await openDialog(page);
    await expect(modal.getByText('at most once an hour')).toBeVisible();
    await expect(modal.getByRole('spinbutton', { name: /^Blocked requests/ })).toHaveAttribute('placeholder', '500');
    const periods = await modal.getByRole('combobox', { name: /^Within/ }).locator('option').allTextContents();
    expect(periods.join('|')).toContain('5 minutes');
    expect(periods.join('|')).toContain('15 minutes');
    expect(await modal.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(2);
    await expectAccessible(page);
    await expect(modal.getByRole('button', { name: 'Save' })).toBeVisible();
});

test('a threshold below one is refused before anything is saved', async ({ page }) => {
    const modal = await openDialog(page);
    const threshold = modal.getByRole('spinbutton', { name: /^Blocked requests/ });
    await threshold.fill('0');
    await threshold.press('Tab');
    await page.waitForLoadState('networkidle');
    await modal.getByRole('button', { name: 'Save', exact: true }).click();
    // The field's minimum stops the submit in the browser, and the dialog stays open with the value unsaved.
    expect(await threshold.evaluate((input: HTMLInputElement) => input.validity.rangeUnderflow)).toBe(true);
    await expect(modal.getByRole('heading', { name: 'Alert on mitigated requests' })).toBeVisible();
});
