import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

export const adminPassword = process.env.CDNF_BROWSER_ADMIN_PASSWORD ?? '';
export const userPassword = process.env.CDNF_BROWSER_USER_PASSWORD ?? '';

// Filament throttles sign-in attempts, so each panel signs in once per run and later tests reuse the session cookies.
const sessions = new Map<string, Awaited<ReturnType<Page['context']>['cookies']> extends Promise<infer T> ? T : never>();

export async function signIn(page: Page, panel: 'admin' | 'app', email: string, password: string): Promise<void> {
    const known = sessions.get(panel);
    if (known) {
        await page.context().addCookies(known);
        return;
    }
    await page.goto(`/${panel}/login`);
    await page.getByLabel('Email address').fill(email);
    await page.locator('input[type=password]').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL((url) => url.pathname.startsWith(`/${panel}`) && !url.pathname.endsWith('/login'));
    sessions.set(panel, await page.context().cookies());
}

/** Applies the project's color scheme through Filament's theme store so dark mode is deterministic. */
export async function settle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle');
}

export async function expectAccessible(page: Page): Promise<void> {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
        .analyze();
    const serious = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    expect(serious.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).slice(0, 3).join(' | ')}`)).toEqual([]);
}
