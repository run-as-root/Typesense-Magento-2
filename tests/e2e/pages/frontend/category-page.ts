import { type Page, type Locator } from '@playwright/test';

export class CategoryPage {
  readonly page: Page;
  readonly container: Locator;
  readonly products: Locator;
  readonly facets: Locator;
  readonly sortDropdown: Locator;
  readonly stats: Locator;
  readonly pagination: Locator;
  readonly prevButton: Locator;
  readonly nextButton: Locator;
  readonly priceApplyButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.container = page.locator('.typesense-category-search');
    this.products = this.container.locator('[data-products]');
    this.facets = this.container.locator('[data-facets]');
    this.sortDropdown = this.container.locator('[data-sort-select]');
    this.stats = this.container.locator('p.text-sm.text-gray-600').first();
    this.pagination = this.container.locator('[data-pagination]');
    this.prevButton = this.pagination.locator('[data-action="prev"]');
    this.nextButton = this.pagination.locator('[data-action="next"]');
    this.priceApplyButton = this.facets.locator('[data-price-apply]');
  }

  async goto(categoryUrl: string) {
    await this.page.goto(categoryUrl, { waitUntil: 'domcontentloaded' });
    await this.page.waitForTimeout(2000);
  }

  async getProductCards() {
    return this.products.locator('.border.border-gray-200.rounded-lg');
  }

  async selectSort(value: string) {
    await this.sortDropdown.selectOption(value);
    await this.page.waitForTimeout(1000);
  }

  async clickFacet(field: string, value: string) {
    const checkbox = this.facets.locator(`input[data-facet-field="${field}"][data-facet-value="${value}"]`);
    await checkbox.click();
    await this.page.waitForTimeout(1000);
  }
}
