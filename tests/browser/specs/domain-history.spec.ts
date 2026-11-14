import { expect, test } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

/** The seeded rows are the oldest, so after other specs add events they may sit on a later page: page forward until the row shows. */
async function expectOnSomePage(page: import('@playwright/test').Page, row: import('@playwright/test').Locator): Promise<void> {
    // Start from the first page, so a check that follows another never begins where the last one stopped.
    const previous = page.getByRole('button', { name: 'Previous' });
    for (let back = 0; back < 8 && (await previous.isVisible()) && !(await previous.isDisabled()); back++) {
        await previous.click();
        await settle(page);
    }
    for (let attempt = 0; attempt < 8; attempt++) {
        if (await row.isVisible()) return;
        const next = page.getByRole('button', { name: 'Next' });
        if (!(await next.isVisible()) || (await next.isDisabled())) break;
        await next.click();
        await settle(page);
    }
    await expect(row).toBeVisible();
}

// Operator actions and changes the platform made on its own are listed apart and can be filtered.
test('the domain history shows operator actions and automatic changes, and filters them', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    // Other specs add domains and events, so the domain is found by search rather than by its place on the first page.
    await page.goto('/admin/domains?tableSearch=alpha-browser');
    await settle(page);
    await page.getByText('alpha-browser.example').first().click();
    await settle(page);
    await page.getByRole('tab', { name: /History/ }).click();
    await settle(page);
    // Other specs add events to this domain; the newest rows would hide the seeded ones, so the table shows fifty at a time.
    await page.getByRole('combobox', { name: 'Per page' }).selectOption('50');
    await settle(page);

    const automatic = page.getByRole('row').filter({ hasText: 'Security state changed' });
    await expectOnSomePage(page, automatic.first());
    await expectOnSomePage(page, page.getByText('Normal to suspected, 64 events in 5 minutes'));
    await expectOnSomePage(page, page.getByText('Suspected to normal, quiet for 10 minutes'));
    await expectOnSomePage(page, automatic.first().getByText('Platform'));
    await expectOnSomePage(page, page.getByRole('row').filter({ hasText: 'Cache purged' }).first());

    await page.getByRole('button', { name: 'Filter', exact: true }).click();
    await page.getByLabel('Source').selectOption('automatic');
    await expect(page.getByRole('row').filter({ hasText: 'Cache purged' })).toHaveCount(0);
    await expect(automatic.first()).toBeVisible();

    await page.getByLabel('Source').selectOption('operator');
    await expect(automatic).toHaveCount(0);
    await expectOnSomePage(page, page.getByRole('row').filter({ hasText: 'Cache purged' }).first());

    await expectAccessible(page);
});
