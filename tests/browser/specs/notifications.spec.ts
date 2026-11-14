import { expect, test, type Page } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn, userPassword } from './helpers';

// Notification channels in both panels: adding, testing, removing, the one-time signing secret, the pause reason, scoping,
// and the frontend rules (an example placeholder in every text input, a tooltip on every complex label).

async function expectFrontendRules(page: Page): Promise<void> {
    const form = page.getByTestId('notification-create');
    const inputs = form.locator('input[type=text], input[type=password], input:not([type])');
    const count = await inputs.count();
    expect(count).toBeGreaterThan(0);
    for (let index = 0; index < count; index += 1) {
        const input = inputs.nth(index);
        if (await input.isVisible()) {
            expect((await input.getAttribute('placeholder')) ?? '', `input ${await input.getAttribute('id')} needs an example placeholder`).not.toEqual('');
        }
    }
    // Type, credentials, and events carry a plain-words tooltip.
    expect(await form.locator('[x-tooltip]').count()).toBeGreaterThanOrEqual(3);
}

test('administrator adds, tests, and removes a platform channel', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/notifications');
    await settle(page);
    await expect(page.getByRole('heading', { name: 'Notifications', level: 1 })).toBeVisible();
    await expectFrontendRules(page);
    await expect(page.getByTestId('notification-type').locator('option')).toHaveText(['Telegram', 'Discord', 'Slack', 'Webhook']);
    await expect(page.getByTestId('notification-row')).toHaveCount(2);

    await page.getByTestId('notification-name').fill('Browser telegram');
    await page.getByTestId('notification-bot-token').fill('123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
    await page.getByTestId('notification-chat-id').fill('-1001234567890');
    await page.getByTestId('notification-add-button').click();
    await expect(page.getByTestId('notification-row')).toHaveCount(3);
    await expect(page.getByTestId('notification-row').filter({ hasText: 'Browser telegram' })).toBeVisible();
    // A secret is never shown again after it is stored.
    await expect(page.locator('body')).not.toContainText('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');

    const added = page.getByTestId('notification-row').filter({ hasText: 'Browser telegram' });
    await added.getByTestId('notification-test').click();
    await expect(page.getByText('Test message queued')).toBeVisible();

    page.once('dialog', (dialog) => void dialog.accept());
    await added.getByTestId('notification-remove').click();
    await expect(page.getByTestId('notification-row')).toHaveCount(2);
});

test('a webhook shows its signing secret once', async ({ page }) => {
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/notifications');
    await page.getByTestId('notification-type').selectOption('webhook');
    await page.getByTestId('notification-name').fill('Browser webhook');
    await expect(page.getByTestId('notification-url')).toHaveAttribute('placeholder', /^https:\/\/.+/);
    // A loopback address is refused with a reason the user can act on.
    await page.getByTestId('notification-url').fill('http://127.0.0.1/hook');
    await page.getByTestId('notification-add-button').click();
    await expect(page.getByRole('alert').filter({ hasText: /loopback|private/i })).toBeVisible();
    await page.getByTestId('notification-url').fill('https://93.184.216.34/hook');
    await page.getByTestId('notification-add-button').click();
    const secret = page.getByTestId('notification-secret-value');
    await expect(secret).toBeVisible();
    expect((await secret.textContent())?.length).toBe(48);
    await page.reload();
    await settle(page);
    await expect(page.getByTestId('notification-secret')).toHaveCount(0);
    for (const row of await page.getByTestId('notification-row').filter({ hasText: 'Browser webhook' }).all()) {
        page.once('dialog', (dialog) => void dialog.accept());
        await row.getByTestId('notification-remove').click();
    }
});

test('a domain user sees only their own channels, the pause reason, and no administrator events', async ({ page }) => {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/notifications');
    await settle(page);
    await expect(page.getByTestId('notification-row')).toHaveCount(2);
    await expect(page.getByText('Operations chat for the whole platform')).toHaveCount(0);
    await expect(page.getByTestId('notification-paused-reason')).toContainText('Discord answered HTTP 404');
    await expect(page.getByTestId('notification-resume')).toHaveCount(1);
    await expect(page.getByTestId('notification-type').locator('option')).toHaveText(['Telegram', 'Discord', 'Slack']);
    await expect(page.getByTestId('notification-events')).not.toContainText('Platform alert');
    await expectFrontendRules(page);
    await expectAccessible(page);
});

test('a user channel needs at least one event', async ({ page }) => {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/notifications');
    await page.getByTestId('notification-name').fill('No events');
    await page.getByTestId('notification-bot-token').fill('123456789:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA');
    await page.getByTestId('notification-chat-id').fill('-1001234567890');
    for (const box of await page.getByTestId('notification-events').locator('input[type=checkbox]').all()) {
        await box.uncheck();
    }
    await page.getByTestId('notification-add-button').click();
    await expect(page.getByTestId('notification-events').getByRole('alert')).toBeVisible();
    await expect(page.getByTestId('notification-row')).toHaveCount(2);
});

for (const [panel, path, email, password] of [
    ['admin', '/admin/notifications', 'admin@browser.test', adminPassword],
    ['app', '/app/notifications', 'user@browser.test', userPassword],
] as const) {
    test(`${panel} notifications screen is accessible and matches its snapshot`, async ({ page }) => {
        await signIn(page, panel, email, password);
        await page.goto(path);
        await settle(page);
        await expectAccessible(page);
        await expect(page).toHaveScreenshot(`notifications-${panel}.png`, { fullPage: true });
    });
}
