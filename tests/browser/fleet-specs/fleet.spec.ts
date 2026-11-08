import { expect, test, type Page } from '@playwright/test';
import { expectAccessible } from '../specs/helpers';

/*
 * The local fleet web: every page loads, is accessible, fits the width, and every action behaves. Nothing reaches a real
 * host: the fixture's nodes point at a closed local port, so checks fail fast the way an outage would.
 */

const pages = [
    ['overview', '/'], ['nodes', '/nodes'], ['inventory', '/inventory'], ['security', '/security'], ['upgrade', '/upgrade'], ['config', '/config'],
    ['rollback', '/rollback'], ['backups', '/backups'], ['ha', '/ha'], ['secrets', '/secrets'], ['history', '/history'],
    ['node-detail', '/nodes/pop-tehran-a'],
] as const;

function collect(page: Page): string[] {
    const problems: string[] = [];
    page.on('pageerror', (e) => problems.push(`script error: ${e.message}`));
    page.on('console', (m) => m.type() === 'error' && problems.push(`console: ${m.text()}`));
    page.on('response', (r) => r.status() >= 500 && problems.push(`${r.url()} returned ${r.status()}`));
    return problems;
}

async function noHorizontalOverflow(page: Page): Promise<void> {
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, 'the page scrolls sideways').toBeLessThanOrEqual(0);
}

for (const [name, path] of pages) {
    test(`page ${name}`, async ({ page }) => {
        const problems = collect(page);
        const response = await page.goto(path);
        expect(response?.status()).toBe(200);
        await expect(page.locator('h1')).toHaveCount(1);
        await expect(page.locator('main')).toBeVisible();
        await expectAccessible(page);
        await noHorizontalOverflow(page);
        await expect(page).toHaveScreenshot(`${name}.png`, { fullPage: true });
        expect(problems).toEqual([]);
    });
}

test('navigation reaches every page and marks the current one', async ({ page }) => {
    await page.goto('/');
    for (const [label, path] of [['Nodes', '/nodes'], ['Inventory', '/inventory'], ['Security', '/security'], ['Upgrade', '/upgrade'], ['Config', '/config'], ['Rollback', '/rollback'],
        ['Backups', '/backups'], ['HA', '/ha'], ['Secrets', '/secrets'], ['History', '/history'], ['Overview', '/']] as const) {
        await page.getByRole('navigation', { name: 'Fleet' }).getByRole('link', { name: label, exact: true }).click();
        await expect(page).toHaveURL(new RegExp(`${path === '/' ? '/$' : path}$`));
        await expect(page.locator('nav a[aria-current=page]')).toHaveText(label);
    }
});

test('keyboard users can tab to the navigation with a visible focus ring', async ({ page }) => {
    await page.goto('/');
    await page.keyboard.press('Tab');
    const outline = await page.evaluate(() => getComputedStyle(document.activeElement as Element).outlineStyle);
    expect(outline).not.toBe('none');
});

test('overview draws the topology with an accessible name and every seeded node', async ({ page }) => {
    await page.goto('/');
    const svg = page.getByRole('group', { name: /Fleet topology/ });
    await expect(svg).toBeVisible();
    await expect(svg).toHaveAttribute('aria-label', /control-1 \(control\)/);
    await expect(svg).toHaveAttribute('aria-label', /pop-frankfurt-with-a-very-long-point-of-presence-name \(edge\)/);
});

test('run check starts a job, shows its progress page, and ends with the unreachable nodes under needs attention', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText('Nothing has been checked in this session')).toBeVisible();
    await page.getByRole('button', { name: 'Run check of the whole stack' }).click();
    await expect(page).toHaveURL(/\/jobs\//);
    await expect(page.getByText('Finished')).toBeVisible({ timeout: 30_000 });
    await expectAccessible(page);
    // Every host is a task on the page, all ticked, and the progress count says so.
    await expect(page.getByTestId('job-tasks').locator('li[data-step]')).toHaveCount(6);
    await expect(page.getByTestId('job-tasks').locator('li[data-state=failed], li[data-state=ok]')).toHaveCount(6);
    await expect(page.getByTestId('job-count')).toHaveText('6 of 6 steps');
    await page.goto('/');
    await expect(page.getByText(/control-1: Host reachable/)).toBeVisible();
    await expect(page.getByText('Healthy at last check')).toBeVisible();
    await expect(page.locator('.attention li').first()).toBeVisible();
});

test('adding a node whose host cannot be reached reports the error and keeps the form usable', async ({ page }) => {
    await page.goto('/nodes');
    const form = page.locator('form[action="/nodes/add"]');
    await form.getByLabel('Name').fill('pop-new');
    await form.getByLabel('Role').selectOption('edge');
    await form.getByLabel('Public IPv4').fill('127.0.0.1');
    await form.getByLabel('SSH port').fill('1');
    await form.getByRole('button', { name: 'Add node and show plan' }).click();
    await expect(page.getByRole('alert')).toContainText(/host key|Could not read/i);
    await expectAccessible(page);
});

test('the nodes page shows the one Fleet public key to copy to a host before adding it, and never a private key', async ({ page }) => {
    await page.goto('/nodes');
    const key = page.getByLabel('Fleet SSH public key');
    await expect(key).toContainText(/^ssh-ed25519 \S+ cdnfoundry-fleet$/);
    await expect(page.getByText('before you add it here')).toBeVisible();
    await expect(page.getByLabel('SSH private key')).toHaveCount(0);
    expect(await page.content()).not.toContain('PRIVATE KEY');
    await expectAccessible(page);
});

test('node page shows the plan with the PoP enrollment step and asks for its location', async ({ page }) => {
    await page.goto('/nodes/pop-tehran-a');
    await expect(page.getByRole('listitem').filter({ hasText: 'enroll' })).toBeVisible();
    await expect(page.getByLabel('Country code')).toHaveValue('US');
    await expect(page.getByRole('button', { name: 'Install and start this node' })).toBeEnabled();
});

test('destructive actions cannot be submitted without their typed confirmation', async ({ page }) => {
    await page.goto('/secrets');
    const field = page.getByLabel(/Type ROTATE to confirm/);
    await field.fill('yes');
    await page.getByRole('button', { name: 'Rotate and deploy' }).click();
    await expect(page).toHaveURL(/\/secrets$/);
    expect(await field.evaluate((el: HTMLInputElement) => el.validity.patternMismatch)).toBe(true);
    await page.goto('/ha');
    await expect(page.getByText(/Deploy .* first|Add exactly one control node/)).toBeVisible();
});

test('restore refuses a wrong confirmation with a readable error', async ({ page }) => {
    await page.goto('/backups');
    const form = page.locator('form[action="/backups/restore"]');
    await form.getByLabel('Snapshot ID', { exact: true }).fill('a'.repeat(64));
    await form.getByLabel(/Type RESTORE/).fill('RESTORE');
    // The pattern needs the phrase and a snapshot ID, so the browser blocks a bare word before it is sent.
    await form.getByRole('button', { name: 'Restore' }).click();
    await expect(page).toHaveURL(/\/backups$/);
    expect(await form.getByLabel(/Type RESTORE/).evaluate((el: HTMLInputElement) => el.validity.patternMismatch)).toBe(true);
    // A well-formed phrase for a different snapshot passes the browser; the server wants the exact snapshot it was given.
    await form.getByLabel('Snapshot ID', { exact: true }).fill('a'.repeat(64));
    await form.getByLabel(/Type RESTORE/).fill('RESTORE ' + 'b'.repeat(64));
    await form.getByRole('button', { name: 'Restore' }).click();
    await expect(page.getByRole('alert')).toContainText('Type exactly: RESTORE ' + 'a'.repeat(64));
    await expectAccessible(page);
});

test('upgrade offers the release of the checkout with one button, and refuses an unsigned pasted manifest', async ({ page }) => {
    await page.goto('/upgrade');
    await expect(page.getByRole('button', { name: /^Use release .* for the first install$/ })).toBeVisible();
    await expect(page.getByLabel('Release public key (PEM)')).toBeHidden();
    await page.getByText('I sign releases myself').click();
    await page.getByLabel('Signed release manifest (JSON)').fill('{"manifest": {"release": "v9"}}');
    await page.getByRole('button', { name: 'Use this manifest' }).click();
    await expect(page.getByRole('alert')).toContainText(/signed|release/);
    await expectAccessible(page);
});

test('history lists the seeded action with its operator', async ({ page }) => {
    await page.goto('/history');
    await expect(page.getByText('fixture: Seed the browser fixture')).toBeVisible();
});

test('a link or button on every page leads somewhere that exists', async ({ page, request }) => {
    for (const [, path] of pages) {
        await page.goto(path);
        const hrefs = await page.locator('main a[href]').evaluateAll((links) => links.map((l) => (l as HTMLAnchorElement).getAttribute('href') ?? ''));
        for (const href of hrefs.filter((h) => h.startsWith('/'))) {
            expect((await request.get(href)).status(), `${path} links to ${href}`).toBe(200);
        }
    }
});


test('the action page filters its log and offers the log as a download', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: 'Run check of the whole stack' }).click();
    await expect(page.getByText('Finished')).toBeVisible({ timeout: 30_000 });
    const log = page.getByTestId('job-log');
    await expect(log).toContainText('control-1:');
    await page.getByLabel('Filter the log').fill('monitoring-1');
    await expect(log).toContainText('monitoring-1');
    await expect(log).not.toContainText('dns-2');
    await page.getByLabel('Filter the log').fill('no such line');
    await expect(log).toHaveText('No log line matches the filter.');
    await expect(page.getByRole('link', { name: 'Download log' })).toHaveAttribute('href', /\/jobs\/\w+\/log\.txt$/);
});

test('once the result of an action was opened, no page keeps announcing it', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: 'Run check of the whole stack' }).click();
    await expect(page).toHaveURL(/\/jobs\//);
    await expect(page.getByText('Finished')).toBeVisible({ timeout: 30_000 });
    await page.goto('/nodes');
    await expect(page.getByTestId('running-banner')).toHaveCount(0);
    await expect(page.getByTestId('finished-banner')).toHaveCount(0);
});

test('inventory asks for a first read and the nodes page lists the live columns', async ({ page }) => {
    await page.goto('/inventory');
    await expect(page.getByText('has not read any host')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Read hardware of every host' })).toBeVisible();
    await page.goto('/nodes');
    await expect(page.getByRole('columnheader', { name: 'Memory' })).toBeVisible();
    await expect(page.getByRole('columnheader', { name: 'Cores' })).toBeVisible();
});

test('every topology host links to its page', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('link', { name: /^pop-tehran-a, / }).click();
    await expect(page).toHaveURL(/\/nodes\/pop-tehran-a$/);
    await expect(page.getByRole('heading', { name: 'Hardware and system' })).toBeVisible();
});
