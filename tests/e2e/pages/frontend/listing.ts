import { type Page, type Locator } from '@playwright/test';

/** Typesense listing shared by the search and category pages (RunAsRoot_TypeSense::listing/*.phtml). */
export class ListingPage {
  readonly page: Page;
  readonly container: Locator;
  readonly grid: Locator;
  readonly tiles: Locator;
  readonly skeletons: Locator;
  readonly count: Locator;
  readonly pagination: Locator;
  readonly prevButton: Locator;
  readonly nextButton: Locator;
  readonly noResults: Locator;
  readonly aiAnswerBox: Locator;

  constructor(page: Page) {
    this.page = page;
    this.container = page.locator('.typesense-listing');
    this.grid = this.container.locator('[data-typesense-grid]');
    this.tiles = this.grid.locator('[data-typesense-tile]');
    this.skeletons = this.grid.locator('.typesense-skeleton-tile');
    this.count = this.container.locator('.typesense-products-count');
    this.pagination = this.container.locator('.typesense-pagination nav');
    this.prevButton = this.pagination.locator('li').first().locator('a');
    this.nextButton = this.pagination.locator('li').last().locator('a');
    this.noResults = this.container.locator('.typesense-no-results');
    this.aiAnswerBox = page.locator('#typesense-ai-answer');
  }

  async goto(url: string) {
    await this.page.goto(url, { waitUntil: 'domcontentloaded' });
    await this.waitForResults();
  }

  async waitForResults() {
    await this.tiles.first().or(this.noResults).waitFor({ state: 'visible' });
  }
}
