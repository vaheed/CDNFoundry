import { expect, test } from '@playwright/test';
import { adminPassword, settle, signIn } from './helpers';

// The browser stack is seeded with domains, edges, and users, so it is not a fresh install:
// the first-run wizard must be hidden from the administrator and refuse direct visits.
// The wizard itself (defaults, reset, finish, skip) is covered on a pristine database by FirstRunSetupTest.
test('first-run setup is not offered once the system has data', async ({ page }) => {
    const problems: string[] = [];
    page.on('pageerror', (e) => problems.push(e.message));
    await signIn(page, 'admin', 'admin@browser.test', adminPassword);
    await settle(page);
    await expect(page.getByRole('link', { name: 'First-run setup' })).toHaveCount(0);

    const response = await page.goto('/admin/first-run-setup');
    expect(response?.status()).toBe(403);
    expect(problems).toEqual([]);
});
