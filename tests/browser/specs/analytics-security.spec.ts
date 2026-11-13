import { expect, test } from '@playwright/test';
import { expectAccessible, settle, signIn, userPassword } from './helpers';

// The Security card on the analytics page: every request by attack score band, action, country, network, path, and user agent.

test('the security card shows each breakdown with its mitigated share', async ({ page }) => {
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/analytics');
    await settle(page);
    const card = page.getByTestId('analytics-security');
    await expect(card.getByRole('heading', { name: 'Security' })).toBeVisible();
    // Attack score bands, with the scale explained.
    await expect(card.getByText('The attack score runs from 1 (attack) to 99 (clean).')).toBeVisible();
    const bands = card.locator('table tbody tr');
    await expect(bands).toHaveCount(4);
    await expect(bands.nth(1)).toContainText('1 to 19: attack');
    await expect(bands.nth(1)).toContainText('100.0%');
    await expect(bands.nth(3)).toContainText('80 to 99: clean');
    await expect(bands.nth(3)).toContainText('0.0%');
    await page.getByTestId('analytics-security-actions').click();
    await settle(page);
    await expect(card.locator('table tbody tr').filter({ hasText: 'Blocked' })).toContainText('725');
    await page.getByTestId('analytics-security-asns').click();
    await settle(page);
    await expect(card.locator('table tbody tr').filter({ hasText: 'AS15169' })).toContainText('540');
    await expect(card.locator('table tbody tr').filter({ hasText: 'Unknown network' })).toBeVisible();
    await page.getByTestId('analytics-security-agents').click();
    await settle(page);
    await expect(card.getByText('Paths and user agents cover the last 24 hours')).toBeVisible();
    await expect(card.locator('table tbody tr').filter({ hasText: 'No user agent' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await expectAccessible(page);
});
