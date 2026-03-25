import { test, expect } from '@playwright/test';
import { ProductPage } from '../../pages/frontend/product-page';

test.describe('Product Detail Page', () => {
  test('recommendations section hidden when feature disabled', async ({ page }) => {
    const productPage = new ProductPage(page);
    await productPage.goto('/joust-duffle-bag.html');
    await expect(productPage.recommendationsWrapper).not.toBeVisible();
  });

  test('PDP loads without JS errors when recommendations disabled', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));

    const productPage = new ProductPage(page);
    await productPage.goto('/joust-duffle-bag.html');
    await page.waitForTimeout(2000);

    const tsErrors = errors.filter(e => e.toLowerCase().includes('typesense') || e.toLowerCase().includes('recommendation'));
    expect(tsErrors).toHaveLength(0);
  });
});
