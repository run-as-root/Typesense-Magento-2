import { test as setup, expect } from '@playwright/test';

const ADMIN_USER = process.env.ADMIN_USER || 'david';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'Admin12345!';
const BASE_URL = process.env.BASE_URL || 'https://app.mage-os-typesense.test';
const ADMIN_URL = process.env.ADMIN_URL || `${BASE_URL}/backend/admin/`;

setup('authenticate as admin', async ({ page }) => {
  setup.setTimeout(120_000);

  await page.goto(ADMIN_URL, { waitUntil: 'networkidle', timeout: 60_000 });

  // Wait for the login form to be ready
  const usernameField = page.locator('#username');
  await expect(usernameField).toBeVisible({ timeout: 30_000 });

  await usernameField.fill(ADMIN_USER);
  await page.locator('#login').fill(ADMIN_PASSWORD);
  await page.locator('.action-login').click();

  // Wait for admin dashboard to load
  await expect(page.locator('.page-wrapper')).toBeVisible({ timeout: 60_000 });

  await page.context().storageState({ path: '.auth/admin.json' });
});
