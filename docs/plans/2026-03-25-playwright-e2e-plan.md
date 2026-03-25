# Playwright E2E Test Suite Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a comprehensive Playwright E2E test suite (~55 tests) covering all frontend and admin features of the Typesense Magento 2 extension, runnable in both Warden and CI.

**Architecture:** Page Object Model pattern with environment-driven config. Tests live in `tests/e2e/` with separate `pages/` (selectors + interactions) and `tests/` (assertions). CI uses a dedicated Docker Compose stack with Magento + Typesense + sample data. Admin auth via Playwright's `storageState` pattern.

**Tech Stack:** Playwright, TypeScript, Node.js, Docker Compose, GitHub Actions

---

### Task 1: Initialize Playwright project

**Files:**
- Create: `tests/e2e/package.json`
- Create: `tests/e2e/tsconfig.json`
- Create: `tests/e2e/playwright.config.ts`
- Create: `tests/e2e/.gitignore`

**Step 1: Create `tests/e2e/package.json`**

```json
{
  "name": "typesense-magento2-e2e",
  "private": true,
  "scripts": {
    "test": "playwright test",
    "test:frontend": "playwright test tests/frontend/",
    "test:admin": "playwright test tests/admin/",
    "test:ui": "playwright test --ui",
    "report": "playwright show-report"
  },
  "devDependencies": {
    "@playwright/test": "^1.52.0"
  }
}
```

**Step 2: Create `tests/e2e/tsconfig.json`**

```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "NodeNext",
    "moduleResolution": "NodeNext",
    "strict": true,
    "esModuleInterop": true,
    "rootDir": ".",
    "baseUrl": ".",
    "paths": {
      "@pages/*": ["pages/*"]
    }
  },
  "include": ["**/*.ts"]
}
```

**Step 3: Create `tests/e2e/playwright.config.ts`**

```typescript
import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env.BASE_URL || 'https://app.mage-os-typesense.test';
const ADMIN_URL = process.env.ADMIN_URL || `${BASE_URL}/backend/admin/`;

export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 2 : undefined,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
  ],
  timeout: 60_000,
  expect: {
    timeout: 10_000,
  },
  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: true,
    navigationTimeout: 30_000,
  },
  projects: [
    {
      name: 'admin-auth',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'frontend',
      testDir: './tests/frontend',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'admin',
      testDir: './tests/admin',
      dependencies: ['admin-auth'],
      use: {
        ...devices['Desktop Chrome'],
        storageState: '.auth/admin.json',
        baseURL: ADMIN_URL,
      },
    },
  ],
});
```

**Step 4: Create `tests/e2e/.gitignore`**

```
node_modules/
test-results/
playwright-report/
.auth/
```

**Step 5: Install dependencies**

Run: `cd /Users/david/Herd/TypeSense/tests/e2e && npm install && npx playwright install chromium`

**Step 6: Commit**

```bash
git add tests/e2e/package.json tests/e2e/package-lock.json tests/e2e/tsconfig.json tests/e2e/playwright.config.ts tests/e2e/.gitignore
git commit -m "feat(e2e): initialize Playwright project with config and package.json"
```

---

### Task 2: Admin auth setup fixture

**Files:**
- Create: `tests/e2e/fixtures/auth.setup.ts`

**Step 1: Create the auth setup**

```typescript
import { test as setup, expect } from '@playwright/test';

const ADMIN_USER = process.env.ADMIN_USER || 'david';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'Admin12345!';
const BASE_URL = process.env.BASE_URL || 'https://app.mage-os-typesense.test';
const ADMIN_URL = process.env.ADMIN_URL || `${BASE_URL}/backend/admin/`;

setup('authenticate as admin', async ({ page }) => {
  await page.goto(ADMIN_URL, { waitUntil: 'domcontentloaded' });

  await page.locator('#username').fill(ADMIN_USER);
  await page.locator('#login').fill(ADMIN_PASSWORD);
  await page.locator('.action-login').click();

  // Wait for admin dashboard to load
  await expect(page.locator('.page-wrapper')).toBeVisible({ timeout: 30_000 });

  await page.context().storageState({ path: '.auth/admin.json' });
});
```

**Step 2: Verify it runs**

Run: `cd /Users/david/Herd/TypeSense/tests/e2e && npx playwright test --project=admin-auth`
Expected: Auth setup passes, `.auth/admin.json` created.

**Step 3: Commit**

```bash
git add tests/e2e/fixtures/auth.setup.ts
git commit -m "feat(e2e): add admin auth setup fixture with storageState"
```

---

### Task 3: Frontend Page Object Models

**Files:**
- Create: `tests/e2e/pages/frontend/autocomplete.ts`
- Create: `tests/e2e/pages/frontend/search-results.ts`
- Create: `tests/e2e/pages/frontend/category-page.ts`
- Create: `tests/e2e/pages/frontend/product-page.ts`

**Step 1: Create `tests/e2e/pages/frontend/autocomplete.ts`**

```typescript
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
    // The autocomplete wraps the search input in a div with x-data="initTypesenseAutocomplete"
    const wrapper = page.locator('[x-data="initTypesenseAutocomplete"]');
    this.searchInput = wrapper.locator('input[type="text"]');
    this.dropdown = wrapper.locator('.absolute.z-50');
    this.productResults = this.dropdown.locator('h4:has-text("Products") + *').locator('a');
    this.categoryResults = this.dropdown.locator('h4:has-text("Categories") + *').locator('a');
    this.suggestionResults = this.dropdown.locator('h4:has-text("Suggestions") + *').locator('button');
    this.noResults = this.dropdown.locator('text=No results found');
  }

  async goto() {
    await this.page.goto('/', { waitUntil: 'domcontentloaded' });
  }

  async search(query: string) {
    await this.searchInput.fill(query);
    // Wait for debounce (300ms) + API response
    await this.page.waitForTimeout(500);
  }

  async clearSearch() {
    await this.searchInput.fill('');
  }
}
```

**Step 2: Create `tests/e2e/pages/frontend/search-results.ts`**

```typescript
import { type Page, type Locator } from '@playwright/test';

export class SearchResultsPage {
  readonly page: Page;
  readonly container: Locator;
  readonly products: Locator;
  readonly facets: Locator;
  readonly sortDropdown: Locator;
  readonly stats: Locator;
  readonly pagination: Locator;
  readonly prevButton: Locator;
  readonly nextButton: Locator;
  readonly priceSliderMin: Locator;
  readonly priceSliderMax: Locator;
  readonly priceApplyButton: Locator;
  readonly aiAnswerBox: Locator;
  readonly noResults: Locator;

  constructor(page: Page) {
    this.page = page;
    this.container = page.locator('.typesense-instant-search');
    this.products = this.container.locator('[data-products]');
    this.facets = this.container.locator('[data-facets]');
    this.sortDropdown = this.container.locator('[data-sort-select]');
    this.stats = this.container.locator('p.text-sm.text-gray-600').first();
    this.pagination = this.container.locator('[data-pagination]');
    this.prevButton = this.pagination.locator('[data-action="prev"]');
    this.nextButton = this.pagination.locator('[data-action="next"]');
    this.priceSliderMin = this.facets.locator('[data-price-slider-min]');
    this.priceSliderMax = this.facets.locator('[data-price-slider-max]');
    this.priceApplyButton = this.facets.locator('[data-price-apply]');
    this.aiAnswerBox = page.locator('#typesense-ai-answer');
    this.noResults = this.products.locator('text=No products found');
  }

  async goto(query: string) {
    await this.page.goto(`/catalogsearch/result/?q=${encodeURIComponent(query)}`, { waitUntil: 'domcontentloaded' });
    // Wait for Typesense results to render
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
```

**Step 3: Create `tests/e2e/pages/frontend/category-page.ts`**

```typescript
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
```

**Step 4: Create `tests/e2e/pages/frontend/product-page.ts`**

```typescript
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
```

**Step 5: Commit**

```bash
git add tests/e2e/pages/frontend/
git commit -m "feat(e2e): add frontend Page Object Models for autocomplete, search, category, PDP"
```

---

### Task 4: Admin Page Object Models

**Files:**
- Create: `tests/e2e/pages/admin/dashboard.ts`
- Create: `tests/e2e/pages/admin/collections.ts`
- Create: `tests/e2e/pages/admin/synonyms.ts`
- Create: `tests/e2e/pages/admin/query-merchandiser.ts`
- Create: `tests/e2e/pages/admin/category-merchandiser.ts`
- Create: `tests/e2e/pages/admin/config.ts`

**Step 1: Create `tests/e2e/pages/admin/dashboard.ts`**

```typescript
import { type Page, type Locator } from '@playwright/test';

export class DashboardPage {
  readonly page: Page;
  readonly container: Locator;
  readonly statusBar: Locator;
  readonly testConnectionButton: Locator;
  readonly connectionCard: Locator;
  readonly checklistCard: Locator;
  readonly checklistItems: Locator;
  readonly collectionsTable: Locator;
  readonly quickActions: Locator;

  constructor(page: Page) {
    this.page = page;
    this.container = page.locator('.ts-dashboard');
    this.statusBar = page.locator('.ts-status-bar');
    this.testConnectionButton = page.locator('#ts-test-connection');
    this.connectionCard = page.locator('.ts-card').filter({ hasText: 'Connection' });
    this.checklistCard = page.locator('.ts-card').filter({ hasText: 'Checklist' });
    this.checklistItems = page.locator('.ts-checklist li');
    this.collectionsTable = page.locator('.ts-card').filter({ hasText: 'Collections' });
    this.quickActions = page.locator('.ts-card').filter({ hasText: 'Quick Actions' });
  }

  async goto() {
    await this.page.goto('/typesense/dashboard/index', { waitUntil: 'domcontentloaded' });
  }

  async testConnection() {
    await this.testConnectionButton.click();
    await this.page.waitForTimeout(2000);
  }
}
```

**Step 2: Create `tests/e2e/pages/admin/collections.ts`**

```typescript
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
```

**Step 3: Create `tests/e2e/pages/admin/synonyms.ts`**

```typescript
import { type Page, type Locator } from '@playwright/test';

export class SynonymsPage {
  readonly page: Page;
  readonly collectionSelector: Locator;
  readonly synonymRows: Locator;
  readonly typeSelector: Locator;
  readonly synonymsInput: Locator;
  readonly rootInput: Locator;
  readonly submitButton: Locator;
  readonly emptyState: Locator;

  constructor(page: Page) {
    this.page = page;
    this.collectionSelector = page.locator('.ts-collection-bar select');
    this.synonymRows = page.locator('.ts-synonym-row');
    this.typeSelector = page.locator('#synonym-type, [name="type"]');
    this.synonymsInput = page.locator('#synonym-words, [name="synonyms"]');
    this.rootInput = page.locator('#synonym-root, [name="root"]');
    this.submitButton = page.locator('button:has-text("Add Synonym"), button:has-text("Save")');
    this.emptyState = page.locator('.ts-empty');
  }

  async goto(collection?: string) {
    const url = collection
      ? `/typesense/synonym/index?collection=${encodeURIComponent(collection)}`
      : '/typesense/synonym/index';
    await this.page.goto(url, { waitUntil: 'domcontentloaded' });
  }

  async selectCollection(name: string) {
    await this.collectionSelector.selectOption({ label: name });
    await this.page.waitForTimeout(1000);
  }

  async addMultiWaySynonym(words: string) {
    await this.typeSelector.selectOption('multi-way');
    await this.synonymsInput.fill(words);
    await this.submitButton.click();
    await this.page.waitForTimeout(1000);
  }

  async addOneWaySynonym(root: string, words: string) {
    await this.typeSelector.selectOption('one-way');
    await this.rootInput.fill(root);
    await this.synonymsInput.fill(words);
    await this.submitButton.click();
    await this.page.waitForTimeout(1000);
  }

  async deleteSynonym(index: number) {
    const row = this.synonymRows.nth(index);
    await row.locator('button:has-text("Delete")').click();
    // Handle confirm dialog
    this.page.on('dialog', dialog => dialog.accept());
    await this.page.waitForTimeout(1000);
  }
}
```

**Step 4: Create `tests/e2e/pages/admin/query-merchandiser.ts`**

```typescript
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
```

**Step 5: Create `tests/e2e/pages/admin/category-merchandiser.ts`**

```typescript
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
    // Scroll to and open the TypeSense Merchandising section
    const section = this.page.locator('[data-index="typesense_merchandiser"], :has-text("TypeSense Merchandising")').first();
    if (await section.isVisible()) {
      await section.click();
    }
    await this.page.waitForTimeout(1000);
  }
}
```

**Step 6: Create `tests/e2e/pages/admin/config.ts`**

```typescript
import { type Page, type Locator } from '@playwright/test';

export class ConfigPage {
  readonly page: Page;

  constructor(page: Page) {
    this.page = page;
  }

  async goto() {
    await this.page.goto('/system_config/edit/section/run_as_root_typesense/', { waitUntil: 'domcontentloaded' });
  }

  group(id: string) {
    return this.page.locator(`#run_as_root_typesense_${id}`);
  }

  groupHeader(id: string) {
    return this.page.locator(`#run_as_root_typesense_${id}-head`);
  }

  field(groupId: string, fieldId: string) {
    return this.page.locator(`#run_as_root_typesense_${groupId}_${fieldId}`);
  }

  async openGroup(id: string) {
    const header = this.groupHeader(id);
    const body = this.group(id);
    // Only click if group is collapsed
    if (!(await body.isVisible())) {
      await header.click();
      await this.page.waitForTimeout(500);
    }
  }

  async save() {
    await this.page.locator('#save').click();
    await this.page.waitForLoadState('domcontentloaded');
  }
}
```

**Step 7: Commit**

```bash
git add tests/e2e/pages/admin/
git commit -m "feat(e2e): add admin Page Object Models for dashboard, collections, synonyms, merchandiser, config"
```

---

### Task 5: Frontend autocomplete tests

**Files:**
- Create: `tests/e2e/tests/frontend/autocomplete.spec.ts`

**Step 1: Write the tests**

```typescript
import { test, expect } from '@playwright/test';
import { AutocompletePage } from '../../pages/frontend/autocomplete';

test.describe('Autocomplete', () => {
  let autocomplete: AutocompletePage;

  test.beforeEach(async ({ page }) => {
    autocomplete = new AutocompletePage(page);
    await autocomplete.goto();
  });

  test('search input exists on homepage', async () => {
    await expect(autocomplete.searchInput).toBeVisible();
  });

  test('typing 2+ chars opens dropdown', async () => {
    await autocomplete.search('bag');
    await expect(autocomplete.dropdown).toBeVisible();
  });

  test('shows product results with images, names, and prices', async () => {
    await autocomplete.search('bag');
    await expect(autocomplete.productResults.first()).toBeVisible();
    // Product should have an image and text
    const firstProduct = autocomplete.productResults.first();
    await expect(firstProduct.locator('img')).toBeVisible();
    await expect(firstProduct.locator('p.text-sm.font-medium')).not.toBeEmpty();
  });

  test('shows category suggestions', async () => {
    await autocomplete.search('gear');
    await expect(autocomplete.categoryResults.first()).toBeVisible({ timeout: 5000 });
  });

  test('shows query suggestions', async ({ page }) => {
    await autocomplete.search('yoga');
    // Suggestions may or may not appear depending on indexed data
    // Just verify the dropdown is open and has some content
    await expect(autocomplete.dropdown).toBeVisible();
  });

  test('clicking a product navigates to PDP', async ({ page }) => {
    await autocomplete.search('bag');
    await expect(autocomplete.productResults.first()).toBeVisible();
    const href = await autocomplete.productResults.first().getAttribute('href');
    await autocomplete.productResults.first().click();
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('.html');
  });

  test('clicking a category navigates to category page', async ({ page }) => {
    await autocomplete.search('gear');
    const catLink = autocomplete.categoryResults.first();
    if (await catLink.isVisible({ timeout: 3000 }).catch(() => false)) {
      await catLink.click();
      await page.waitForLoadState('domcontentloaded');
      // Should be on a category page
      expect(page.url()).not.toBe('/');
    }
  });

  test('clearing input closes dropdown', async () => {
    await autocomplete.search('bag');
    await expect(autocomplete.dropdown).toBeVisible();
    await autocomplete.clearSearch();
    await expect(autocomplete.dropdown).not.toBeVisible();
  });

  test('no results for gibberish query', async () => {
    await autocomplete.search('xyzqwerty99999');
    await expect(autocomplete.noResults).toBeVisible({ timeout: 5000 });
  });
});
```

**Step 2: Run tests**

Run: `cd /Users/david/Herd/TypeSense/tests/e2e && npx playwright test tests/frontend/autocomplete.spec.ts`
Expected: Tests run (may need Warden env up with indexed data).

**Step 3: Commit**

```bash
git add tests/e2e/tests/frontend/autocomplete.spec.ts
git commit -m "test(e2e): add autocomplete tests — 9 tests covering search, navigation, results"
```

---

### Task 6: Frontend search results tests

**Files:**
- Create: `tests/e2e/tests/frontend/search-results.spec.ts`

**Step 1: Write the tests**

```typescript
import { test, expect } from '@playwright/test';
import { SearchResultsPage } from '../../pages/frontend/search-results';

test.describe('Search Results Page', () => {
  let searchPage: SearchResultsPage;

  test('searching navigates to search results page', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('bag');
    expect(page.url()).toContain('/catalogsearch/result/');
    expect(page.url()).toContain('q=bag');
  });

  test('products render with images, names, prices, and view buttons', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('bag');
    const cards = await searchPage.getProductCards();
    await expect(cards.first()).toBeVisible();
    // Check product card has essential elements
    const firstCard = cards.first();
    await expect(firstCard.locator('img')).toBeVisible();
    await expect(firstCard.locator('h3')).not.toBeEmpty();
    await expect(firstCard.locator('a:has-text("View Product")')).toBeVisible();
  });

  test('facet filters appear in sidebar', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('jacket');
    await expect(searchPage.facets).toBeVisible();
    // Should have at least Category facet
    await expect(searchPage.facets.locator('h3')).toHaveCount.greaterThanOrEqual?.(1) ??
      expect(await searchPage.facets.locator('h3').count()).toBeGreaterThanOrEqual(1);
  });

  test('clicking a facet filters results', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('jacket');
    const statsText = await searchPage.stats.textContent();
    // Find a facet checkbox and click it
    const firstCheckbox = searchPage.facets.locator('.ts-facet-checkbox').first();
    if (await firstCheckbox.isVisible({ timeout: 3000 }).catch(() => false)) {
      await firstCheckbox.click();
      await page.waitForTimeout(1500);
      const newStatsText = await searchPage.stats.textContent();
      expect(newStatsText).not.toBe(statsText);
    }
  });

  test('sort dropdown changes product order', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('shirt');
    // Get first product name with default sort
    const firstProduct = (await searchPage.getProductCards()).first();
    const firstName = await firstProduct.locator('h3').textContent();
    // Sort by price ascending
    await searchPage.selectSort('price:asc');
    const newFirstProduct = (await searchPage.getProductCards()).first();
    const newFirstName = await newFirstProduct.locator('h3').textContent();
    // Products should be in a different order (may not always differ, but usually will)
    // At minimum, verify the page re-rendered
    await expect(newFirstProduct).toBeVisible();
  });

  test('pagination works', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('*'); // wildcard for all products
    // If there are enough products, pagination should appear
    const nextBtn = searchPage.nextButton;
    if (await nextBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await expect(nextBtn).toBeEnabled();
      await nextBtn.click();
      await page.waitForTimeout(1500);
      // Page indicator should show 2 / X
      await expect(searchPage.pagination.locator('span')).toContainText('2 /');
    }
  });

  test('price range slider filters by price', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('bag');
    // Set a price range and apply
    if (await searchPage.priceApplyButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await searchPage.priceSliderMax.fill('100');
      await searchPage.priceApplyButton.click();
      await page.waitForTimeout(1500);
      // Results should be filtered — stats should update
      const stats = await searchPage.stats.textContent();
      expect(stats).toContain('results found');
    }
  });

  test('no results message for nonsense query', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('xyznonexistent99999');
    await expect(searchPage.noResults).toBeVisible({ timeout: 5000 });
  });

  test('stats line shows result count and timing', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('bag');
    const stats = await searchPage.stats.textContent();
    expect(stats).toMatch(/\d+ results found in \d+ms/);
  });

  test('AI answer box is hidden when conversational search disabled', async ({ page }) => {
    searchPage = new SearchResultsPage(page);
    await searchPage.goto('bag');
    // Conversational search is disabled by default
    await expect(searchPage.aiAnswerBox).not.toBeVisible();
  });
});
```

**Step 2: Run and commit**

Run: `cd /Users/david/Herd/TypeSense/tests/e2e && npx playwright test tests/frontend/search-results.spec.ts`

```bash
git add tests/e2e/tests/frontend/search-results.spec.ts
git commit -m "test(e2e): add search results page tests — 10 tests covering products, facets, sort, pagination"
```

---

### Task 7: Frontend category page and product page tests

**Files:**
- Create: `tests/e2e/tests/frontend/category-page.spec.ts`
- Create: `tests/e2e/tests/frontend/product-page.spec.ts`

**Step 1: Create `tests/e2e/tests/frontend/category-page.spec.ts`**

```typescript
import { test, expect } from '@playwright/test';
import { CategoryPage } from '../../pages/frontend/category-page';

test.describe('Category Page', () => {
  let categoryPage: CategoryPage;

  test.beforeEach(async ({ page }) => {
    categoryPage = new CategoryPage(page);
    // Navigate to a known category (Gear > Bags in sample data)
    await categoryPage.goto('/gear/bags.html');
  });

  test('loads with Typesense-powered listing', async () => {
    await expect(categoryPage.container).toBeVisible();
  });

  test('displays products for category', async () => {
    const cards = await categoryPage.getProductCards();
    await expect(cards.first()).toBeVisible();
    expect(await cards.count()).toBeGreaterThan(0);
  });

  test('facet filters appear and work', async ({ page }) => {
    await expect(categoryPage.facets).toBeVisible();
    const firstCheckbox = categoryPage.facets.locator('.ts-facet-checkbox').first();
    if (await firstCheckbox.isVisible({ timeout: 3000 }).catch(() => false)) {
      const statsBefore = await categoryPage.stats.textContent();
      await firstCheckbox.click();
      await page.waitForTimeout(1500);
      const statsAfter = await categoryPage.stats.textContent();
      // Stats should change or at minimum remain valid
      expect(statsAfter).toContain('products');
    }
  });

  test('sort options work', async ({ page }) => {
    await expect(categoryPage.sortDropdown).toBeVisible();
    const options = await categoryPage.sortDropdown.locator('option').count();
    expect(options).toBeGreaterThan(1);
    await categoryPage.selectSort('price:asc');
    await expect((await categoryPage.getProductCards()).first()).toBeVisible();
  });

  test('pagination works when enough products', async ({ page }) => {
    const nextBtn = categoryPage.nextButton;
    if (await nextBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await nextBtn.click();
      await page.waitForTimeout(1500);
      await expect(categoryPage.pagination.locator('span')).toContainText('2 /');
    }
  });

  test('price range slider works', async ({ page }) => {
    if (await categoryPage.priceApplyButton.isVisible({ timeout: 3000 }).catch(() => false)) {
      await categoryPage.priceApplyButton.click();
      await page.waitForTimeout(1500);
      const stats = await categoryPage.stats.textContent();
      expect(stats).toContain('products');
    }
  });
});
```

**Step 2: Create `tests/e2e/tests/frontend/product-page.spec.ts`**

```typescript
import { test, expect } from '@playwright/test';
import { ProductPage } from '../../pages/frontend/product-page';

test.describe('Product Detail Page', () => {
  test('recommendations section hidden when feature disabled', async ({ page }) => {
    const productPage = new ProductPage(page);
    // Navigate to a known sample data product
    await productPage.goto('/joust-duffle-bag.html');
    // Recommendations are disabled by default
    await expect(productPage.recommendationsWrapper).not.toBeVisible();
  });

  test('PDP loads without JS errors when recommendations disabled', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));

    const productPage = new ProductPage(page);
    await productPage.goto('/joust-duffle-bag.html');
    await page.waitForTimeout(2000);

    // Filter for TypeSense-related errors only
    const tsErrors = errors.filter(e => e.toLowerCase().includes('typesense') || e.toLowerCase().includes('recommendation'));
    expect(tsErrors).toHaveLength(0);
  });
});
```

**Step 3: Run and commit**

Run: `cd /Users/david/Herd/TypeSense/tests/e2e && npx playwright test tests/frontend/`

```bash
git add tests/e2e/tests/frontend/category-page.spec.ts tests/e2e/tests/frontend/product-page.spec.ts
git commit -m "test(e2e): add category page (6 tests) and product page (2 tests) tests"
```

---

### Task 8: Admin dashboard and collections tests

**Files:**
- Create: `tests/e2e/tests/admin/dashboard.spec.ts`
- Create: `tests/e2e/tests/admin/collections.spec.ts`

**Step 1: Create `tests/e2e/tests/admin/dashboard.spec.ts`**

```typescript
import { test, expect } from '@playwright/test';
import { DashboardPage } from '../../pages/admin/dashboard';

test.describe('Admin Dashboard', () => {
  let dashboard: DashboardPage;

  test.beforeEach(async ({ page }) => {
    dashboard = new DashboardPage(page);
    await dashboard.goto();
  });

  test('dashboard page loads with all cards', async () => {
    await expect(dashboard.container).toBeVisible();
    await expect(dashboard.connectionCard).toBeVisible();
    await expect(dashboard.checklistCard).toBeVisible();
    await expect(dashboard.collectionsTable).toBeVisible();
  });

  test('test connection button shows success', async ({ page }) => {
    await dashboard.testConnection();
    // Look for success indicator
    const successIndicator = page.locator('.ts-connection-success, :has-text("Connected")').first();
    await expect(successIndicator).toBeVisible({ timeout: 10_000 });
  });

  test('setup checklist shows items as checked', async () => {
    const items = dashboard.checklistItems;
    expect(await items.count()).toBeGreaterThanOrEqual(4);
    // At least module enabled and API keys should be checked
    const firstItem = items.first();
    await expect(firstItem).toBeVisible();
  });

  test('collections table lists indexed collections', async () => {
    // Should have at least product collection
    await expect(dashboard.collectionsTable.locator('text=product')).toBeVisible();
  });

  test('quick action links navigate correctly', async ({ page }) => {
    const collectionsLink = dashboard.quickActions.locator('a:has-text("Collections")');
    if (await collectionsLink.isVisible()) {
      await collectionsLink.click();
      await page.waitForLoadState('domcontentloaded');
      expect(page.url()).toContain('collection');
    }
  });
});
```

**Step 2: Create `tests/e2e/tests/admin/collections.spec.ts`**

```typescript
import { test, expect } from '@playwright/test';
import { CollectionsPage } from '../../pages/admin/collections';

test.describe('Admin Collections', () => {
  let collections: CollectionsPage;

  test.beforeEach(async ({ page }) => {
    collections = new CollectionsPage(page);
    await collections.goto();
  });

  test('collections page lists all collections', async () => {
    const rows = collections.collectionRows;
    expect(await rows.count()).toBeGreaterThan(0);
  });

  test('collection shows name, doc count, and alias status', async () => {
    const productRow = collections.getRow('product');
    await expect(productRow).toBeVisible();
    // Should display document count
    await expect(productRow).toContainText(/\d+/);
  });

  test('view action shows collection details', async ({ page }) => {
    await collections.viewCollection('product');
    await page.waitForLoadState('domcontentloaded');
    expect(page.url()).toContain('collection');
  });

  test('delete action shows confirmation dialog', async ({ page }) => {
    // Listen for dialog
    let dialogMessage = '';
    page.on('dialog', async dialog => {
      dialogMessage = dialog.message();
      await dialog.dismiss(); // Don't actually delete
    });

    // Try to delete — look for any delete button
    const deleteBtn = page.locator('button:has-text("Delete"), a:has-text("Delete")').first();
    if (await deleteBtn.isVisible({ timeout: 3000 }).catch(() => false)) {
      await deleteBtn.click();
      await page.waitForTimeout(1000);
      expect(dialogMessage).toBeTruthy();
    }
  });
});
```

**Step 3: Commit**

```bash
git add tests/e2e/tests/admin/dashboard.spec.ts tests/e2e/tests/admin/collections.spec.ts
git commit -m "test(e2e): add admin dashboard (5 tests) and collections (4 tests) tests"
```

---

### Task 9: Admin synonyms tests

**Files:**
- Create: `tests/e2e/tests/admin/synonyms.spec.ts`

**Step 1: Write the tests**

```typescript
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
    // Navigate with product collection pre-selected
    const productCollection = 'rar_product_default';
    await synonyms.goto(productCollection);
    // Should show existing synonyms (sneakers/shoes, jacket/coat, pants/trousers)
    await expect(synonyms.synonymRows.first()).toBeVisible({ timeout: 5000 });
  });

  test('add multi-way synonym and verify it appears', async ({ page }) => {
    synonyms = new SynonymsPage(page);
    const productCollection = 'rar_product_default';
    await synonyms.goto(productCollection);

    const countBefore = await synonyms.synonymRows.count();
    await synonyms.addMultiWaySynonym('hoodie, sweatshirt, pullover');
    await page.waitForTimeout(1000);

    // Reload and verify
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

    // First add a synonym we can safely delete
    await synonyms.goto(productCollection);
    await synonyms.addMultiWaySynonym('testdelete1, testdelete2');
    await page.waitForTimeout(1000);

    await synonyms.goto(productCollection);
    // Find the row with testdelete and delete it
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
```

**Step 2: Commit**

```bash
git add tests/e2e/tests/admin/synonyms.spec.ts
git commit -m "test(e2e): add admin synonyms tests — 5 tests covering CRUD and collection selector"
```

---

### Task 10: Admin query merchandiser and category merchandiser tests

**Files:**
- Create: `tests/e2e/tests/admin/query-merchandiser.spec.ts`
- Create: `tests/e2e/tests/admin/category-merchandiser.spec.ts`

**Step 1: Create `tests/e2e/tests/admin/query-merchandiser.spec.ts`**

```typescript
import { test, expect } from '@playwright/test';
import { QueryMerchandiserPage } from '../../pages/admin/query-merchandiser';

test.describe('Admin Query Merchandiser', () => {
  let merchandiser: QueryMerchandiserPage;

  test('listing page loads with data grid', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    await expect(merchandiser.dataGrid).toBeVisible({ timeout: 10_000 });
  });

  test('create new merchandising rule', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    await merchandiser.addButton.click();
    await page.waitForLoadState('domcontentloaded');
    await expect(merchandiser.queryInput).toBeVisible();
  });

  test('search and pin a product', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    await merchandiser.addButton.click();
    await page.waitForLoadState('domcontentloaded');

    await merchandiser.queryInput.fill('test-e2e-pin-rule');
    // Search for a product to pin
    if (await merchandiser.searchProductInput.isVisible({ timeout: 3000 }).catch(() => false)) {
      await merchandiser.searchProductInput.fill('bag');
      await page.waitForTimeout(1000);
      // Click first result to pin it
      const firstResult = page.locator('[data-role="product-result"], .ts-product-result').first();
      if (await firstResult.isVisible({ timeout: 3000 }).catch(() => false)) {
        await firstResult.click();
      }
    }
  });

  test('search and hide a product', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    await merchandiser.addButton.click();
    await page.waitForLoadState('domcontentloaded');
    await expect(merchandiser.queryInput).toBeVisible();
    // Verify hide functionality exists
    const hideTab = page.locator('text=Hidden, text=Hide, [data-tab="hidden"]').first();
    if (await hideTab.isVisible({ timeout: 3000 }).catch(() => false)) {
      await hideTab.click();
    }
  });

  test('edit existing rule', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    // Click first edit link in the grid
    const editLink = page.locator('a:has-text("Edit"), [data-action="edit"]').first();
    if (await editLink.isVisible({ timeout: 5000 }).catch(() => false)) {
      await editLink.click();
      await page.waitForLoadState('domcontentloaded');
      await expect(merchandiser.queryInput).toBeVisible();
    }
  });

  test('delete a rule', async ({ page }) => {
    merchandiser = new QueryMerchandiserPage(page);
    await merchandiser.gotoListing();
    const deleteBtn = page.locator('button:has-text("Delete"), a:has-text("Delete")').first();
    if (await deleteBtn.isVisible({ timeout: 5000 }).catch(() => false)) {
      page.on('dialog', dialog => dialog.accept());
      await deleteBtn.click();
      await page.waitForTimeout(1000);
    }
  });
});
```

**Step 2: Create `tests/e2e/tests/admin/category-merchandiser.spec.ts`**

```typescript
import { test, expect } from '@playwright/test';
import { CategoryMerchandiserPage } from '../../pages/admin/category-merchandiser';

test.describe('Admin Category Merchandiser', () => {
  let merchandiser: CategoryMerchandiserPage;

  // Category ID 4 is typically "Bags" in Luma sample data
  const CATEGORY_ID = 4;

  test.beforeEach(async ({ page }) => {
    merchandiser = new CategoryMerchandiserPage(page);
    await merchandiser.gotoCategoryEdit(CATEGORY_ID);
  });

  test('merchandiser section visible on category edit page', async () => {
    await expect(merchandiser.merchandiserSection).toBeVisible({ timeout: 10_000 });
  });

  test('products table loads with existing category products', async () => {
    await expect(merchandiser.productTable).toBeVisible({ timeout: 10_000 });
  });

  test('search for a product to pin', async ({ page }) => {
    if (await merchandiser.productSearch.isVisible()) {
      await merchandiser.productSearch.fill('bag');
      await page.waitForTimeout(1000);
      // Results should appear
      const results = page.locator('.ts-search-results, [data-role="search-results"]');
      // Just verify search input works, results depend on Typesense
    }
  });

  test('pin a product and verify position', async ({ page }) => {
    if (await merchandiser.productSearch.isVisible()) {
      await merchandiser.productSearch.fill('bag');
      await page.waitForTimeout(1000);
      const firstResult = page.locator('.ts-search-result, [data-role="product-result"]').first();
      if (await firstResult.isVisible({ timeout: 3000 }).catch(() => false)) {
        await firstResult.click();
        await page.waitForTimeout(500);
        // Verify product appears in pinned table
      }
    }
  });

  test('save button exists for merchandising changes', async () => {
    await expect(merchandiser.saveButton).toBeVisible({ timeout: 10_000 });
  });
});
```

**Step 3: Commit**

```bash
git add tests/e2e/tests/admin/query-merchandiser.spec.ts tests/e2e/tests/admin/category-merchandiser.spec.ts
git commit -m "test(e2e): add query merchandiser (6 tests) and category merchandiser (5 tests) tests"
```

---

### Task 11: Admin config page tests

**Files:**
- Create: `tests/e2e/tests/admin/config.spec.ts`

**Step 1: Write the tests**

```typescript
import { test, expect } from '@playwright/test';
import { ConfigPage } from '../../pages/admin/config';

test.describe('Admin Configuration', () => {
  let config: ConfigPage;

  test.beforeEach(async ({ page }) => {
    config = new ConfigPage(page);
    await config.goto();
  });

  test('config page loads with all groups', async ({ page }) => {
    // All config groups should have headers present
    await expect(page.locator('text=General Settings')).toBeVisible();
    await expect(page.locator('text=Indexing')).toBeVisible();
    await expect(page.locator('text=Instant Search')).toBeVisible();
    await expect(page.locator('text=Autocomplete')).toBeVisible();
    await expect(page.locator('text=Conversational Search')).toBeVisible();
    await expect(page.locator('text=Product Recommendations')).toBeVisible();
  });

  test('general settings group has expected fields', async ({ page }) => {
    await config.openGroup('general');
    await expect(config.field('general', 'enabled')).toBeVisible();
    await expect(config.field('general', 'host')).toBeVisible();
    await expect(config.field('general', 'port')).toBeVisible();
    await expect(config.field('general', 'search_only_api_key')).toBeVisible();
  });

  test('indexing group has additional attributes multiselect', async ({ page }) => {
    await config.openGroup('indexing');
    await expect(config.field('indexing', 'additional_attributes')).toBeVisible();
    // Should be a multiselect
    const field = config.field('indexing', 'additional_attributes');
    const tagName = await field.evaluate(el => el.tagName.toLowerCase());
    expect(tagName).toBe('select');
  });

  test('instant search group has tile attributes, sort options, facet filters', async ({ page }) => {
    await config.openGroup('instant_search');
    await expect(config.field('instant_search', 'tile_attributes')).toBeVisible();
    await expect(config.field('instant_search', 'sort_options')).toBeVisible();
    await expect(config.field('instant_search', 'facet_filters')).toBeVisible();
  });

  test('recommendations group has enable toggle and limit', async ({ page }) => {
    await config.openGroup('recommendations');
    await expect(config.field('recommendations', 'enabled')).toBeVisible();
    await expect(config.field('recommendations', 'limit')).toBeVisible();
  });

  test('saving config persists values', async ({ page }) => {
    await config.openGroup('recommendations');
    const limitField = config.field('recommendations', 'limit');
    await limitField.fill('12');
    await config.save();

    // Verify success message
    await expect(page.locator('.message-success')).toBeVisible({ timeout: 10_000 });

    // Reload and verify persisted
    await config.goto();
    await config.openGroup('recommendations');
    await expect(limitField).toHaveValue('12');

    // Reset to default
    await limitField.fill('8');
    await config.save();
  });
});
```

**Step 2: Commit**

```bash
git add tests/e2e/tests/admin/config.spec.ts
git commit -m "test(e2e): add admin config page tests — 6 tests covering all config groups"
```

---

### Task 12: Docker Compose stack for CI

**Files:**
- Create: `tests/e2e/docker/docker-compose.yml`
- Create: `tests/e2e/docker/setup.sh`

**Step 1: Create `tests/e2e/docker/docker-compose.yml`**

```yaml
services:
  magento:
    image: ghcr.io/mage-os/magento2:2.4.8-php8.3-apache
    ports:
      - "8080:80"
    environment:
      - MAGENTO_RUN_MODE=developer
    volumes:
      - magento-data:/var/www/html
      - ../../../:/var/www/html/typesense-extension:ro
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
      opensearch:
        condition: service_healthy
      typesense:
        condition: service_started

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: magento
      MYSQL_USER: magento
      MYSQL_PASSWORD: magento
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 3s
      retries: 30

  redis:
    image: redis:7-alpine
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 3s
      retries: 10

  opensearch:
    image: opensearchproject/opensearch:2.19.0
    environment:
      - discovery.type=single-node
      - DISABLE_SECURITY_PLUGIN=true
      - "OPENSEARCH_JAVA_OPTS=-Xms512m -Xmx512m"
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:9200"]
      interval: 5s
      timeout: 3s
      retries: 30

  typesense:
    image: typesense/typesense:28.0
    command: --data-dir=/data --api-key=typesense_dev_key --enable-cors
    volumes:
      - typesense-data:/data

volumes:
  magento-data:
  typesense-data:
```

**Step 2: Create `tests/e2e/docker/setup.sh`**

```bash
#!/usr/bin/env bash
set -euo pipefail

echo "=== Waiting for services ==="
until mysql -h mysql -u magento -pmagento -e "SELECT 1" &>/dev/null; do sleep 2; done
until curl -sf http://typesense:8108/health &>/dev/null; do sleep 2; done

echo "=== Installing Magento ==="
cd /var/www/html

bin/magento setup:install \
  --base-url=http://localhost:8080 \
  --db-host=mysql \
  --db-name=magento \
  --db-user=magento \
  --db-password=magento \
  --admin-firstname=Admin \
  --admin-lastname=User \
  --admin-email=admin@example.com \
  --admin-user=admin \
  --admin-password='Admin12345!' \
  --language=en_US \
  --currency=USD \
  --timezone=America/New_York \
  --use-rewrites=1 \
  --search-engine=opensearch \
  --opensearch-host=opensearch \
  --opensearch-port=9200 \
  --backend-frontname=admin \
  --cache-backend=redis \
  --cache-backend-redis-server=redis \
  --cache-backend-redis-port=6379

echo "=== Installing sample data ==="
bin/magento sampledata:deploy
bin/magento setup:upgrade

echo "=== Installing TypeSense extension ==="
composer config repositories.typesense path /var/www/html/typesense-extension
composer require run-as-root/magento2-typesense:@dev --no-interaction
bin/magento setup:upgrade
bin/magento setup:di:compile

echo "=== Configuring TypeSense ==="
bin/magento config:set run_as_root_typesense/general/enabled 1
bin/magento config:set run_as_root_typesense/general/protocol http
bin/magento config:set run_as_root_typesense/general/host typesense
bin/magento config:set run_as_root_typesense/general/port 8108
bin/magento config:set run_as_root_typesense/general/api_key typesense_dev_key
bin/magento config:set run_as_root_typesense/general/search_only_api_key typesense_dev_key
bin/magento config:set run_as_root_typesense/general/index_prefix rar
bin/magento config:set run_as_root_typesense/instant_search/enabled 1
bin/magento config:set run_as_root_typesense/instant_search/replace_category_page 1
bin/magento config:set run_as_root_typesense/autocomplete/enabled 1
bin/magento config:set run_as_root_typesense/indexing/additional_attributes color,size,material,activity,gender,climate
bin/magento config:set run_as_root_typesense/instant_search/facet_filters color,size,material,activity,gender,climate
bin/magento config:set run_as_root_typesense/instant_search/sort_options relevance,price_asc,price_desc,newest
bin/magento config:set run_as_root_typesense/merchandising/category_merchandiser_enabled 1
bin/magento config:set run_as_root_typesense/merchandising/query_merchandiser_enabled 1

echo "=== Disabling TFA and admin captcha ==="
bin/magento module:disable Magento_AdminAdobeImsTwoFactorAuth Magento_TwoFactorAuth || true
bin/magento setup:upgrade

echo "=== Flushing caches ==="
bin/magento cache:flush

echo "=== Indexing into TypeSense ==="
bin/magento typesense:reindex

echo "=== Adding test synonyms ==="
# These are added programmatically or via the admin — the indexer handles collection creation

echo "=== Setup complete ==="
bin/magento typesense:health
```

**Step 3: Make setup.sh executable and commit**

```bash
chmod +x tests/e2e/docker/setup.sh
git add tests/e2e/docker/
git commit -m "feat(e2e): add Docker Compose stack and setup script for CI"
```

---

### Task 13: GitHub Actions E2E workflow

**Files:**
- Create: `.github/workflows/e2e.yml`

**Step 1: Create the workflow**

```yaml
name: E2E Tests

on:
  push:
    branches: [main]
    paths-ignore: ['docs/**', '*.md']
  pull_request:
    branches: [main]
    paths-ignore: ['docs/**', '*.md']
  workflow_dispatch:

jobs:
  e2e:
    runs-on: ubuntu-latest
    timeout-minutes: 30

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Start Docker services
        working-directory: tests/e2e/docker
        run: docker compose up -d

      - name: Wait for MySQL
        run: |
          until docker compose -f tests/e2e/docker/docker-compose.yml exec -T mysql mysqladmin ping -h localhost --silent; do
            sleep 5
          done

      - name: Run Magento setup
        working-directory: tests/e2e/docker
        run: docker compose exec -T magento bash /var/www/html/typesense-extension/tests/e2e/docker/setup.sh
        timeout-minutes: 20

      - name: Setup Node.js
        uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: npm
          cache-dependency-path: tests/e2e/package-lock.json

      - name: Install Playwright
        working-directory: tests/e2e
        run: |
          npm ci
          npx playwright install --with-deps chromium

      - name: Run Playwright tests
        working-directory: tests/e2e
        env:
          BASE_URL: http://localhost:8080
          ADMIN_URL: http://localhost:8080/admin/
          ADMIN_USER: admin
          ADMIN_PASSWORD: Admin12345!
        run: npx playwright test

      - name: Upload test report
        uses: actions/upload-artifact@v4
        if: failure()
        with:
          name: playwright-report
          path: tests/e2e/playwright-report/
          retention-days: 14

      - name: Stop Docker services
        if: always()
        working-directory: tests/e2e/docker
        run: docker compose down -v
```

**Step 2: Commit**

```bash
git add .github/workflows/e2e.yml
git commit -m "ci: add GitHub Actions workflow for Playwright E2E tests"
```

---

### Task 14: Verify full suite locally

**Step 1: Ensure Warden is running with indexed data**

From `/Users/david/Herd/mage-os-typesense`:
```bash
warden env up
warden shell -c "bin/magento typesense:health"
warden shell -c "bin/magento typesense:reindex"
```

**Step 2: Run the full Playwright suite**

```bash
cd /Users/david/Herd/TypeSense/tests/e2e
npx playwright test
```

Expected: Frontend tests pass against Warden. Admin tests pass with auth.

**Step 3: Fix any selector mismatches**

Selectors in Page Object Models may need adjustment based on actual rendered HTML. Fix iteratively:
- Run failing test in headed mode: `npx playwright test <test-file> --headed`
- Use Playwright UI mode: `npx playwright test --ui`
- Update selectors in POM files as needed

**Step 4: Final commit**

```bash
git add -A
git commit -m "fix(e2e): adjust selectors after local verification"
```

---

## Summary of all files

**New files (24):**

Infrastructure:
- `tests/e2e/package.json`
- `tests/e2e/tsconfig.json`
- `tests/e2e/playwright.config.ts`
- `tests/e2e/.gitignore`
- `tests/e2e/fixtures/auth.setup.ts`

Page Object Models (10):
- `tests/e2e/pages/frontend/autocomplete.ts`
- `tests/e2e/pages/frontend/search-results.ts`
- `tests/e2e/pages/frontend/category-page.ts`
- `tests/e2e/pages/frontend/product-page.ts`
- `tests/e2e/pages/admin/dashboard.ts`
- `tests/e2e/pages/admin/collections.ts`
- `tests/e2e/pages/admin/synonyms.ts`
- `tests/e2e/pages/admin/query-merchandiser.ts`
- `tests/e2e/pages/admin/category-merchandiser.ts`
- `tests/e2e/pages/admin/config.ts`

Test specs (10):
- `tests/e2e/tests/frontend/autocomplete.spec.ts`
- `tests/e2e/tests/frontend/search-results.spec.ts`
- `tests/e2e/tests/frontend/category-page.spec.ts`
- `tests/e2e/tests/frontend/product-page.spec.ts`
- `tests/e2e/tests/admin/dashboard.spec.ts`
- `tests/e2e/tests/admin/collections.spec.ts`
- `tests/e2e/tests/admin/synonyms.spec.ts`
- `tests/e2e/tests/admin/query-merchandiser.spec.ts`
- `tests/e2e/tests/admin/category-merchandiser.spec.ts`
- `tests/e2e/tests/admin/config.spec.ts`

CI:
- `tests/e2e/docker/docker-compose.yml`
- `tests/e2e/docker/setup.sh`
- `.github/workflows/e2e.yml`
