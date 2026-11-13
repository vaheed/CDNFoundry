import { expect, test } from '@playwright/test';

/*
 * Roadmap frontend rules for the Fleet web: every text, number, URL, search, and text-area input shows an example
 * placeholder, and every field whose meaning is not obvious from its label carries a hint line or a title. The crawl
 * opens every page and every collapsed section, so a form inside a closed section is checked too. Nothing is submitted.
 * Behavior does not depend on theme or width, so only the light desktop project runs it.
 */

const pages = ['/', '/nodes', '/inventory', '/security', '/upgrade', '/config', '/rollback', '/backups', '/ha', '/secrets', '/history', '/nodes/pop-tehran-a'];

const OBVIOUS_LABELS = new Set([
    'name', 'host', 'address', 'ssh user', 'ssh port', 'region', 'public ipv4', 'country code', 'continent code', 'role', 'node', 'secret',
    'filter the log', 'monitoring', 'central logs', 'backups', 'layout', 'monitoring host', 'log host', 'control node',
]);

test.beforeEach(({}, info) => {
    test.skip(info.project.name !== 'light-desktop', 'the crawl does not depend on theme or width');
});

for (const path of pages) {
    test(`every input on ${path} has an example and every unclear field has help`, async ({ page }) => {
        await page.goto(path);
        await page.evaluate(() => document.querySelectorAll('details').forEach((details) => ((details as HTMLDetailsElement).open = true)));
        const findings = await page.evaluate((obvious) => {
            const out: string[] = [];
            const labelText = (input: Element) => {
                const label = input.closest('label');
                const clone = label?.cloneNode(true) as HTMLElement | undefined;
                clone?.querySelectorAll('input, textarea, select, .hint').forEach((child) => child.remove());
                return (clone?.textContent ?? input.getAttribute('aria-label') ?? (input as HTMLInputElement).name).replace(/\s+/g, ' ').trim();
            };
            for (const element of document.querySelectorAll('input, textarea, select')) {
                const field = element as HTMLInputElement;
                const type = (field.getAttribute('type') ?? 'text').toLowerCase();
                if (['hidden', 'checkbox', 'radio', 'submit', 'button'].includes(type) || field.readOnly || field.disabled) continue;
                const label = labelText(field);
                if (field.tagName !== 'SELECT' && (field.getAttribute('placeholder') ?? '').trim() === '') out.push(`input "${label}" has no example placeholder`);
                const text = label.toLowerCase();
                const wrapper = field.closest('label');
                const helped = wrapper?.querySelector('.hint') || wrapper?.getAttribute('title') || field.getAttribute('title') || field.getAttribute('aria-describedby');
                if (!helped && !obvious.includes(text) && text.length <= 60) out.push(`field "${label}" has no hint`);
            }
            return out;
        }, [...OBVIOUS_LABELS]);
        expect([...new Set(findings)]).toEqual([]);
    });
}
