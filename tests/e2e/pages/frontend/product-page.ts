import { type Page, type Locator } from '@playwright/test';

export class ProductPage {
  readonly page: Page;
  readonly recommendationsWrapper: Locator;
  readonly recommendationsSlider: Locator;
  readonly recommendationsTitle: Locator;
  readonly leftArrow: Locator;
  readonly rightArrow: Locator;

  constructor(page: Page) {
    this.page = page;
    this.recommendationsWrapper = page.locator('[data-rec-wrapper]');
    this.recommendationsSlider = page.locator('[data-rec-slider]');
    this.recommendationsTitle = page.locator('h2:has-text("You May Also Like")');
    this.leftArrow = page.locator('[data-rec-left]');
    this.rightArrow = page.locator('[data-rec-right]');
  }

  async goto(productUrl: string) {
    await this.page.goto(productUrl, { waitUntil: 'domcontentloaded' });
    await this.page.waitForTimeout(1000);
  }
}
