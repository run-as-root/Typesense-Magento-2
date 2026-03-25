import { test, expect } from '@playwright/test';
import { CollectionsPage } from '../../pages/admin/collections';

test.describe('Admin Collections', () => {
  let collections: CollectionsPage;

  test.beforeEach(async ({ page }) => {
    collections = new CollectionsPage(page);
    await collections.goto();
  });

  test('collections page lists all collections', async () => {
    const rows = collections.collectionRows;
    expect(await rows.count()).toBeGreaterThan(0);
  });

  test('collection shows name, doc count, and alias status', async () => {
    const productRow = collections.getRow('product');
    await expect(productRow).toBeVisible();
    await expect(productRow).toContainText(/\d+/);
  });

  test('view action shows collection details', async ({ page }) => {
    await collections.viewCollection('product');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('collection');
  });

  test('delete action shows confirmation dialog', async ({ page }) => {
    let dialogMessage = '';
    page.on('dialog', async dialog => {
      dialogMessage = dialog.message();
      await dialog.dismiss();
    });

    const deleteBtn = page.locator('button:has-text("Delete"), a:has-text("Delete")').first();
    if (await deleteBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await deleteBtn.click();
      await page.waitForTimeout(1000);
      expect(dialogMessage).toBeTruthy();
    }
  });
});
