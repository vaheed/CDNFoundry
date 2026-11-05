import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from '@playwright/test';
import { adminPassword, adminUser, signIn } from './helpers';

// Captures the Grafana screenshots the documentation shows, from the seeded development stack, in both themes.
// The files land in docs/public/screenshots and are never edited by hand. Only the desktop project runs.
const output = resolve(dirname(fileURLToPath(import.meta.url)), '../../../docs/public/screenshots');

const scenes = [['grafana-now', 'cdnf-now'], ['grafana-noc-wall', 'cdnf-noc-wall']] as const;

for (const scheme of ['light', 'dark'] as const) {
    for (const [name, uid] of scenes) {
        test(`documentation screenshot ${name} ${scheme}`, async ({ page }, info) => {
            test.skip(info.project.name !== 'desktop', 'documentation screenshots use the desktop width');
            await signIn(page, adminUser, adminPassword);
            await page.goto(`/d/${uid}?theme=${scheme}&kiosk`);
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(6000);
            mkdirSync(`${output}/${scheme}`, { recursive: true });
            await page.screenshot({ path: `${output}/${scheme}/${name}.png` });
        });
    }
}
