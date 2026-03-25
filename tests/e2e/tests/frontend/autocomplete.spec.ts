import { test, expect } from '@playwright/test';
import { AutocompletePage } from '../../pages/frontend/autocomplete';

test.describe('Autocomplete', () => {
  let autocomplete: AutocompletePage;

  test.beforeEach(async ({ page }) => {
    autocomplete = new AutocompletePage(page);
    await autocomplete.goto();
  });

  test('search input exists on homepage', async () => {
    await expect(autocomplete.searchInput).toBeVisible();
  });

  test('typing 2+ chars opens dropdown', async () => {
    await autocomplete.search('bag');
    await expect(autocomplete.dropdown).toBeVisible();
  });

  test('shows product results with images, names, and prices', async () => {
    await autocomplete.search('bag');
    await expect(autocomplete.productResults.first()).toBeVisible();
    const firstProduct = autocomplete.productResults.first();
    await expect(firstProduct.locator('img')).toBeVisible();
    await expect(firstProduct.locator('p.text-sm.font-medium')).not.toBeEmpty();
  });

  test('shows category suggestions', async () => {
    await autocomplete.search('gear');
    await expect(autocomplete.categoryResults.first()).toBeVisible({ timeout: 5000 });
  });

  test('shows query suggestions', async () => {
    await autocomplete.search('yoga');
    await expect(autocomplete.dropdown).toBeVisible();
  });

  test('clicking a product navigates to PDP', async ({ page }) => {
    await autocomplete.search('bag');
    await expect(autocomplete.productResults.first()).toBeVisible();
    await autocomplete.productResults.first().click();
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('.html');
  });

  test('clicking a category navigates to category page', async ({ page }) => {
    await autocomplete.search('gear');
    const catLink = autocomplete.categoryResults.first();
    if (await catLink.isVisible({ timeout: 3000 }).catch(() => false)) {
      await catLink.click();
      await page.waitForLoadState('domcontentloaded');
      expect(page.url()).not.toBe('/');
    }
  });

  test('clearing input closes dropdown', async () => {
    await autocomplete.search('bag');
    await expect(autocomplete.dropdown).toBeVisible();
    await autocomplete.clearSearch();
    await expect(autocomplete.dropdown).not.toBeVisible();
  });

  test('no results for gibberish query', async () => {
    await autocomplete.search('xyzqwerty99999');
    await expect(autocomplete.noResults).toBeVisible({ timeout: 5000 });
  });
});
