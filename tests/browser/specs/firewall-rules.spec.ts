import { expect, test, type Page } from '@playwright/test';
import { expectAccessible, settle, signIn, userPassword } from './helpers';

// The Firewall rules tab on a domain page: the list in plain words, the rule sheet, its example placeholders, and its tooltips.

async function openRules(page: Page): Promise<void> {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/domains');
    await settle(page);
    await page.locator('table tbody tr a').first().click();
    await settle(page);
    await page.getByRole('tab', { name: /Firewall rules/ }).click();
    await settle(page);
}

test('the list reads each rule in words, with its action and order', async ({ page }) => {
    await openRules(page);
    // The save spec below adds a rate-limit rule to the shared seed, so the seeded rows are counted without it.
    const rows = page.locator('table tbody tr').filter({ hasNotText: 'responses with status' });
    await expect(rows).toHaveCount(4);
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
    await sheet.getByText('Only above a request rate').click();
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

test('the user agent preset dialog has an example and a tooltip, and the hotlink dialog lists its defaults', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Presets', exact: true }).click();
    await page.getByRole('menuitem', { name: 'Block user agents' }).click();
    let sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByRole('heading', { name: 'Block or challenge user agents' })).toBeVisible();
    await expect(sheet.locator('input[placeholder="sqlmap"]')).toBeVisible();
    expect(await sheet.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(2);
    await expectAccessible(page);
    await sheet.getByRole('button', { name: 'Cancel' }).click();

    await page.getByRole('button', { name: 'Presets', exact: true }).click();
    await page.getByRole('menuitem', { name: 'Hotlink protection' }).click();
    sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByRole('heading', { name: 'Protect files from hotlinking' })).toBeVisible();
    await expect(sheet.getByText('webp', { exact: true })).toBeVisible();
    await expect(sheet.locator('input[placeholder="www.partner.example"]')).toBeVisible();
    expect(await sheet.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(2);
    await expectAccessible(page);
    // Nothing was saved, so the seeded rules stay the only ones and neither remove action is offered.
    await sheet.getByRole('button', { name: 'Cancel' }).click();
    await page.getByRole('button', { name: 'Presets', exact: true }).click();
    await expect(page.getByRole('menuitem', { name: 'Remove user agent rules' })).toHaveCount(0);
});

test('a field whose detection is not set up is listed but cannot be picked', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Add rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    const option = sheet.getByRole('combobox', { name: /^Field/ }).locator('option', { hasText: 'Prompt injection score (needs AI protection)' });
    await expect(option).toHaveCount(1);
    await expect(option).toBeDisabled();
    await expect(sheet.getByRole('combobox', { name: /^Field/ }).locator('option', { hasText: 'Attack score' }).first()).toBeEnabled();
    // The sheet reads top to bottom in one column: conditions, then the action, then the rule in words.
    const conditions = await sheet.getByRole('combobox', { name: /^Field/ }).boundingBox();
    const action = await sheet.getByRole('combobox', { name: /^Action/ }).boundingBox();
    const width = (await sheet.boundingBox())?.width ?? 0;
    expect(conditions && action && conditions.y < action.y && action.width > width * 0.6).toBe(true);
    await expect(sheet.getByTestId('rule-reads-as')).toContainText('Reads as');
    await expect(sheet.getByRole('button', { name: 'Add rule', exact: true })).toBeVisible();
    await expectAccessible(page);
});

test('a shadow rule shows its badge and end, and the list counts what each rule matched', async ({ page }) => {
    await openRules(page);
    const rows = page.locator('table tbody tr');
    const shadow = rows.filter({ hasText: 'Watch for scripted sign-ups' });
    await expect(shadow.getByText('Shadow', { exact: true })).toBeVisible();
    await expect(shadow).toContainText('Ends 2 days from now');
    await expect(shadow).toContainText('37 would act');
    await expect(rows.first()).toContainText('1,284 acted');
    await expect(rows.nth(1)).toContainText('0');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expectAccessible(page);
});

test('the sheet offers shadow mode for block and challenge only, an end time, and a partial estimate', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Add rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await expect(sheet.getByRole('combobox', { name: /^Mode/ })).toHaveCount(0);
    await sheet.getByRole('combobox', { name: /^Action/ }).selectOption('allow');
    await settle(page);
    await expect(sheet.getByRole('combobox', { name: /^Mode/ })).toHaveCount(0);
    await sheet.getByRole('combobox', { name: /^Action/ }).selectOption('block');
    await settle(page);
    await expect(sheet.getByRole('combobox', { name: /^Mode/ })).toBeVisible();
    await sheet.getByRole('combobox', { name: /^Field/ }).selectOption('path');
    await settle(page);
    await sheet.getByRole('textbox', { name: 'Value*' }).fill('/signup');
    await sheet.getByRole('textbox', { name: 'Value*' }).press('Tab');
    await settle(page);
    await sheet.getByText('Details').click();
    await expect(sheet.getByText('Ends at')).toBeVisible();
    await sheet.getByRole('button', { name: 'Estimate the last 24 hours' }).click();
    await settle(page);
    await expect(sheet.getByTestId('rule-impact-estimate')).toContainText('312 of 48,210 requests in the last 24 hours. Partial');
    await expectAccessible(page);
});

test('a rate limit offers window, scope, response counting and combined keys, each named for what it does', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Add rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    await sheet.getByText('Only above a request rate').click();
    await settle(page);

    // Periods reach 24 hours, and the keys include cookie, query argument, network and country.
    const periods = (await sheet.getByRole('combobox', { name: /^Per/ }).locator('option').allTextContents()).map((text) => text.trim());
    expect(periods).toEqual(expect.arrayContaining(['1 hour', '24 hours']));
    const keys = (await sheet.getByRole('combobox', { name: /^Count by/ }).locator('option').allTextContents()).map((text) => text.trim());
    expect(keys).toEqual(expect.arrayContaining(['Cookie value', 'Query argument', 'ASN', 'Country']));

    // The window defaults to fixed and offers sliding; the scope defaults to each cell on its own.
    const window = sheet.getByRole('combobox', { name: /^Window/ });
    await expect(window).toHaveValue('fixed');
    await window.selectOption('sliding');
    await expect(window).toHaveValue('sliding');
    const scope = sheet.getByRole('combobox', { name: /^Across cells/ });
    await expect(scope).toHaveValue('per_cell');
    expect((await scope.locator('option').allTextContents()).map((text) => text.trim()).filter((text) => text !== 'Select an option')).toEqual(['Each cell counts on its own', 'Threshold shared among the cells']);

    // A cookie key asks for the cookie name, a query-argument key for the argument name.
    const key = sheet.getByRole('combobox', { name: /^Count by/ });
    await key.selectOption('cookie');
    await settle(page);
    await expect(sheet.getByRole('textbox', { name: 'Cookie name*' })).toHaveAttribute('placeholder', 'session');
    await key.selectOption('query_arg');
    await settle(page);
    await expect(sheet.getByRole('textbox', { name: 'Argument name*' })).toHaveAttribute('placeholder', 'token');

    // A second key shows its own name field only when it needs one.
    await expect(sheet.getByRole('textbox', { name: 'Second name*' })).toHaveCount(0);
    await sheet.getByRole('combobox', { name: /^And also by/ }).selectOption('header');
    await settle(page);
    await expect(sheet.getByRole('textbox', { name: 'Second name*' })).toHaveAttribute('placeholder', 'user');
    await sheet.getByRole('combobox', { name: /^And also by/ }).selectOption('country');
    await settle(page);
    await expect(sheet.getByRole('textbox', { name: 'Second name*' })).toHaveCount(0);

    // Response counting is a list of statuses with an example and a hint.
    const statuses = sheet.getByRole('combobox', { name: /^Count only responses/ });
    await expect(statuses).toHaveAttribute('placeholder', '401');
    await expect(sheet.getByText('Type a status and press Enter. Up to 10.')).toBeVisible();
    await expectAccessible(page);
});

test('a rate limit rule is saved with its window, scope and counted statuses, and the list reads it back', async ({ page }) => {
    await openRules(page);
    await page.getByRole('button', { name: 'Add rule' }).click();
    const sheet = page.locator('.fi-modal-window:visible');
    // A rate-limit rule may have no conditions, so the empty one the sheet starts with is removed.
    await sheet.getByRole('button', { name: 'Delete' }).first().click();
    await sheet.getByText('Only above a request rate').click();
    await settle(page);
    await sheet.getByRole('combobox', { name: /^Action/ }).selectOption('block');
    await sheet.getByRole('combobox', { name: /^Count by/ }).selectOption('cookie');
    await settle(page);
    await sheet.getByRole('textbox', { name: 'Cookie name*' }).fill('sid');
    await sheet.getByRole('spinbutton', { name: /^Requests/ }).fill('5');
    await sheet.getByRole('combobox', { name: /^Per/ }).selectOption('600');
    await sheet.getByRole('combobox', { name: /^Window/ }).selectOption('sliding');
    await sheet.getByRole('combobox', { name: /^Across cells/ }).selectOption('shared');
    const statuses = sheet.getByRole('combobox', { name: /^Count only responses/ });
    await statuses.fill('401');
    await statuses.press('Enter');
    await statuses.fill('403');
    await statuses.press('Enter');
    await sheet.getByRole('button', { name: 'Add rule', exact: true }).click();
    await settle(page);
    await expect(page.locator('table tbody tr').filter({ hasText: '5 responses with status 401, 403 per 10 minutes per cookie value sid, sliding, shared among the cells' })).toHaveCount(1);
});
