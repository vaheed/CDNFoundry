import { defineConfig } from '@playwright/test';
import fleet from './playwright.fleet.config';

export default defineConfig({
    ...fleet,
    testIgnore: [],
    testMatch: '**/docs-screenshots.spec.ts',
});
