import { expect, test, type Page } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// The Sensitive data check and AI protection sheets on the domain page: an example in every input, a tooltip on the unclear parts, a save, and a removal.

async function openAction(page: Page, testId: string, heading: string) {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains/1');
    await settle(page);
    await page.getByRole('button', { name: 'Security', exact: true }).click();
    await page.getByTestId(testId).click();
    const modal = page.locator('.fi-modal-window:visible');
    await expect(modal.getByRole('heading', { name: heading })).toBeVisible();
    return modal;
}

test('the sensitive data sheet lists the kinds, explains that it only logs, and saves', async ({ page }) => {
    const modal = await openAction(page, 'sensitive-data-action', 'Watch responses for sensitive data');
    await expect(modal.getByText('It only logs')).toBeVisible();
    await expect(modal.getByLabel('Payment card numbers (with the Luhn check)')).toBeVisible();
    expect(await modal.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(1);
    await expectAccessible(page);
    await modal.getByLabel('IBAN bank account numbers').check();
    await modal.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(page.getByText('Sensitive data check saved')).toBeVisible();
});

test('the AI protection sheet shows examples, says it is pattern based, saves, and can be removed', async ({ page }) => {
    const modal = await openAction(page, 'ai-protection-action', 'Score prompts sent to your AI endpoints');
    await expect(modal.getByText('does not understand a prompt')).toBeVisible();
    await expect(modal.getByPlaceholder('/v1/chat')).toBeVisible();
    await expect(modal.getByPlaceholder('messages', { exact: true })).toBeVisible();
    expect(await modal.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(2);
    await expectAccessible(page);
    await modal.getByPlaceholder('/v1/chat').fill('/v1/chat');
    await modal.getByPlaceholder('/v1/chat').press('Enter');
    await modal.getByPlaceholder('messages', { exact: true }).fill('messages');
    await modal.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(page.getByText('AI protection saved')).toBeVisible();
    await page.getByRole('button', { name: 'Security', exact: true }).click();
    await page.getByRole('menuitem', { name: 'Remove AI protection' }).click();
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByText('AI protection removed')).toBeVisible();
});
