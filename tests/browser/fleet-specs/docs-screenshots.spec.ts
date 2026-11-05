import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from '@playwright/test';

// Captures the Fleet web screenshots the documentation shows, from the disposable fixture vault, in both themes.
// The files land in docs/public/screenshots and are never edited by hand. Only the desktop projects run.
const output = resolve(dirname(fileURLToPath(import.meta.url)), '../../../docs/public/screenshots');

const scenes = [['overview', '/'], ['nodes', '/nodes']] as const;

for (const [name, path] of scenes) {
    test(`documentation screenshot fleet ${name}`, async ({ page }, info) => {
        test.skip(!info.project.name.endsWith('-desktop'), 'documentation screenshots use the desktop width');
        const scheme = info.project.name.startsWith('dark') ? 'dark' : 'light';
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        mkdirSync(`${output}/${scheme}`, { recursive: true });
        await page.screenshot({ path: `${output}/${scheme}/fleet-${name}.png` });
    });
}
