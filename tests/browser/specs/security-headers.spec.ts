import { expect, test } from '@playwright/test';
import { adminPassword, signIn } from './helpers';

/*
 * The panels run under an enforced Content-Security-Policy and the API allows no cross-origin browser by default.
 * Both are checked in a real browser: the policy must name no wildcard, every panel page must run without a single
 * violation, and a cross-origin preflight must be refused.
 */
test.describe('browser security headers', () => {
    test.beforeEach(({}, testInfo) => {
        test.skip(testInfo.project.name !== 'light-desktop', 'header behavior does not depend on theme or width');
    });

    test('the sign-in page and the signed-in panel send a strict policy and raise no violation', async ({ page }) => {
        const violations: string[] = [];
        page.on('console', (message) => {
            if (/content security policy/i.test(message.text())) {
                violations.push(message.text().slice(0, 200));
            }
        });
        const response = await page.goto('/admin/login');
        const policy = response?.headers()['content-security-policy'] ?? '';
        expect(policy).toContain("default-src 'self'");
        expect(policy).toMatch(/script-src 'self' 'nonce-[A-Za-z0-9]+'/);
        expect(policy).not.toMatch(/(^|[ ;])\*([ ;]|$)/);
        expect(response?.headers()['x-powered-by']).toBeUndefined();

        await signIn(page, 'admin', 'admin@browser.test', adminPassword);
        await page.goto('/admin');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('.fi-sidebar')).toBeVisible();
        expect(violations).toEqual([]);
    });

    test('a cross-origin preflight to the API is refused and no response names a wildcard origin', async ({ request }) => {
        const preflight = await request.fetch('/api/domains', {
            method: 'OPTIONS',
            headers: { Origin: 'https://attacker.example', 'Access-Control-Request-Method': 'GET', 'Access-Control-Request-Headers': 'authorization' },
        });
        expect(preflight.headers()['access-control-allow-origin']).toBeUndefined();
        const health = await request.get('/api/health', { headers: { Origin: 'https://attacker.example' } });
        expect(health.headers()['access-control-allow-origin']).toBeUndefined();
    });
});
