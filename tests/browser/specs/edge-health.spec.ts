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

test('the cells list states whether each cell runs the pinned rule set', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/edges');
    await page.getByRole('row').filter({ hasText: 'edge-with-a-very-long-point-of-presence-name' }).getByRole('link').first().click();
    await settle(page);
    await page.getByRole('tab', { name: /Cells/ }).click();
    await settle(page);
    await expect(page.getByRole('columnheader', { name: /Rule set/ })).toBeVisible();
    // The seeded cells never reported commits, so none of them can be called pinned.
    await expect(page.getByText('Not reported', { exact: true }).first()).toBeVisible();
    await expect(page.getByText('Pinned', { exact: true })).toHaveCount(0);
    await expectAccessible(page);
});

test('the pools list states whether each pool runs the pinned rule set', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/edge-pools');
    await settle(page);
    await expect(page.getByRole('columnheader', { name: /Rule set/ })).toBeVisible();
    // The seeded shared pool holds cells that never reported commits; the reserved pool holds none.
    await expect(page.getByRole('row').filter({ hasText: 'shared-default' }).getByText('Not reported', { exact: true })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: 'quarantine-pool-for-suspicious-traffic' }).getByText('No cells', { exact: true })).toBeVisible();
    await expect(page.getByText('Pinned', { exact: true })).toHaveCount(0);
    await expectAccessible(page);
});
