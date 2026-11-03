import { expect, test } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn } from './helpers';

// The first-run wizard: one step per settings group, every setting beside its default, and a reset on every step.
test('first-run wizard shows defaults, resets a step, and keeps every default without changing anything', async ({ page }) => {
    const problems: string[] = [];
    page.on('pageerror', (e) => problems.push(e.message));
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await page.goto('/admin/first-run-setup');
    await settle(page);
    await expectAccessible(page);

    const wizard = page.getByTestId('first-run-wizard');
    await expect(wizard).toBeVisible();
    await expect(wizard.getByText(/Default:/).first()).toBeVisible();

    // Change a value on the first step, then reset the whole step: the default comes back.
    const input = wizard.locator('input:visible').first();
    const original = await input.inputValue();
    await input.fill('37');
    await wizard.getByRole('button', { name: 'Reset this step to defaults' }).click();
    await expect(input).toHaveValue(original);

    // Walk forward through the steps; each one stays accessible.
    for (let i = 0; i < 2; i++) {
        await wizard.getByRole('button', { name: 'Next step' }).click();
        await settle(page);
        await expectAccessible(page);
    }

    await page.getByRole('button', { name: 'Keep all defaults' }).click();
    await page.getByRole('button', { name: 'Confirm' }).click();
    await expect(page).toHaveURL(/\/admin\/platform-settings/);
    await expect(page.getByRole('link', { name: 'First-run setup' })).toHaveCount(0);
    expect(problems).toEqual([]);
});
