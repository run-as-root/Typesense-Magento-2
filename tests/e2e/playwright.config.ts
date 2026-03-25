import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env.BASE_URL || 'https://app.mage-os-typesense.test';
const ADMIN_URL = process.env.ADMIN_URL || `${BASE_URL}/backend/admin/`;
const HYVA_AVAILABLE = !!process.env.HYVA_AVAILABLE;

const projects: any[] = [
  {
    name: 'admin-auth',
    testDir: './fixtures',
    testMatch: /auth\.setup\.ts/,
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
