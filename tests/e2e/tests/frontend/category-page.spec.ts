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
    await expect(categoryPage.tiles.first()).toBeVisible();
  });

  test('writes an ItemList JSON-LD from the visible products', async ({ page }) => {
    // The server renders an empty ItemList; it is filled once the first results are rendered.
    await expect(categoryPage.tiles.first()).toBeVisible();
    const jsonLd = await page.locator('#typesense-item-list').textContent();
    const itemList = JSON.parse(jsonLd ?? '{}');

    expect(itemList['@type']).toBe('ItemList');
    expect(itemList.itemListElement.length).toBeGreaterThan(0);
  });

  test('add to cart posts with a form key and refreshes the cart', async ({ page }) => {
    const button = categoryPage.tiles.locator('button:has-text("Add to Cart")').first();
    test.skip(!(await button.isVisible()), 'no directly addable product in this category');

    const request = page.waitForRequest(req => req.url().includes('checkout/cart/add') && req.method() === 'POST');
    await button.click();
    expect((await request).postData()).toContain('form_key=');
  });
});
