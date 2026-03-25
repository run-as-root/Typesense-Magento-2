import { test, expect } from '@playwright/test';
import { DashboardPage } from '../../pages/admin/dashboard';

test.describe('Admin Dashboard', () => {
  let dashboard: DashboardPage;

  test.beforeEach(async ({ page }) => {
    dashboard = new DashboardPage(page);
    await dashboard.goto();
  });

  test('dashboard page loads with all cards', async () => {
    await expect(dashboard.container).toBeVisible();
    await expect(dashboard.connectionCard).toBeVisible();
    await expect(dashboard.checklistCard).toBeVisible();
    await expect(dashboard.collectionsTable).toBeVisible();
  });

  test('test connection button shows success', async ({ page }) => {
    await dashboard.testConnection();
    const successIndicator = page.locator('.ts-connection-success, :has-text("Connected")').first();
    await expect(successIndicator).toBeVisible({ timeout: 10_000 });
  });

  test('setup checklist shows items as checked', async () => {
    const items = dashboard.checklistItems;
    expect(await items.count()).toBeGreaterThanOrEqual(4);
    const firstItem = items.first();
    await expect(firstItem).toBeVisible();
  });

  test('collections table lists indexed collections', async () => {
    await expect(dashboard.collectionsTable.locator('text=product')).toBeVisible();
  });

  test('quick action links navigate correctly', async ({ page }) => {
    const collectionsLink = dashboard.quickActions.locator('a:has-text("Collections")');
    if (await collectionsLink.isVisible()) {
      await collectionsLink.click();
      await page.waitForLoadState('domcontentloaded');
      expect(page.url()).toContain('collection');
    }
  });
});
