import { test, expect } from '@playwright/test';
import { SearchResultsPage } from '../../pages/frontend/search-results';

test.describe('Search Results Page', () => {
  test('renders Typesense tiles with image, name link and count', async ({ page }) => {
    const searchPage = new SearchResultsPage(page);
    await searchPage.gotoQuery('bag');

    expect(page.url()).toContain('q=bag');
    await expect(searchPage.skeletons.first()).toBeHidden();
    const tile = searchPage.tiles.first();
    await expect(tile.locator('img')).toBeVisible();
    await expect(tile.locator('a.typesense-tile-name')).not.toBeEmpty();
    await expect(searchPage.count).toHaveText(/\d+ products?/);
  });

  test('first images load eagerly, the rest lazily', async ({ page }) => {
    const searchPage = new SearchResultsPage(page);
    await searchPage.gotoQuery('*');

    await expect(searchPage.tiles.nth(0).locator('img')).toHaveAttribute('loading', 'eager');
    await expect(searchPage.tiles.nth(0).locator('img')).toHaveAttribute('fetchpriority', 'high');
    if (await searchPage.tiles.count() > 4) {
      await expect(searchPage.tiles.nth(4).locator('img')).toHaveAttribute('loading', 'lazy');
    }
  });

  test('pagination writes the page to the URL and back restores it', async ({ page }) => {
    const searchPage = new SearchResultsPage(page);
    await searchPage.gotoQuery('*');
    test.skip(!(await searchPage.pagination.isVisible()), 'catalog has a single page');

    const firstName = await searchPage.tiles.first().locator('a.typesense-tile-name').textContent();
    await searchPage.nextButton.click();
    await expect(page).toHaveURL(/page=2/);
    await expect(searchPage.tiles.first().locator('a.typesense-tile-name')).not.toHaveText(firstName ?? '');

    await page.goBack();
    await expect(page).not.toHaveURL(/page=2/);
    await expect(searchPage.tiles.first().locator('a.typesense-tile-name')).toHaveText(firstName ?? '');
  });

  test('hostile URL parameters are ignored or clamped', async ({ page }) => {
    const searchPage = new SearchResultsPage(page);
    await searchPage.gotoQuery('bag', '&page=999&sort=bogus&color=%3Cscript%3Ewindow.pwned%3D1%3C%2Fscript%3E&utm_x=1');

    await expect(page).not.toHaveURL(/page=999|sort=bogus|utm_x/);
    expect(await page.evaluate(() => (window as unknown as { pwned?: number }).pwned)).toBeUndefined();
  });

  test('no results message for nonsense query', async ({ page }) => {
    const searchPage = new SearchResultsPage(page);
    await searchPage.gotoQuery('xyznonexistent99999');

    await expect(searchPage.noResults).toContainText('xyznonexistent99999');
  });

  test('AI answer box is hidden when conversational search disabled', async ({ page }) => {
    const searchPage = new SearchResultsPage(page);
    await searchPage.gotoQuery('bag');

    await expect(searchPage.aiAnswerBox).toBeHidden();
  });
});
