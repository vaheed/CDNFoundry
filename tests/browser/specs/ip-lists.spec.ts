import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, settle, signIn, userPassword } from './helpers';

// The IP lists tab of a domain: the list, the sheet that holds the addresses, and the rule field that uses a list.

async function openLists(page: Page): Promise<void> {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/domains');
    await settle(page);
    await page.locator('table tbody tr a').first().click();
    await settle(page);
    await page.getByRole('tab', { name: /IP lists/ }).click();
    await settle(page);
}

test('the list shows each list with its entry count and the rules that use it', async ({ page }) => {
    await openLists(page);
    const rows = page.locator('table tbody tr');
    // Other specs of the run may have added lists, so the seeded two are found by name.
    await expect(rows.filter({ hasText: 'office' })).toHaveCount(1);
    await expect(rows.filter({ hasText: 'office' })).toContainText('3');
    await expect(rows.filter({ hasText: 'partner-networks-and-payment-providers-eu' })).toContainText('200');
    await expect(rows.filter({ hasText: 'office' })).toContainText('No rules');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expectAccessible(page);
});

test('a list is added from one address or network per line, and a bad line is named', async ({ page }) => {
    await openLists(page);
    await page.getByRole('button', { name: 'Add list' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByRole('textbox', { name: /^Name/ })).toHaveAttribute('placeholder', 'office');
    await expect(sheet.getByRole('textbox', { name: /^Addresses and networks/ })).toHaveAttribute('placeholder', /203\.0\.113\.0\/24/);
    await sheet.getByRole('textbox', { name: /^Name/ }).fill('staging');
    await sheet.getByRole('textbox', { name: /^Addresses and networks/ }).fill('203.0.113.7\nnot-an-address');
    await sheet.getByRole('button', { name: 'Add list' }).click();
    await settle(page);
    await expect(sheet).toContainText('Each entry must be an IPv4 or IPv6 address or a CIDR network.');
    await sheet.getByRole('textbox', { name: /^Addresses and networks/ }).fill('203.0.113.7\n2001:db8::/48\n203.0.113.7');
    await sheet.getByRole('button', { name: 'Add list' }).click();
    await settle(page);
    const row = page.locator('table tbody tr').filter({ hasText: 'staging' });
    await expect(row).toContainText('2');
    await expectAccessible(page);
});

test('a firewall rule can test the visitor against a list by name', async ({ page }) => {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/domains');
    await settle(page);
    await page.locator('table tbody tr a').first().click();
    await settle(page);
    await page.getByRole('tab', { name: /Firewall rules/ }).click();
    await settle(page);
    await page.getByRole('button', { name: 'Add rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await sheet.getByRole('combobox', { name: /^Field/ }).selectOption('client_ip_list');
    await settle(page);
    await expect(sheet.getByRole('combobox', { name: /^Operator/ })).toBeVisible();
    await sheet.getByRole('combobox', { name: /^Operator/ }).selectOption('not_equals');
    await settle(page);
    await sheet.getByRole('combobox', { name: /^List/ }).selectOption('office');
    await sheet.getByRole('combobox', { name: /^Action/ }).selectOption('block');
    await settle(page);
    await expect(sheet.getByTestId('rule-reads-as')).toContainText('Client IP is not on list office');
    await expectAccessible(page);
});
