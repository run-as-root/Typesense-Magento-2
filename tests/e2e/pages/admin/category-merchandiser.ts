import { type Page, type Locator } from '@playwright/test';

export class CategoryMerchandiserPage {
  readonly page: Page;
  readonly merchandiserSection: Locator;
  readonly productSearch: Locator;
  readonly productTable: Locator;
  readonly saveButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.merchandiserSection = page.locator('#typesense-merchandiser, [data-role="typesense-merchandiser"]');
    this.productSearch = page.locator('#ts-product-search');
    this.productTable = page.locator('.ts-merchandiser-table, #ts-merchandiser-grid');
    this.saveButton = page.locator('#ts-merchandiser-save, button:has-text("Save Merchandising")');
  }

  async gotoCategoryEdit(categoryId: number) {
    await this.page.goto(`/catalog/category/edit/id/${categoryId}`, { waitUntil: 'domcontentloaded' });
    const section = this.page.locator('[data-index="typesense_merchandiser"], :has-text("TypeSense Merchandising")').first();
    if (await section.isVisible()) {
      await section.click();
    }
    await this.page.waitForTimeout(1000);
  }
}
