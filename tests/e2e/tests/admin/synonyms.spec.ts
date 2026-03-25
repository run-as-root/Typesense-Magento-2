import { test, expect } from '@playwright/test';
import { SynonymsPage } from '../../pages/admin/synonyms';

test.describe('Admin Synonyms', () => {
  let synonyms: SynonymsPage;

  test('synonym page loads with collection selector', async ({ page }) => {
    synonyms = new SynonymsPage(page);
    await synonyms.goto();
    await expect(synonyms.collectionSelector).toBeVisible();
  });

  test('existing synonyms display after selecting product collection', async ({ page }) => {
    synonyms = new SynonymsPage(page);
    const productCollection = 'rar_product_default';
    await synonyms.goto(productCollection);
    await expect(synonyms.synonymRows.first()).toBeVisible({ timeout: 5000 });
  });

  test('add multi-way synonym and verify it appears', async ({ page }) => {
    synonyms = new SynonymsPage(page);
    const productCollection = 'rar_product_default';
    await synonyms.goto(productCollection);

    await synonyms.addMultiWaySynonym('hoodie, sweatshirt, pullover');
    await page.waitForTimeout(1000);

    await synonyms.goto(productCollection);
    await expect(page.locator('text=hoodie')).toBeVisible({ timeout: 5000 });
  });

  test('add one-way synonym and verify root word displayed', async ({ page }) => {
    synonyms = new SynonymsPage(page);
    const productCollection = 'rar_product_default';
    await synonyms.goto(productCollection);

    await synonyms.addOneWaySynonym('blazer', 'sport coat, suit jacket');
    await page.waitForTimeout(1000);

    await synonyms.goto(productCollection);
    await expect(page.locator('text=blazer')).toBeVisible({ timeout: 5000 });
  });

  test('delete synonym and verify removal', async ({ page }) => {
    synonyms = new SynonymsPage(page);
    const productCollection = 'rar_product_default';

    await synonyms.goto(productCollection);
    await synonyms.addMultiWaySynonym('testdelete1, testdelete2');
    await page.waitForTimeout(1000);

    await synonyms.goto(productCollection);
    const row = page.locator('.ts-synonym-row').filter({ hasText: 'testdelete1' });
    if (await row.isVisible()) {
      page.on('dialog', dialog => dialog.accept());
      await row.locator('button:has-text("Delete")').click();
      await page.waitForTimeout(1000);
      await synonyms.goto(productCollection);
      await expect(page.locator('text=testdelete1')).not.toBeVisible();
    }
  });
});
