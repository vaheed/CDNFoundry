import { expect, test, type Page } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// The managed WAF dialog on the domain page: the adjustments section, its example placeholders, and its tooltips.

async function openWaf(page: Page) {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains/1');
    await settle(page);
    await page.getByTestId('overview-action-waf-profile').click();
    const modal = page.locator('.fi-modal-window:visible');
    await expect(modal.getByRole('heading', { name: 'Choose web application firewall protection' })).toBeVisible();
    return modal;
}

test('the adjustments start collapsed and show the pinned ruleset when opened', async ({ page }) => {
    const modal = await openWaf(page);
    await expect(modal.getByText('Paranoia level')).toBeHidden();
    await modal.getByText('Adjust managed rules').click();
    await expect(modal.getByText('owasp-crs/4.30.0')).toBeVisible();
    await expect(modal.getByText('Paranoia level')).toBeVisible();
    await expectAccessible(page);
});

test('every adjustment has an example and a plain-words tooltip', async ({ page }) => {
    const modal = await openWaf(page);
    await modal.getByText('Adjust managed rules').click();
    await expect(modal.getByRole('spinbutton', { name: /^Anomaly threshold/ })).toHaveAttribute('placeholder', '5');
    // The rule pickers search the catalog by number or by name.
    for (const label of ['Turn off single rules', 'Log only']) {
        await expect(modal.getByText(label, { exact: true }).first()).toBeVisible();
    }
    await expect(modal.locator('.fi-select-input-placeholder', { hasText: 'for example 942100' })).toBeVisible();
    await expect(modal.locator('.fi-select-input-placeholder', { hasText: 'for example 941100' })).toBeVisible();
    await expect(modal.getByText('SQL injection')).toBeVisible();
    expect(await modal.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(5);
    await expectAccessible(page);
    await expect(modal).toHaveScreenshot('waf-tuning-form.png');
});
