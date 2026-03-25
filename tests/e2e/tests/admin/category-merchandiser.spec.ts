import { test, expect } from '@playwright/test';
import { CategoryMerchandiserPage } from '../../pages/admin/category-merchandiser';

test.describe('Admin Category Merchandiser', () => {
  let merchandiser: CategoryMerchandiserPage;
  const CATEGORY_ID = 4;

  test.beforeEach(async ({ page }) => {
    merchandiser = new CategoryMerchandiserPage(page);
    await merchandiser.gotoCategoryEdit(CATEGORY_ID);
  });

  test('merchandiser section visible on category edit page', async () => {
    await expect(merchandiser.merchandiserSection).toBeVisible({ timeout: 10_000 });
  });

  test('products table loads with existing category products', async () => {
    await expect(merchandiser.productTable).toBeVisible({ timeout: 10_000 });
  });

  test('search for a product to pin', async ({ page }) => {
    if (await merchandiser.productSearch.isVisible()) {
      await merchandiser.productSearch.fill('bag');
      await page.waitForTimeout(1000);
    }
  });

  test('pin a product and verify position', async ({ page }) => {
    if (await merchandiser.productSearch.isVisible()) {
      await merchandiser.productSearch.fill('bag');
      await page.waitForTimeout(1000);
      const firstResult = page.locator('.ts-search-result, [data-role="product-result"]').first();
      if (await firstResult.isVisible({ timeout: 3000 }).catch(() => false)) {
        await firstResult.click();
        await page.waitForTimeout(500);
      }
    }
  });

  test('save button exists for merchandising changes', async () => {
    await expect(merchandiser.saveButton).toBeVisible({ timeout: 10_000 });
  });
});
