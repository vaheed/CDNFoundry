import { expect, test, type Page } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// The Credential check sheet on the domain page: an example in every input, a tooltip on the unclear parts, and a save.

async function openSheet(page: Page) {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains/1');
    await settle(page);
    await page.getByTestId('overview-action-credential-check').click();
    const modal = page.locator('.fi-modal-window:visible');
    await expect(modal.getByRole('heading', { name: 'Check logins against leaked passwords' })).toBeVisible();
    return modal;
}

test('every input shows an example and the sheet explains itself', async ({ page }) => {
    const modal = await openSheet(page);
    await expect(modal.getByPlaceholder('/api/login')).toBeVisible();
    await expect(modal.getByPlaceholder('password', { exact: true })).toBeVisible();
    await expect(modal.getByPlaceholder('email', { exact: true })).toBeVisible();
    await expect(modal.getByText('never stored or logged')).toBeVisible();
    await expectAccessible(page);
});

test('a login path is saved and shown again, and the check can be removed', async ({ page }) => {
    const modal = await openSheet(page);
    await modal.getByTestId('credential-path').first().fill('/api/login');
    await modal.getByPlaceholder('password', { exact: true }).first().fill('password');
    await modal.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Credential check saved')).toBeVisible();
    await page.getByTestId('overview-action-credential-check').click();
    await expect(page.locator('.fi-modal-window:visible').getByTestId('credential-path').first()).toHaveValue('/api/login');
    await page.keyboard.press('Escape');
    await page.getByTestId('overview-action-credential-check').click();
    await page.locator('.fi-modal-window:visible').getByTestId('settings-turn-off').click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Turn off', exact: true }).click();
    await expect(page.getByText('Credential check turned off')).toBeVisible();
});
