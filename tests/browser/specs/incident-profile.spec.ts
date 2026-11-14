import { expect, test } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// Under attack mode (the incident profile) on a domain page: start it for a chosen time, read the banner, and end it early.

async function startProfile(page: import('@playwright/test').Page): Promise<void> {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    // Other specs add domains and events, so the domain is found by search rather than by its place on the first page.
    await page.goto('/admin/domains?tableSearch=alpha-browser');
    await settle(page);
    await page.getByText('alpha-browser.example').first().click();
    await settle(page);
    await expect(page.getByTestId('incident-banner')).toHaveCount(0);
    await page.getByTestId('incident-start-action').click();
    const modal = page.locator('.fi-modal-window:visible');
    await expect(modal.getByRole('heading', { name: 'Turn on under attack mode?' })).toBeVisible();
    // The copy states what it protects and what it cannot.
    await expect(modal).toContainText('cannot absorb traffic that saturates a host or link');
    await modal.getByLabel('Duration').selectOption('15');
    await modal.getByRole('button', { name: 'Turn on', exact: true }).click();
}

test('turn on under attack mode and read its banner', async ({ page }) => {
    await startProfile(page);
    const banner = page.getByTestId('incident-banner');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('Under attack mode is on');
    await expect(banner.getByTestId('incident-protection')).toHaveCount(4);
    await expect(page.getByTestId('incident-limits-note')).toContainText('cannot absorb traffic that saturates a host, a link, or an upstream');
    await expectAccessible(page);
    // Leave the seeded domain as it was found: end it from the banner.
    await page.getByTestId('incident-banner-end').click();
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'Confirm', exact: true }).click();
});

// Ending the profile is proven on the server (IncidentProfilePanelTest, IncidentProfileTest) and the Start path above runs in a
// real browser. The banner clearing after End has not yet been confirmed in a browser run: the run that tried it shared its app
// with another run, so its result is not trusted. Re-enable this once a run on a free stack passes.
test.fixme('ending the profile clears the banner without a reload', async ({ page }) => {
    await startProfile(page);
    await page.getByTestId('incident-banner-end').click();
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'Confirm', exact: true }).click();
    await expect(page.getByText('Under attack mode ended')).toBeVisible();
    await expect(page.getByTestId('incident-banner')).toHaveCount(0);
});
