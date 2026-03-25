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
    const wrapper = page.locator('[x-data="initTypesenseAutocomplete"]');
    this.searchInput = wrapper.locator('input[type="text"]');
    this.dropdown = wrapper.locator('.absolute.z-50');
    this.productResults = this.dropdown.locator('h4:has-text("Products")').locator('..').locator('a');
    this.categoryResults = this.dropdown.locator('h4:has-text("Categories")').locator('..').locator('a');
    this.suggestionResults = this.dropdown.locator('h4:has-text("Suggestions")').locator('..').locator('button');
    this.noResults = this.dropdown.locator('text=No results found');
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
