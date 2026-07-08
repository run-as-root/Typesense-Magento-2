import { type Page, type Locator, expect } from '@playwright/test';

export class CategoryMerchandiserPage {
  readonly page: Page;
  readonly merchandiserSection: Locator;
  readonly productSearch: Locator;
  readonly productTable: Locator;
  readonly saveButton: Locator;
  /** Search-result dropdown items rendered under the product search input. */
  readonly searchResults: Locator;

  constructor(page: Page) {
    this.page = page;
    // Real ids, read from view/adminhtml/templates/category/merchandiser.phtml — the guessed
    // "#typesense-merchandiser"/"#ts-*" selectors below never matched the actual markup.
    this.merchandiserSection = page.locator('#typesense-category-merchandiser');
    this.productSearch = page.locator('#merchandiser-product-search');
    this.productTable = page.locator('#merchandiser-grid');
    this.saveButton = page.locator('#merchandiser-save-btn');
    this.searchResults = page.locator('#merchandiser-search-results .search-result-item');
  }

  async gotoCategoryEdit(categoryId: number) {
    // No leading slash: the "admin" project's baseURL is `${BASE_URL}/backend/` (see
    // playwright.config.ts). A leading slash resolves against the *origin*, discarding the
    // "/backend/" prefix entirely and landing on the storefront instead of the admin.
    await this.page.goto(`catalog/category/edit/id/${categoryId}`, { waitUntil: 'domcontentloaded' });
    // Must target the ".admin__collapsible-title" header specifically, not the whole
    // "[data-index=...]" fieldset wrapper: the wrapper's own bounding box includes the (hidden,
    // zero-height-when-collapsed) content area, so a plain `.click()` on the wrapper can land
    // outside the actual clickable title bar and silently fail to expand the section.
    //
    // The collapsible's click handler is a KnockoutJS binding that only attaches once the UI
    // component form finishes initializing client-side (well after "domcontentloaded" fires) —
    // clicking too early is a silent no-op. Wait for the header to actually be visible/stable
    // first, then retry the click until the (KO-rendered) section really shows up instead of
    // trusting a single click + fixed sleep.
    // Real fieldset name is "typesense_merchandising" (see view/adminhtml/ui_component/
    // category_form.xml) — "typesense_merchandiser" never matched anything.
    const section = this.page.locator('[data-index="typesense_merchandising"] .admin__collapsible-title').first();
    await section.waitFor({ state: 'visible', timeout: 15_000 });
    await expect(async () => {
      if (await this.merchandiserSection.isVisible()) return;
      await section.click();
      await expect(this.merchandiserSection).toBeVisible({ timeout: 3_000 });
    }).toPass({ timeout: 15_000 });
  }
}
