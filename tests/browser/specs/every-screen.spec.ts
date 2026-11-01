import { expect, test } from '@playwright/test';
import { adminPassword, expectAccessible, settle, signIn, userPassword } from './helpers';

// Every screen of both panels loads without a server error and passes the accessibility check.
const adminScreens = [
    '/admin', '/admin/telemetry', '/admin/operations', '/admin/audit-logs', '/admin/users', '/admin/domains',
    '/admin/edges', '/admin/edge-pools', '/admin/dns-clusters', '/admin/system-dns-identity', '/admin/platform-settings',
    '/admin/profile', '/admin/tokens', '/admin/users/create', '/admin/edges/create', '/admin/edge-pools/create',
    '/admin/dns-clusters/create', '/admin/topology',
];
const appScreens = ['/app', '/app/domains', '/app/analytics', '/app/profile', '/app/tokens'];

for (const path of adminScreens) {
    test(`admin ${path}`, async ({ page }) => {
        await signIn(page, 'admin', 'admin@browser.test', adminPassword);
        const response = await page.goto(path);
        expect(response?.status()).toBeLessThan(400);
        await settle(page);
        await expectAccessible(page);
    });
}

for (const path of appScreens) {
    test(`app ${path}`, async ({ page }) => {
        await signIn(page, 'app', 'user@browser.test', userPassword);
        const response = await page.goto(path);
        expect(response?.status()).toBeLessThan(400);
        await settle(page);
        await expectAccessible(page);
    });
}
