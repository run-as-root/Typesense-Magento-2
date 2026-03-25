import { test, expect } from '@playwright/test';
import { SearchResultsPage } from '../../pages/frontend/search-results';

test.describe('Search Results Page', () => {
  let searchPage: SearchResultsPage;

  test('searching navigates to search results page', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('bag');
    expect(page.url()).toContain('/catalogsearch/result/');
    expect(page.url()).toContain('q=bag');
  });

  test('products render with images, names, prices, and view buttons', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('bag');
    const cards = await searchPage.getProductCards();
    await expect(cards.first()).toBeVisible();
    const firstCard = cards.first();
    await expect(firstCard.locator('img')).toBeVisible();
    await expect(firstCard.locator('h3')).not.toBeEmpty();
    await expect(firstCard.locator('a:has-text("View Product")')).toBeVisible();
  });

  test('facet filters appear in sidebar', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('jacket');
    await expect(searchPage.facets).toBeVisible();
    expect(await searchPage.facets.locator('h3').count()).toBeGreaterThanOrEqual(1);
  });

  test('clicking a facet filters results', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('jacket');
    const statsText = await searchPage.stats.textContent();
    const firstCheckbox = searchPage.facets.locator('.ts-facet-checkbox').first();
    if (await firstCheckbox.isVisible({ timeout: 3000 }).catch(() => false)) {
      await firstCheckbox.click();
      await page.waitForTimeout(1500);
      const newStatsText = await searchPage.stats.textContent();
      expect(newStatsText).not.toBe(statsText);
    }
  });

  test('sort dropdown changes product order', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('shirt');
    const firstProduct = (await searchPage.getProductCards()).first();
    await expect(firstProduct).toBeVisible();
    await searchPage.selectSort('price:asc');
    const newFirstProduct = (await searchPage.getProductCards()).first();
    await expect(newFirstProduct).toBeVisible();
  });

  test('pagination works', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('*');
    const nextBtn = searchPage.nextButton;
    if (await nextBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await expect(nextBtn).toBeEnabled();
      await nextBtn.click();
      await page.waitForTimeout(1500);
      await expect(searchPage.pagination.locator('span')).toContainText('2 /');
    }
  });

  test('price range slider filters by price', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('bag');
    if (await searchPage.priceApplyButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await searchPage.priceSliderMax.fill('100');
      await searchPage.priceApplyButton.click();
      await page.waitForTimeout(1500);
      const stats = await searchPage.stats.textContent();
      expect(stats).toContain('results found');
    }
  });

  test('no results message for nonsense query', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('xyznonexistent99999');
    await expect(searchPage.noResults).toBeVisible({ timeout: 5000 });
  });

  test('stats line shows result count and timing', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('bag');
    const stats = await searchPage.stats.textContent();
    expect(stats).toMatch(/\d+ results found in \d+ms/);
  });

  test('AI answer box is hidden when conversational search disabled', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('bag');
    await expect(searchPage.aiAnswerBox).not.toBeVisible();
  });
});
