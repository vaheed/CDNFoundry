import { test } from '@playwright/test';
import { adminUser, adminPassword, signIn, waitForPanels, drawnPanelTitles, dashboards } from './helpers';
test('debug scroll', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop');
    await signIn(page, adminUser, adminPassword);
    await page.goto('/d/cdnf-now?orgId=1&from=now-6h&to=now&refresh=');
    await waitForPanels(page);
    console.log('viewport', JSON.stringify(page.viewportSize()), 'body', await page.evaluate(() => [document.body.scrollHeight, document.body.clientHeight]));
    const drawn = await drawnPanelTitles(page);
    const defined = dashboards().find((d) => d.uid === 'cdnf-now')!.panels.filter((p) => p.type !== 'row').map((p) => p.title);
    console.log('drawn', drawn.length, 'defined', defined.length, 'missing', JSON.stringify(defined.filter((t) => !drawn.includes(t))));
    console.log('all h2', JSON.stringify(await page.locator('h2').allTextContents()));
    await page.screenshot({ path: 'test-results/debug-now.png' });
});
