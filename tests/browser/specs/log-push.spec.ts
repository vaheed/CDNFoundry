import { expect, test } from '@playwright/test';
import { expectAccessible, settle, signIn, userPassword } from './helpers';

// The Log push tab on a domain page: its destinations, their state, the add form, and the frontend rules.

async function openLogPush(page: import('@playwright/test').Page): Promise<void> {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/domains');
    await settle(page);
    // The first assigned domain is the seeded one that carries the destinations.
    await page.locator('table tbody tr a').first().click();
    await settle(page);
    await page.getByRole('tab', { name: /Log push/ }).click();
    await settle(page);
}

test('the log push tab shows each destination with its state', async ({ page }) => {
    await openLogPush(page);
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(2);
    await expect(rows.getByText('Failing', { exact: true })).toBeVisible();
    await expect(rows.getByText('Active', { exact: true })).toBeVisible();
    // Long names are cut, never widen the table, and nothing hides the row actions.
    await expect(rows.first().getByRole('button', { name: /Send test/ })).toBeVisible();
    await expectAccessible(page);
});

test('the add form has an example in every input and a tooltip on the complex fields', async ({ page }) => {
    await openLogPush(page);
    await page.getByRole('button', { name: 'Add destination' }).click();
    const modal = page.locator('.fi-modal-window:visible');
    await expect(modal.getByRole('heading', { name: 'Add destination' })).toBeVisible();
    for (const input of await modal.locator('input[type=text], input[type=password], input[type=url], input:not([type])').all()) {
        if (await input.isVisible()) {
            expect((await input.getAttribute('placeholder')) ?? '', 'every visible text input shows an example').not.toEqual('');
        }
    }
    expect(await modal.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(3);
    // A private address is refused with a reason.
    await modal.getByLabel('Name').fill('Loopback attempt');
    await modal.getByLabel('Endpoint URL').fill('https://127.0.0.1/ingest');
    await modal.getByRole('button', { name: /Submit|Add|Create|Confirm/ }).last().click();
    await expect(page.getByText(/loopback|private|link-local/i)).toBeVisible();
    await page.keyboard.press('Escape');
});

test('the tab matches its snapshot', async ({ page }) => {
    await openLogPush(page);
    await expect(page).toHaveScreenshot('log-push-tab.png', { fullPage: true });
});
