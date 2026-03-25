import { test, expect } from '@playwright/test';
import { CategoryPage } from '../../pages/frontend/category-page';

test.describe('Category Page', () => {
  let categoryPage: CategoryPage;

  test.beforeEach(async ({ page }) => {
    categoryPage = new CategoryPage(page);
    await categoryPage.goto('/gear/bags.html');
  });

  test('loads with Typesense-powered listing', async () => {
    await expect(categoryPage.container).toBeVisible();
  });

  test('displays products for category', async () => {
    const cards = await categoryPage.getProductCards();
    await expect(cards.first()).toBeVisible();
    expect(await cards.count()).toBeGreaterThan(0);
  });

  test('facet filters appear and work', async ({ page }) => {
    await expect(categoryPage.facets).toBeVisible();
    const firstCheckbox = categoryPage.facets.locator('.ts-facet-checkbox').first();
    if (await firstCheckbox.isVisible({ timeout: 3000 }).catch(() => false)) {
      const statsBefore = await categoryPage.stats.textContent();
      await firstCheckbox.click();
      await page.waitForTimeout(1500);
      const statsAfter = await categoryPage.stats.textContent();
      expect(statsAfter).toContain('products');
    }
  });

  test('sort options work', async () => {
    await expect(categoryPage.sortDropdown).toBeVisible();
    const options = await categoryPage.sortDropdown.locator('option').count();
    expect(options).toBeGreaterThan(1);
    await categoryPage.selectSort('price:asc');
    await expect((await categoryPage.getProductCards()).first()).toBeVisible();
  });

  test('pagination works when enough products', async ({ page }) => {
    const nextBtn = categoryPage.nextButton;
    if (await nextBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await nextBtn.click();
      await page.waitForTimeout(1500);
      await expect(categoryPage.pagination.locator('span')).toContainText('2 /');
    }
  });

  test('price range slider works', async ({ page }) => {
    if (await categoryPage.priceApplyButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await categoryPage.priceApplyButton.click();
      await page.waitForTimeout(1500);
      const stats = await categoryPage.stats.textContent();
      expect(stats).toContain('products');
    }
  });
});
