import { expect, type Page, type APIRequestContext } from '@playwright/test';
import { readFileSync, readdirSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const grafanaDirectory = join(here, '..', '..', '..', 'docker', 'grafana');

export const access = JSON.parse(readFileSync(join(grafanaDirectory, 'access.json'), 'utf8')) as {
    teams: Record<string, { edit: boolean; folders: string[]; home: string }>;
    playlists: Record<string, string[]>;
};

export type Dashboard = { uid: string; title: string; folder: string; panels: { type: string; title: string; libraryPanel?: { uid: string } }[]; templating: { list: { name: string }[] } };

export function dashboards(): Dashboard[] {
    const root = join(grafanaDirectory, 'dashboards');
    return readdirSync(root, { withFileTypes: true })
        .filter((entry) => entry.isDirectory())
        .flatMap((entry) => readdirSync(join(root, entry.name)).filter((file) => file.endsWith('.json')).map((file) => ({ ...JSON.parse(readFileSync(join(root, entry.name, file), 'utf8')), folder: entry.name }) as Dashboard));
}

export const adminUser = process.env.GRAFANA_ADMIN_USER ?? 'admin';
export const adminPassword = process.env.GRAFANA_ADMIN_PASSWORD ?? 'cdnf-grafana-dev-only';
export const teamPassword = process.env.GRAFANA_TEST_USER_PASSWORD ?? 'cdnf-grafana-team-users-dev-only';

export async function signIn(page: Page, user: string, password: string): Promise<void> {
    await page.goto('/login');
    await page.getByTestId('data-testid Username input field').fill(user);
    await page.getByTestId('data-testid Password input field').fill(password);
    await page.getByTestId('data-testid Login button').click();
    await expect(page.getByTestId('data-testid Username input field')).toHaveCount(0, { timeout: 30_000 });
}

export async function signInRequest(request: APIRequestContext, user: string, password: string): Promise<void> {
    const response = await request.post('/login', { data: { user, password } });
    expect(response.ok(), `sign-in as ${user}`).toBeTruthy();
}

export function screenshotPath(project: string, range: string, uid: string): string {
    const directory = join('test-results', 'grafana-screens', project, range);
    mkdirSync(directory, { recursive: true });
    return join(directory, `${uid}.png`);
}

/** Wait until every panel has finished loading, the way a person waits for the spinners to stop. */
export async function waitForPanels(page: Page): Promise<void> {
    const loading = page.locator('[data-testid="data-testid Panel loading bar"], [aria-label="Panel loading bar"]');
    const settled = async () => {
        await expect(loading).toHaveCount(0, { timeout: 90_000 });
        await expect(page.getByRole('button', { name: 'Cancel' })).toHaveCount(0, { timeout: 90_000 });
    };
    await page.waitForLoadState('domcontentloaded');
    await settled();
    // Grafana draws a panel only when it is in view, and scrolls the dashboard inside its own container. A viewport as tall
    // as that container's content draws every panel at once, so each is queried, checked, and in the screenshot.
    const width = page.viewportSize()?.width ?? 1440;
    for (let attempt = 0; attempt < 4; attempt++) {
        const overflow = await page.evaluate(() => Math.max(0, ...Array.from(document.querySelectorAll<HTMLElement>('body, body *'))
            .filter((element) => /(auto|scroll)/.test(getComputedStyle(element).overflowY) && element.scrollHeight > element.clientHeight + 1)
            .map((element) => element.scrollHeight - element.clientHeight)));
        if (overflow === 0) break;
        const height = page.viewportSize()?.height ?? 900;
        await page.setViewportSize({ width, height: Math.min(height + overflow + 40, 16_000) });
        await page.waitForTimeout(500);
        await settled();
    }
    await page.waitForTimeout(1000);
    await settled();
}

/** Titles of the panels drawn on the page. */
export async function drawnPanelTitles(page: Page): Promise<string[]> {
    return page.locator('section h2').evaluateAll((titles) => titles.filter((title) => (title as HTMLElement).offsetParent !== null).map((title) => (title.textContent ?? '').trim()));
}
