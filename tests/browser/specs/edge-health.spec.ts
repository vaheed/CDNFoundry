import { expect, test } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// Two facts per edge: when the control plane last heard from it, and whether other PoPs can reach it.
test('an edge with a stale heartbeat and passing probes shows both facts', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/edges');
    await settle(page);

    const quiet = page.getByRole('row').filter({ hasText: 'edge-with-a-very-long-point-of-presence-name' });
    await expect(quiet.getByText('Serving', { exact: true })).toBeVisible();
    await expect(quiet.getByText(/days? ago/)).toBeVisible();

    const unreachable = page.getByRole('row').filter({ hasText: 'edge-frankfurt-a' });
    await expect(unreachable.getByText('Not serving')).toBeVisible();

    await expectAccessible(page);
});

test('the edge page states the serving fact next to the heartbeat', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/edges');
    await page.getByRole('row').filter({ hasText: 'edge-with-a-very-long-point-of-presence-name' }).getByRole('link').first().click();
    await settle(page);
    await expect(page.getByText('Control last heard')).toBeVisible();
    await expect(page.getByText('Serving', { exact: true }).first()).toBeVisible();
    await expectAccessible(page);
});
