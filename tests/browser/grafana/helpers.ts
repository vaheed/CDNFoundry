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
    await page.waitForLoadState('domcontentloaded');
    await expect(loading).toHaveCount(0, { timeout: 90_000 });
    await expect(page.getByRole('button', { name: 'Cancel' })).toHaveCount(0, { timeout: 90_000 });
    // Grafana draws a panel only when it scrolls into view, so every screen of the dashboard is visited and allowed to load;
    // otherwise panels below the first screen are never queried, checked, or shown in the screenshot.
    const height = await page.evaluate(() => document.querySelector('[data-testid="data-testid dashboard controls"]')?.closest('main, .scrollbar-view, body')?.scrollHeight ?? document.body.scrollHeight);
    const viewport = page.viewportSize() ?? { width: 1440, height: 900 };
    // The pointer must be over the dashboard, not the navigation, for the wheel to scroll it.
    await page.mouse.move(Math.round(viewport.width * 0.75), Math.round(viewport.height * 0.6));
    for (let y = 0; y < Math.max(height, 8000); y += 600) {
        await page.mouse.wheel(0, 600);
        await page.waitForTimeout(250);
        await expect(loading).toHaveCount(0, { timeout: 60_000 });
    }
    await page.mouse.wheel(0, -100_000);
    await expect(page.getByRole('button', { name: 'Cancel' })).toHaveCount(0, { timeout: 90_000 });
    await page.waitForTimeout(1500);
    await expect(loading).toHaveCount(0, { timeout: 30_000 });
}
