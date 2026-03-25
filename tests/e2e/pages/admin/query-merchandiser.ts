import { type Page, type Locator } from '@playwright/test';

export class QueryMerchandiserPage {
  readonly page: Page;
  readonly dataGrid: Locator;
  readonly addButton: Locator;
  readonly queryInput: Locator;
  readonly searchProductInput: Locator;
  readonly saveButton: Locator;
  readonly deleteButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.dataGrid = page.locator('.admin__data-grid-outer-wrap, [data-role="grid"]');
    this.addButton = page.locator('button:has-text("Add New"), #add');
    this.queryInput = page.locator('[name="query"]');
    this.searchProductInput = page.locator('[data-role="product-search"], input[placeholder*="product"]');
    this.saveButton = page.locator('button:has-text("Save"), #save');
    this.deleteButton = page.locator('button:has-text("Delete")');
  }

  async gotoListing() {
    await this.page.goto('/typesense/querymerchandiser/index', { waitUntil: 'domcontentloaded' });
  }

  async gotoEdit(id: number) {
    await this.page.goto(`/typesense/querymerchandiser/edit/id/${id}`, { waitUntil: 'domcontentloaded' });
  }
}
