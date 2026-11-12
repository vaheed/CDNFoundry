import { expect, test } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// The incident profile on a domain page: start it for a chosen time, read the banner, and end it early.

test('start, read, and end the incident profile', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains/1');
    await settle(page);
    await expect(page.getByTestId('incident-banner')).toHaveCount(0);

    await page.getByRole('button', { name: 'Domain actions' }).click();
    await page.getByTestId('incident-start-action').click();
    const modal = page.locator('.fi-modal-window:visible');
    await expect(modal.getByRole('heading', { name: 'Start the incident profile?' })).toBeVisible();
    // The copy states what it protects and what it cannot.
    await expect(modal).toContainText('cannot absorb traffic that saturates a host or link');
    await modal.getByLabel('Duration').selectOption('15');
    await modal.getByRole('button', { name: /Confirm|Submit|Start/ }).last().click();

    const banner = page.getByTestId('incident-banner');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('Incident profile is on');
    await expect(banner.getByTestId('incident-protection')).toHaveCount(4);
    await expect(page.getByTestId('incident-limits-note')).toContainText('cannot absorb traffic that saturates a host, a link, or an upstream');
    await expectAccessible(page);

    await page.getByRole('button', { name: 'Domain actions' }).click();
    await page.getByTestId('incident-end-action').click();
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: /Confirm|Submit|End/ }).last().click();
    await expect(page.getByTestId('incident-banner')).toHaveCount(0);
});
