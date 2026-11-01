import { test } from '@playwright/test';
import { adminPassword, settle, signIn } from './helpers';

// Review screenshots taken with the rich seed (not snapshot-compared): used to look for layout problems by eye.
const shots: Array<[string, string]> = [
    ['/admin/domains/1', 'detail-overview'], ['/admin/domains/1?tab=-dns-tab', 'detail-dns'], ['/admin/domains', 'domains'],
    ['/admin/edges', 'edges'], ['/admin/edge-pools', 'pools'], ['/admin/dns-clusters', 'clusters'], ['/admin/users', 'users'],
    ['/admin/operations', 'operations'], ['/admin/audit-logs', 'audit'], ['/admin/telemetry', 'telemetry'],
    ['/admin/tokens', 'tokens'], ['/admin/system-dns-identity', 'dns-identity'], ['/admin', 'overview'],
];
for (const [path, name] of shots) {
    test(`review ${name}`, async ({ page }, info) => {
        await signIn(page, 'admin', 'admin@browser.test', adminPassword);
        await page.goto(path);
        await settle(page);
        await page.screenshot({ path: `review/${info.project.name}-${name}.png`, fullPage: true });
    });
}
