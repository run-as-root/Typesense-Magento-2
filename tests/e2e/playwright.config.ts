import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env.BASE_URL || 'https://app.mage-os-typesense.test';
// NOTE: this must be the admin frontName root ("/backend/") without a trailing "admin/" segment.
// Playwright resolves a *relative* (no leading slash) goto() path against this baseURL by simple
// path concatenation, so any extra "admin/" here would land every admin page object one directory
// too deep (e.g. "/backend/admin/catalog/category/edit/..." instead of the real
// "/backend/catalog/category/edit/..." route) and any *leading-slash* goto() path resolves against
// the origin root regardless of this value. Confirmed against a live Warden instance: navigating
// admin page objects relative to "/backend/admin/" 404s or bounces back to the login screen.
const ADMIN_URL = process.env.ADMIN_URL || `${BASE_URL}/backend/`;
const HYVA_AVAILABLE = !!process.env.HYVA_AVAILABLE;

const projects: any[] = [
  {
    name: 'admin-auth',
    testDir: './fixtures',
    testMatch: /auth\.setup\.ts/,
    // Must use the same device/user-agent profile as the dependent "admin" project below.
    // Magento's session validator (web/session/use_http_user_agent, enabled by default) ties the
    // admin session cookie to the user agent that created it — without this, the "admin" project's
    // devices['Desktop Chrome'] context presents a different UA than this setup project's browser
    // default, and Magento invalidates the session on the very first navigation, bouncing every
    // admin test back to the login screen regardless of which URL it requested.
    use: { ...devices['Desktop Chrome'] },
  },
  {
    name: 'admin',
    testDir: './tests/admin',
    dependencies: ['admin-auth'],
    use: {
      ...devices['Desktop Chrome'],
      storageState: '.auth/admin.json',
      baseURL: ADMIN_URL,
    },
  },
];

// Frontend tests require Hyva theme (Alpine.js components)
// Skip in CI unless HYVA_AVAILABLE=true is set
if (HYVA_AVAILABLE || !process.env.CI) {
  projects.push({
    name: 'frontend',
    testDir: './tests/frontend',
    use: { ...devices['Desktop Chrome'] },
  });
}

export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
  ],
  timeout: 60_000,
  expect: {
    timeout: 10_000,
  },
  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: true,
    navigationTimeout: 30_000,
  },
  projects,
});
