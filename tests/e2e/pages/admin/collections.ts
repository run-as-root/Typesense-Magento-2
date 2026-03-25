import { type Page, type Locator } from '@playwright/test';

export class CollectionsPage {
  readonly page: Page;
  readonly container: Locator;
  readonly collectionRows: Locator;
  readonly emptyState: Locator;

  constructor(page: Page) {
    this.page = page;
    this.container = page.locator('.ts-collections');
    this.collectionRows = page.locator('.ts-collection-row');
    this.emptyState = page.locator('.ts-empty');
  }

  async goto() {
    await this.page.goto('/typesense/collection/index', { waitUntil: 'domcontentloaded' });
  }

  getRow(collectionName: string) {
    return this.page.locator('.ts-collection-row').filter({ hasText: collectionName });
  }

  async viewCollection(name: string) {
    const row = this.getRow(name);
    await row.locator('a:has-text("View")').click();
  }

  async deleteCollection(name: string) {
    const row = this.getRow(name);
    await row.locator('button:has-text("Delete"), a:has-text("Delete")').click();
  }
}
