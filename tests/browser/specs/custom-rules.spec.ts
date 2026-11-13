import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, settle, signIn, userPassword } from './helpers';

// The Custom rules tab on a domain page: the list in plain words, the rule sheet, its example placeholders, and its tooltips.

async function openRules(page: Page): Promise<void> {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/domains');
    await settle(page);
    await page.locator('table tbody tr a').first().click();
    await settle(page);
    await page.getByRole('tab', { name: /Custom rules/ }).click();
    await settle(page);
}

test('the list reads each rule in words, with its action and order', async ({ page }) => {
    await openRules(page);
    const rows = page.locator('table tbody tr');
    await expect(rows).toHaveCount(3);
    await expect(rows.first()).toContainText('Path starts with /admin and Continent does not equal EU');
    await expect(rows.nth(1)).toContainText('Client IP is one of 203.0.113.0/24, 2001:db8:abcd::/48');
    await expect(rows.nth(2)).toContainText('Path starts with /login and Method equals POST and Header x-api-key equals none and Cookie session is absent and Body size (bytes) is less than 4096');
    await expect(rows.nth(1).getByText('Allow', { exact: true })).toBeVisible();
    await expect(rows.nth(2).getByText('Challenge', { exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expectAccessible(page);
});

test('the rule sheet has an example in every input and a tooltip on the complex items', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Add rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByRole('heading', { name: /Add rule|Create/ })).toBeVisible();
    await sheet.getByRole('combobox', { name: /^Field/ }).selectOption('path');
    await settle(page);
    await sheet.getByRole('combobox', { name: /^Operator/ }).selectOption('in_list');
    await settle(page);
    const inputs = sheet.locator('input[type=text]:visible, input[type=number]:visible');
    const count = await inputs.count();
    expect(count).toBeGreaterThan(0);
    for (let index = 0; index < count; index += 1) {
        const input = inputs.nth(index);
        const hasTags = (await input.locator('xpath=ancestor::*[contains(@class,"fi-fo-tags-input")]').count()) > 0;
        const placeholder = (await input.getAttribute('placeholder')) ?? '';
        if (!hasTags) expect(placeholder, `input ${await input.getAttribute('id')} needs an example placeholder`).not.toEqual('');
    }
    expect(await sheet.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(3);
    await expectAccessible(page);
});

test('a header field asks for the header name, and the preview reads the rule back', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Add rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await sheet.getByRole('combobox', { name: /^Field/ }).selectOption('header');
    await settle(page);
    await expect(sheet.getByRole('textbox', { name: 'Name*' })).toHaveAttribute('placeholder', 'X-Api-Key');
    await sheet.getByRole('textbox', { name: 'Name*' }).fill('X-Api-Key');
    await sheet.getByRole('textbox', { name: 'Value*' }).fill('secret');
    await sheet.getByRole('textbox', { name: 'Value*' }).press('Tab');
    await settle(page);
    await expect(sheet).toContainText('Header X-Api-Key equals secret');
});

test('the attack score field explains its scale and asks for an example number', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Add rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await sheet.getByRole('combobox', { name: /^Field/ }).selectOption('attack_score');
    await settle(page);
    await sheet.getByRole('combobox', { name: /^Operator/ }).selectOption('less_than');
    await settle(page);
    await expect(sheet.getByText('From 1 (attack) to 99 (clean)', { exact: false })).toBeVisible();
    await expect(sheet.getByRole('spinbutton', { name: 'Value*' })).toHaveAttribute('placeholder', '20');
    await expectAccessible(page);
});

test('the rate limit fields appear with the toggle, with examples and a per-cell explanation', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Add rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByRole('spinbutton', { name: /^Requests/ })).toHaveCount(0);
    await sheet.getByText('Limit the request rate').click();
    await settle(page);
    await expect(sheet.getByRole('spinbutton', { name: /^Requests/ })).toHaveAttribute('placeholder', '100');
    await expect(sheet.getByRole('spinbutton', { name: /^Then for/ })).toHaveAttribute('placeholder', '300');
    await sheet.getByRole('combobox', { name: /^Count by/ }).selectOption('header');
    await settle(page);
    await expect(sheet.getByRole('textbox', { name: 'Header name*' })).toHaveAttribute('placeholder', 'X-Api-Key');
    // A rate limit offers block, challenge, and log only.
    const actions = await sheet.getByRole('combobox', { name: /^Action/ }).locator('option').allTextContents();
    expect(actions.join('|')).not.toContain('Allow');
    expect(await sheet.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(5);
    await expectAccessible(page);
});
