import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';
import { expectAccessible, settle, signIn, userPassword } from './helpers';

// Documentation captures use the same disposable seeded stack as the panel suite.
const output = resolve(dirname(fileURLToPath(import.meta.url)), '../../../docs/public/screenshots');

test('documentation domain features use the current tabs', async ({ page }, info) => {
    test.skip(!info.project.name.endsWith('-desktop'), 'documentation captures use desktop width');
    test.setTimeout(120_000);
    const scheme = info.project.name.startsWith('dark') ? 'dark' : 'light';
    await signIn(page, 'app', 'user@browser.test', userPassword);
    await page.goto('/app/domains');
    await settle(page);
    await page.locator('table tbody tr a').first().click();
    await settle(page);
    mkdirSync(`${output}/${scheme}`, { recursive: true });
    for (const [tab, name] of [
        ['Overview', 'app-domain-overview'],
        ['DNS records', 'app-dns-records'],
        ['Firewall rules', 'app-firewall-rules'],
        ['Rules', 'app-request-rules'],
    ]) {
        await page.getByRole('tab', { name: new RegExp(`^${tab}`) }).click();
        await settle(page);
        await expect(page.getByRole('tab', { name: new RegExp(`^${tab}`) })).toHaveAttribute('aria-selected', 'true');
        await expectAccessible(page);
        await page.screenshot({ path: `${output}/${scheme}/${name}.png` });
    }
});
