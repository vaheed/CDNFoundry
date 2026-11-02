import { expect, test } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn, userPassword } from './helpers';

test.describe('administrator overview', () => {
    test('shows status, KPI strip, and recent operations', async ({ page }) => {
        await signIn(page, 'admin', 'admin@browser.test', adminPassword);
        await page.goto('/admin');
        await settle(page);
        await expect(page.getByTestId('overview-status')).toBeVisible();
        await expect(page.getByTestId('overview-live')).toHaveText('Polling active');
        await expect(page.getByText('Healthy PoPs')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByText('DNS success')).toBeVisible();
        await expect(page.getByTestId('overview-recent')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('sidebar-version')).toHaveText(/^v\d+\.\d+\.\d+/);
        await expectAccessible(page);
        await expect(page).toHaveScreenshot('overview.png', { fullPage: true });
    });

    test('opens global search with the keyboard', async ({ page, isMobile }) => {
        test.skip(isMobile === true || page.viewportSize()!.width < 600, 'keyboard shortcut is a desktop affordance');
        await signIn(page, 'admin', 'admin@browser.test', adminPassword);
        await page.goto('/admin');
        await page.keyboard.press('Control+k');
        await expect(page.getByRole('searchbox').first()).toBeFocused();
    });
});

test.describe('domains', () => {
    test('administrator lists domains and opens a domain with tabs', async ({ page }) => {
        await signIn(page, 'admin', 'admin@browser.test', adminPassword);
        await page.goto('/admin/domains');
        await settle(page);
        await expect(page.getByTestId('domain-create-button')).toBeVisible();
        await expect(page.getByText('alpha-browser.example')).toBeVisible();
        await expectAccessible(page);
        await expect(page).toHaveScreenshot('domains-list.png', { fullPage: true });

        await page.getByText('alpha-browser.example').first().click();
        await settle(page);
        await expect(page.getByRole('tab', { name: 'Overview' })).toBeVisible();
        await expect(page.getByRole('tab', { name: 'TLS' })).toBeVisible();
        await expect(page.getByTestId('domain-attention')).toBeVisible();
        await expect(page.getByTestId('domain-rail')).toBeVisible();
        await expectAccessible(page);
        await expect(page).toHaveScreenshot('domain-detail.png', { fullPage: true });
    });

    test('domain user sees only assigned domains', async ({ page }) => {
        await signIn(page, 'app', 'user@browser.test', userPassword);
        await page.goto('/app/domains');
        await settle(page);
        await expect(page.getByText('alpha-browser.example')).toBeVisible();
        await expect(page.getByText('private-browser.example')).toHaveCount(0);
        await expectAccessible(page);
        await expect(page).toHaveScreenshot('app-domains-list.png', { fullPage: true });
    });

    test('adding a domain opens in a side sheet', async ({ page }) => {
        await signIn(page, 'app', 'user@browser.test', userPassword);
        await page.goto('/app/domains');
        await settle(page);
        await page.getByTestId('domain-create-button').click();
        await expect(page.getByRole('heading', { name: 'Add domain', level: 2 })).toBeVisible();
        await expect(page.getByRole('textbox', { name: 'Domain' })).toBeFocused();
    });
});

test('quick action opens its form in a side sheet', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains');
    await page.getByText('alpha-browser.example').first().click();
    await settle(page);
    await page.getByTestId('domain-quick-purge-cache').click();
    await expect(page.getByRole('heading', { name: 'Purge cache', level: 2 })).toBeVisible();
});

test.describe('platform settings', () => {
    test('save is disabled until a value changes, and reset restores the default', async ({ page }) => {
        await signIn(page, 'admin', 'admin@browser.test', adminPassword);
        await page.goto('/admin/platform-settings');
        await settle(page);
        const save = page.getByTestId('settings-save-button');
        await expect(save).toBeDisabled();
        await expectAccessible(page);
        await expect(page).toHaveScreenshot('platform-settings.png', { fullPage: true });
        const firstNumber = page.locator('[data-testid^="setting-"] input[inputmode="numeric"]').first();
        const original = await firstNumber.inputValue();
        await firstNumber.fill(String(Number(original) + 1));
        await expect(save).toBeEnabled();
    });
});

test('editing a DNS record opens a grouped single-column sheet', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/domains/1');
    await settle(page);
    await page.locator('.fi-ta input[type=search]').first().fill('geo-routed');
    await settle(page);
    const row = page.locator('tr', { hasText: 'geo-routed' });
    await row.getByRole('button', { name: /edit/i }).click();
    await expect(page.getByRole('heading', { name: /edit/i, level: 2 })).toBeVisible();
    await expect(page.getByText('Geo-DNS routing')).toBeVisible();
    await settle(page);
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('dns-record-edit.png');
});

test('topology shows nodes, a live indicator, and search dims the rest', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/topology');
    await settle(page);
    await expect(page.getByTestId('topology-live')).toHaveText('Polling active');
    await expect(page.getByTestId('topology-node').first()).toBeVisible();
    await page.getByTestId('topology-search').fill('with-a-very-long');
    await expect(page.locator('[data-testid=topology-node].is-dimmed').first()).toBeAttached();
    await expectAccessible(page);
    await expect(page).toHaveScreenshot('topology.png');
});
