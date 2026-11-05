import { test, expect, request as playwrightRequest } from '@playwright/test';
import { access, dashboards, signInRequest, teamPassword, adminPassword, adminUser } from './helpers';

// Each team sees its folders, only platform-admin can edit, and an anonymous visitor is refused.
const folderOf = new Map(dashboards().map((dashboard) => [dashboard.uid, dashboard.folder]));
const folderUid: Record<string, string> = {
    '00-overview': 'cdnf-folder-overview', '10-noc': 'cdnf-folder-noc', '20-soc': 'cdnf-folder-soc', '30-platform-admin': 'cdnf-folder-platform',
    '40-on-call-slo': 'cdnf-folder-oncall', '50-domains': 'cdnf-folder-domains', '60-business-capacity': 'cdnf-folder-business', '90-samples': 'cdnf-folder-samples',
};

test.describe('folder access', () => {
    test('anonymous visitors are refused', async ({ baseURL }) => {
        const anonymous = await playwrightRequest.newContext({ baseURL });
        for (const path of ['/api/search', '/api/dashboards/uid/cdnf-now', '/api/folders']) {
            const response = await anonymous.get(path);
            expect([401, 403], path).toContain(response.status());
        }
        const page = await anonymous.get('/d/cdnf-now', { maxRedirects: 0 });
        expect([302, 401, 403]).toContain(page.status());
        await anonymous.dispose();
    });

    for (const [team, settings] of Object.entries(access.teams)) {
        test(`${team} sees its folders and ${settings.edit ? 'can' : 'cannot'} edit`, async ({ baseURL }) => {
            const context = await playwrightRequest.newContext({ baseURL });
            await signInRequest(context, `${team}-user`, teamPassword);
            const search = (await (await context.get('/api/search?type=dash-db&limit=500')).json()) as { uid: string; folderUid?: string }[];
            const seen = new Set(search.map((row) => row.uid));
            for (const [uid, directory] of folderOf) {
                const allowed = settings.folders.includes(folderUid[directory]);
                expect(seen.has(uid), `${team} ${allowed ? 'sees' : 'must not see'} ${uid}`).toBe(allowed);
            }
            const probe = settings.folders.length ? [...folderOf.entries()].find(([, directory]) => settings.folders.includes(folderUid[directory]))![0] : 'cdnf-now';
            const meta = ((await (await context.get(`/api/dashboards/uid/${probe}`)).json()) as { meta: { canSave: boolean; canEdit: boolean } }).meta;
            expect(meta.canSave, `${team} edit rights`).toBe(settings.edit);
            const write = await context.post('/api/dashboards/db', { data: { dashboard: { title: `${team} attempt`, panels: [] }, folderUid: settings.folders[0], overwrite: false } });
            expect(write.ok(), `${team} must ${settings.edit ? '' : 'not '}create dashboards`).toBe(settings.edit);
            await context.dispose();
        });
    }

    test('the administrator reaches every dashboard', async ({ baseURL }) => {
        const context = await playwrightRequest.newContext({ baseURL });
        await signInRequest(context, adminUser, adminPassword);
        const search = (await (await context.get('/api/search?type=dash-db&limit=500')).json()) as { uid: string }[];
        expect(new Set(search.map((row) => row.uid))).toEqual(expect.objectContaining(new Set(dashboards().map((dashboard) => dashboard.uid))));
        await context.dispose();
    });
});
