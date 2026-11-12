import { expect, test, type Page } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// Cache settings on the domain page: analytics cookie presets, path rules, the example placeholders, and the tooltips.

async function openCacheSettings(page: Page) {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains/1');
    await settle(page);
    await page.getByRole('button', { name: 'Cache', exact: true }).click();
    await page.getByTestId('cache-settings-action').click();
    const modal = page.locator('.fi-modal-window:visible');
    await expect(modal.getByRole('heading', { name: 'Cache settings' })).toBeVisible();
    return modal;
}

test('the cache form shows example placeholders and plain-words tooltips', async ({ page }) => {
    const modal = await openCacheSettings(page);
    await modal.getByText('Advanced').click();
    const inputs = modal.locator('input[type=text]:visible, input[type=number]:visible');
    const count = await inputs.count();
    expect(count).toBeGreaterThan(0);
    for (let index = 0; index < count; index += 1) {
        const input = inputs.nth(index);
        const placeholder = (await input.getAttribute('placeholder')) ?? '';
        // Tag inputs keep their placeholder on an inner field once they hold a value.
        const hasTags = (await input.locator('xpath=ancestor::*[contains(@class,"fi-fo-tags-input")]').count()) > 0;
        if (!hasTags) expect(placeholder, `input ${await input.getAttribute('id')} needs an example placeholder`).not.toEqual('');
    }
    expect(await modal.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(8);
    await expectAccessible(page);
    await expect(modal).toHaveScreenshot('cache-settings-form.png');
});

// Saving and reading back the rules is proven server-side (FilamentWorkflowTest, CacheApiTest); these browser tests never submit a valid form, so the seeded domain stays unchanged.
test('a preset adds its cookie names and a path rule row defaults to a prefix', async ({ page }) => {
    const modal = await openCacheSettings(page);
    await modal.getByTestId('cache-cookie-presets').locator('select').selectOption({ label: 'Google Analytics' });
    await expect(modal.getByText('_ga_*', { exact: true })).toBeVisible();
    await expect(modal.getByText('_gcl_au', { exact: true })).toBeVisible();
    await modal.getByRole('button', { name: 'Add path rule' }).click();
    await expect(modal.getByRole('combobox', { name: 'Match*' })).toHaveValue('prefix');
    await modal.getByRole('textbox', { name: 'Path*' }).fill('/static/');
    await modal.getByRole('combobox', { name: 'Action*' }).selectOption('ttl');
    await expect(modal.getByRole('spinbutton', { name: 'TTL (seconds)', exact: true })).toBeVisible();
    await expectAccessible(page);
});

test('a path prefix without a leading slash is refused on the field', async ({ page }) => {
    const modal = await openCacheSettings(page);
    await modal.getByRole('button', { name: 'Add path rule' }).click();
    await modal.getByRole('textbox', { name: 'Path*' }).fill('static');
    // Blurring the field sends its value and re-renders the form; clicking Submit during that re-render is lost.
    await modal.getByRole('textbox', { name: 'Path*' }).press('Tab');
    await page.waitForLoadState('networkidle');
    await modal.getByRole('combobox', { name: 'Action*' }).selectOption('bypass');
    // The Action select is live: let its Livewire round trip finish before submitting.
    await page.waitForLoadState('networkidle');
    await modal.getByRole('button', { name: 'Submit', exact: true }).click();
    await expect(modal.getByText('A path prefix must start with /.')).toBeVisible();
});
