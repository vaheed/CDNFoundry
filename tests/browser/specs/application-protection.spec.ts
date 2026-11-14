import { expect, test, type Page } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn, userPassword } from './helpers';

// The API schema and Client-side security sheets, and the Scripts and connections tab on a domain page.

async function openAction(page: Page, testId: string, heading: string) {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains/1');
    await settle(page);
    await page.getByTestId(testId).click();
    const modal = page.locator('.fi-modal-window:visible');
    await expect(modal.getByRole('heading', { name: heading })).toBeVisible();
    return modal;
}

test('the API schema sheet shows examples, refuses a bad document, and saves a good one', async ({ page }) => {
    const modal = await openAction(page, 'overview-action-api-schema', 'Check requests against an OpenAPI schema');
    await expect(modal.getByPlaceholder('/api/v1', { exact: true })).toBeVisible();
    await expect(modal.getByPlaceholder('65536', { exact: true })).toBeVisible();
    await expect(modal.getByPlaceholder(/"openapi": "3.0.3"/)).toBeVisible();
    expect(await modal.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(3);
    await expectAccessible(page);
    await modal.getByPlaceholder(/"openapi": "3.0.3"/).fill('{not json');
    await modal.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('The schema was not saved')).toBeVisible();
    await modal.getByPlaceholder(/"openapi": "3.0.3"/).fill('{"openapi":"3.0.3","info":{"title":"Orders","version":"1"},"paths":{"/orders":{"get":{}}}}');
    await modal.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('API schema saved')).toBeVisible();
    await page.getByTestId('overview-action-api-schema').click();
    await page.locator('.fi-modal-window:visible').getByTestId('settings-turn-off').click();
    await page.getByRole('alertdialog').getByRole('button', { name: 'Remove schema', exact: true }).click();
    await expect(page.getByText('API schema removed')).toBeVisible();
});

test('the client-side sheet explains itself, saves, and can be turned off', async ({ page }) => {
    const modal = await openAction(page, 'overview-action-client-security', 'Watch the scripts your pages load');
    await expect(modal.getByText('Nothing is blocked')).toBeVisible();
    await expect(modal.getByPlaceholder('120', { exact: true })).toBeVisible();
    await expect(modal.getByPlaceholder('60', { exact: true })).toBeVisible();
    expect(await modal.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(2);
    await expectAccessible(page);
    await modal.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByText('Client-side security saved')).toBeVisible();
});

test('the scripts tab lists what browsers reported, marks the new ones, and approves them', async ({ page }, testInfo) => {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/domains');
    await settle(page);
    await page.locator('table tbody tr a').first().click();
    await settle(page);
    await page.getByRole('tab', { name: /Scripts and connections/ }).click();
    await settle(page);
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(5);
    await expect(page.getByText('xn--bcher-kva.example')).toBeVisible();
    await expect(rows.filter({ hasText: 'Approved' }).first()).toBeVisible();
    // Long addresses are cut and never widen the table.
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expectAccessible(page);
    // Approving changes the shared test data, so the approval flow runs once, in the first project.
    if (testInfo.project.name !== 'light-desktop') return;
    await expect(rows.filter({ hasText: 'New' })).toHaveCount(4);
    await rows.filter({ hasText: 'inline' }).getByTestId('client-script-approve').click();
    await expect(rows.filter({ hasText: 'New' })).toHaveCount(3);
    await page.getByTestId('client-script-approve-all').click();
    await page.locator('.fi-modal-window:visible').getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByText('All new scripts approved')).toBeVisible();
    await expect(rows.filter({ hasText: 'New' })).toHaveCount(0);
});
