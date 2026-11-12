import { expect, test } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// Operator actions and changes the platform made on its own are listed apart and can be filtered.
test('the domain history shows operator actions and automatic changes, and filters them', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains/1');
    await settle(page);
    await page.getByRole('tab', { name: /History/ }).click();
    await settle(page);

    const automatic = page.getByRole('row').filter({ hasText: 'Security state changed' });
    await expect(automatic.first()).toBeVisible();
    await expect(page.getByText('Normal to suspected, 64 events in 5 minutes')).toBeVisible();
    await expect(page.getByText('Suspected to normal, quiet for 10 minutes')).toBeVisible();
    await expect(automatic.first().getByText('Platform')).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'Cache purged' }).first()).toBeVisible();

    await page.getByRole('button', { name: 'Filter', exact: true }).click();
    await page.getByLabel('Source').selectOption('automatic');
    await expect(page.getByRole('row').filter({ hasText: 'Cache purged' })).toHaveCount(0);
    await expect(automatic.first()).toBeVisible();

    await page.getByLabel('Source').selectOption('operator');
    await expect(automatic).toHaveCount(0);
    await expect(page.getByRole('row').filter({ hasText: 'Cache purged' }).first()).toBeVisible();

    await expectAccessible(page);
});
