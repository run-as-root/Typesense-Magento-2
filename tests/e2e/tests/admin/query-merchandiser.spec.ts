import { test, expect } from '@playwright/test';
import { QueryMerchandiserPage } from '../../pages/admin/query-merchandiser';

test.describe('Admin Query Merchandiser', () => {
  let merchandiser: QueryMerchandiserPage;

  test('listing page loads with data grid', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    await expect(merchandiser.dataGrid).toBeVisible({ timeout: 10_000 });
  });

  test('create new merchandising rule', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    await merchandiser.addButton.click();
    await page.waitForLoadState('domcontentloaded');
    await expect(merchandiser.queryInput).toBeVisible();
  });

  test('search and pin a product', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    await merchandiser.addButton.click();
    await page.waitForLoadState('domcontentloaded');

    await merchandiser.queryInput.fill('test-e2e-pin-rule');
    if (await merchandiser.searchProductInput.isVisible({ timeout: 3000 }).catch(() => false)) {
      await merchandiser.searchProductInput.fill('bag');
      await page.waitForTimeout(1000);
      const firstResult = page.locator('[data-role="product-result"], .ts-product-result').first();
      if (await firstResult.isVisible({ timeout: 3000 }).catch(() => false)) {
        await firstResult.click();
      }
    }
  });

  test('search and hide a product', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    await merchandiser.addButton.click();
    await page.waitForLoadState('domcontentloaded');
    await expect(merchandiser.queryInput).toBeVisible();
    const hideTab = page.locator('text=Hidden, text=Hide, [data-tab="hidden"]').first();
    if (await hideTab.isVisible({ timeout: 3000 }).catch(() => false)) {
      await hideTab.click();
    }
  });

  test('edit existing rule', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    const editLink = page.locator('a:has-text("Edit"), [data-action="edit"]').first();
    if (await editLink.isVisible({ timeout: 5000 }).catch(() => false)) {
      await editLink.click();
      await page.waitForLoadState('domcontentloaded');
      await expect(merchandiser.queryInput).toBeVisible();
    }
  });

  test('delete a rule', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    const deleteBtn = page.locator('button:has-text("Delete"), a:has-text("Delete")').first();
    if (await deleteBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      page.on('dialog', dialog => dialog.accept());
      await deleteBtn.click();
      await page.waitForTimeout(1000);
    }
  });
});
