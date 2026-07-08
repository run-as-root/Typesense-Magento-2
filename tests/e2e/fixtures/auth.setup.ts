import { test as setup, expect } from '@playwright/test';

const ADMIN_USER = process.env.ADMIN_USER || 'david';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'Admin12345!';
const BASE_URL = process.env.BASE_URL || 'https://app.mage-os-typesense.test';
// Must match playwright.config.ts's ADMIN_URL: the admin frontName root without a trailing
// "admin/" segment (see that file for why the extra segment breaks every relative admin goto()).
const ADMIN_URL = process.env.ADMIN_URL || `${BASE_URL}/backend/`;

setup('authenticate as admin', async ({ page }) => {
  setup.setTimeout(120_000);

  await page.goto(ADMIN_URL, { waitUntil: 'networkidle', timeout: 60_000 });

  // Wait for the login form to be ready
  const usernameField = page.locator('#username');
  await expect(usernameField).toBeVisible({ timeout: 30_000 });

  await usernameField.fill(ADMIN_USER);
  await page.locator('#login').fill(ADMIN_PASSWORD);
  await page.locator('.action-login').click();

  // NOTE: don't assert on ".page-wrapper" here — Magento's login page itself is wrapped in a
  // "<section class=\"page-wrapper\">" (adminhtml-auth-login body), so that locator is visible
  // whether or not the login actually succeeded. That false positive let every failed login in
  // this suite silently "pass" while quietly burning through admin_user.failures_num until the
  // account locked itself out. Assert on the body's login-page class disappearing instead, and
  // fail loudly with the real error message if it's still there.
  const loginError = page.locator('.message-error, [data-ui-id="messages-message-error"]');
  await page.waitForFunction(
    () => !document.body.classList.contains('adminhtml-auth-login'),
    undefined,
    { timeout: 60_000 },
  ).catch(() => {});

  if (await page.evaluate(() => document.body.classList.contains('adminhtml-auth-login'))) {
    const message = (await loginError.count()) > 0 ? await loginError.first().innerText() : '(no error message found)';
    throw new Error(`Admin login failed, still on login page: ${message}`);
  }

  await page.context().storageState({ path: '.auth/admin.json' });
});
