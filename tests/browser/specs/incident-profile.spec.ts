import { expect, test } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// The incident profile on a domain page: start it for a chosen time, read the banner, and end it early.

async function startProfile(page: import('@playwright/test').Page): Promise<void> {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains/1');
    await settle(page);
    await expect(page.getByTestId('incident-banner')).toHaveCount(0);
    await page.getByRole('button', { name: 'Security', exact: true }).click();
    await page.getByTestId('incident-start-action').click();
    const modal = page.locator('.fi-modal-window:visible');
    await expect(modal.getByRole('heading', { name: 'Start the incident profile?' })).toBeVisible();
    // The copy states what it protects and what it cannot.
    await expect(modal).toContainText('cannot absorb traffic that saturates a host or link');
    await modal.getByLabel('Duration').selectOption('15');
    await modal.getByRole('button', { name: 'Submit', exact: true }).click();
}

test('start the incident profile and read its banner', async ({ page }) => {
    await startProfile(page);
    const banner = page.getByTestId('incident-banner');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('Incident profile is on');
    await expect(banner.getByTestId('incident-protection')).toHaveCount(4);
    await expect(page.getByTestId('incident-limits-note')).toContainText('cannot absorb traffic that saturates a host, a link, or an upstream');
    await expectAccessible(page);
    // Leave the seeded domain as it was found: end the profile through the same menu.
    await page.getByRole('button', { name: 'Security', exact: true }).click();
    await page.getByTestId('incident-end-action').click();
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'Confirm', exact: true }).click();
});

// Ending the profile is proven on the server (IncidentProfilePanelTest, IncidentProfileTest) and the Start path above runs in a
// real browser. The banner clearing after End has not yet been confirmed in a browser run: the run that tried it shared its app
// with another run, so its result is not trusted. Re-enable this once a run on a free stack passes.
test.fixme('ending the profile clears the banner without a reload', async ({ page }) => {
    await startProfile(page);
    await page.getByRole('button', { name: 'Security', exact: true }).click();
    await page.getByTestId('incident-end-action').click();
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByText('Incident profile ended')).toBeVisible();
    await expect(page.getByTestId('incident-banner')).toHaveCount(0);
});
