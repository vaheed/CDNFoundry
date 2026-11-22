import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

// Captures the Fleet web screenshots the documentation shows, from the disposable fixture vault, in both themes.
// The files land in docs/public/screenshots and are never edited by hand. Only the desktop projects run.
const output = resolve(dirname(fileURLToPath(import.meta.url)), '../../../docs/public/screenshots');

const scenes = [['overview', '/'], ['nodes', '/nodes'], ['backups', '/backups']] as const;

for (const [name, path] of scenes) {
    test(`documentation screenshot fleet ${name}`, async ({ page }, info) => {
        test.skip(!info.project.name.endsWith('-desktop'), 'documentation screenshots use the desktop width');
        const scheme = info.project.name.startsWith('dark') ? 'dark' : 'light';
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        if (name === 'backups') {
            await page.getByRole('button', { name: 'Refresh backups' }).click();
            await expect(page.getByText('Finished', { exact: true })).toBeVisible({ timeout: 30_000 });
            await page.goto('/backups');
            await expect(page.getByRole('region', { name: 'Recent backups' })).toContainText('Succeeded');
        }
        mkdirSync(`${output}/${scheme}`, { recursive: true });
        await page.screenshot({ path: `${output}/${scheme}/fleet-${name}.png` });
    });
}
