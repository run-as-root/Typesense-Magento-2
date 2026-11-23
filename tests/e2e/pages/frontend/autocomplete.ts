import { type Page, type Locator } from '@playwright/test';

export class AutocompletePage {
  readonly page: Page;
  readonly searchInput: Locator;
  readonly dropdown: Locator;
  readonly productResults: Locator;
  readonly categoryResults: Locator;
  readonly suggestionResults: Locator;
  readonly noResults: Locator;

  constructor(page: Page) {
    this.page = page;
    const wrapper = page.locator('[x-data="typesenseHeaderSearch"]');
    this.searchInput = wrapper.locator('#search');
    this.dropdown = wrapper.locator('#typesense-autocomplete');
    this.productResults = this.dropdown.locator('[aria-labelledby="typesense-ac-product-title"] a[role="option"]');
    this.categoryResults = this.dropdown.locator('[aria-labelledby="typesense-ac-category-title"] a[role="option"]');
    this.suggestionResults = this.dropdown.locator('[aria-labelledby="typesense-ac-suggestion-title"] a[role="option"]');
    this.noResults = this.dropdown.locator('text=No products for');
  }

  async goto() {
    await this.page.goto('/', { waitUntil: 'domcontentloaded' });
  }

  async search(query: string) {
    await this.searchInput.fill(query);
    await this.page.waitForTimeout(500);
  }

  async clearSearch() {
    await this.searchInput.fill('');
  }
}
