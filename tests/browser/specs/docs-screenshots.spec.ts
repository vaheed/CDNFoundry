import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from '@playwright/test';
import { adminPassword, settle, signIn, userPassword } from './helpers';

// Captures the screenshots the documentation shows, from the seeded browser stack, in both themes.
// The files land in docs/public/screenshots and are never edited by hand. Only the desktop projects run.
const output = resolve(dirname(fileURLToPath(import.meta.url)), '../../../docs/public/screenshots');

const scenes = [
    { name: 'admin-overview', panel: 'admin', path: '/admin' },
    { name: 'admin-domains', panel: 'admin', path: '/admin/domains' },
    { name: 'admin-edges', panel: 'admin', path: '/admin/edges' },
    { name: 'admin-edge-pools', panel: 'admin', path: '/admin/edge-pools' },
    { name: 'admin-dns-clusters', panel: 'admin', path: '/admin/dns-clusters' },
    { name: 'admin-operations', panel: 'admin', path: '/admin/operations' },
    { name: 'admin-telemetry', panel: 'admin', path: '/admin/telemetry' },
    { name: 'admin-platform-settings', panel: 'admin', path: '/admin/platform-settings' },
    { name: 'app-domains', panel: 'app', path: '/app/domains' },
    { name: 'app-analytics', panel: 'app', path: '/app/analytics' },
] as const;

for (const scene of scenes) {
    test(`documentation screenshot ${scene.name}`, async ({ page }, info) => {
        test.skip(!info.project.name.endsWith('-desktop'), 'documentation screenshots use the desktop width');
        const scheme = info.project.name.startsWith('dark') ? 'dark' : 'light';
        if (scene.panel === 'admin') {
            await signIn(page, 'admin', 'admin@browser.test', adminPassword);
        } else {
            await signIn(page, 'app', 'user@browser.test', userPassword);
        }
        await page.goto(scene.path);
        await settle(page);
        mkdirSync(`${output}/${scheme}`, { recursive: true });
        await page.screenshot({ path: `${output}/${scheme}/${scene.name}.png` });
    });
}

// The social card search engines and link previews show: the SVG card rendered at 1200 by 630, since they do not read SVG.
test('documentation social card', async ({ page }, info) => {
    test.skip(info.project.name !== 'light-desktop', 'the card is rendered once');
    await page.setViewportSize({ width: 1200, height: 630 });
    await page.setContent(readFileSync(resolve(output, '../social-card.svg'), 'utf8'));
    mkdirSync(output, { recursive: true });
    await page.screenshot({ path: `${output}/social-card.png`, clip: { x: 0, y: 0, width: 1200, height: 630 } });
});

// The release these screenshots were taken for. scripts/ci/check_no_screenshots.py fails when it differs from VERSION.
test('documentation screenshots record their release', async () => {
    const version = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), '../../../VERSION'), 'utf8').trim();
    mkdirSync(output, { recursive: true });
    writeFileSync(`${output}/release.txt`, `${version}\n`);
});
