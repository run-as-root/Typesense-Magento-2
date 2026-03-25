# Playwright E2E Test Suite Design

**Date:** 2026-03-25
**Status:** Approved

## Overview

Comprehensive Playwright E2E test suite covering all 11 features (5 frontend + 6 admin) of the Typesense Magento 2 extension. Tests run locally against Warden and in CI via a separate GitHub Actions workflow with a dedicated Docker Compose stack.

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| CI strategy | Separate workflow (`.github/workflows/e2e.yml`) | Don't slow down the fast unit test CI feedback loop |
| Test data | Magento sample data (Luma) | Matches Warden dev environment, ~2000 products with real attributes |
| AI features | Test UI behavior only | Verify show/hide/fallback without needing OpenAI key or real embeddings |
| Browser | Chromium only | No need for cross-browser testing for a Magento extension |
| Auth | Playwright storageState | Login once, reuse for all admin tests |
| Environment | Env vars for all URLs/credentials | Works in both Warden and CI without code changes |

## Project Structure

```
tests/e2e/
├── playwright.config.ts
├── package.json
├── fixtures/
│   └── auth.setup.ts              # Admin login, save auth state
├── pages/                         # Page Object Models
│   ├── frontend/
│   │   ├── autocomplete.ts
│   │   ├── search-results.ts
│   │   ├── category-page.ts
│   │   └── product-page.ts
│   └── admin/
│       ├── dashboard.ts
│       ├── collections.ts
│       ├── synonyms.ts
│       ├── query-merchandiser.ts
│       ├── category-merchandiser.ts
│       └── config.ts
├── tests/
│   ├── frontend/
│   │   ├── autocomplete.spec.ts
│   │   ├── search-results.spec.ts
│   │   ├── category-page.spec.ts
│   │   └── product-page.spec.ts
│   └── admin/
│       ├── dashboard.spec.ts
│       ├── collections.spec.ts
│       ├── synonyms.spec.ts
│       ├── query-merchandiser.spec.ts
│       ├── category-merchandiser.spec.ts
│       └── config.spec.ts
└── docker/
    ├── docker-compose.yml
    └── setup.sh
```

## Environment Variables

| Variable | Default (Warden) | CI Override |
|----------|-----------------|-------------|
| `BASE_URL` | `https://app.mage-os-typesense.test` | `http://localhost:8080` |
| `ADMIN_URL` | `https://app.mage-os-typesense.test/backend/admin/` | `http://localhost:8080/admin/` |
| `ADMIN_USER` | `david` | `admin` |
| `ADMIN_PASSWORD` | `Admin12345!` | `Admin12345!` |

## Test Coverage (~55 tests)

### Frontend Tests (~25 tests)

**autocomplete.spec.ts (9 tests)**
- Search input exists on homepage
- Typing 2+ chars opens dropdown
- Shows product results with images, names, prices
- Shows category suggestions
- Shows query suggestions
- Clicking a product navigates to PDP
- Clicking a category navigates to category page
- Clearing input closes dropdown
- No results message for gibberish query

**search-results.spec.ts (10 tests)**
- Searching navigates to `/catalogsearch/result?q=...`
- Products render with images, names, prices, "View Product" buttons
- Facet filters appear in sidebar (categories, color, size, etc.)
- Clicking a facet filters results and updates product count
- Sort dropdown changes product order (price asc/desc, newest)
- Pagination works (next/prev, page indicator)
- Price range slider filters by price
- No results message for nonsense query
- Stats line shows result count and timing
- AI answer box hidden when conversational search disabled

**category-page.spec.ts (6 tests)**
- Category page loads with Typesense-powered listing
- Products display for known category (e.g., "Bags")
- Facet filters work on category page
- Sort options work on category page
- Pagination works
- Price range slider works

**product-page.spec.ts (2 tests)**
- Recommendations section hidden when feature disabled (default state)
- PDP loads without JS errors when recommendations disabled

### Admin Tests (~30 tests)

**dashboard.spec.ts (5 tests)**
- Dashboard page loads with all cards
- Test Connection button shows success
- Setup checklist shows all items checked (green)
- Collections table lists indexed collections with doc counts
- Quick action links navigate correctly

**collections.spec.ts (4 tests)**
- Collections page lists all collections
- Each collection shows name, doc count, alias status
- View action shows collection details
- Delete action shows confirmation dialog

**synonyms.spec.ts (5 tests)**
- Synonym page loads with collection selector
- Existing synonyms display (sneakers/shoes, jacket/coat, pants/trousers)
- Add multi-way synonym and verify it appears
- Add one-way synonym and verify root word displayed
- Delete synonym and verify removal

**query-merchandiser.spec.ts (6 tests)**
- Listing page loads with data grid
- Create new merchandising rule
- Search and pin a product
- Search and hide a product
- Edit existing rule
- Delete a rule

**category-merchandiser.spec.ts (5 tests)**
- Merchandiser tab visible on category edit page
- Products table loads with existing category products
- Search for a product to pin
- Pin a product and verify position
- Save merchandising changes

**config.spec.ts (6 tests)**
- Config page loads with all groups collapsed
- General Settings group has all expected fields
- Indexing group has additional attributes multiselect
- Instant Search group has tile attributes, sort options, facet filters
- Recommendations group has enable toggle and limit field
- Saving config persists values

## Authentication Strategy

Admin auth uses Playwright's `storageState` pattern:
1. `auth.setup.ts` runs as a setup project before all admin tests
2. Logs into admin, saves cookies to `.auth/admin.json`
3. All admin spec files depend on setup project and reuse stored auth
4. Frontend tests need no auth (guest browsing)

## Test Configuration

- **Navigation timeout:** 30s (Magento pages are slow)
- **Typesense API timeout:** 10s
- **Default test timeout:** 60s
- **Retries:** 1 in CI, 0 locally
- **Parallelism:** Spec files run in parallel, tests within a file run sequentially
- **Browser:** Chromium only

## CI Workflow

**File:** `.github/workflows/e2e.yml`

**Triggers:** Push/PR to main, workflow_dispatch (not monthly — too expensive).

**Docker Compose stack** (`tests/e2e/docker/`):
- Magento (Mage-OS 2.2.0, PHP 8.3, Apache) with Hyva + sample data
- Typesense v28.0
- MySQL 8.0
- Redis 7
- OpenSearch 2.x (Magento requires a search engine)

**`setup.sh`** handles:
1. Install Magento with sample data
2. Install the extension from the checked-out repo
3. Configure Typesense connection
4. Enable all features, disable TFA + captcha
5. Run `setup:upgrade`, `di:compile`, `cache:flush`
6. Run `typesense:reindex`
7. Health check — wait for all services ready

**Workflow steps:**
1. Checkout repo
2. `docker compose up -d`
3. Run `setup.sh` inside Magento container
4. Install Playwright + Chromium
5. Run Playwright tests
6. Upload HTML report as artifact on failure

**Estimated CI time:** ~10-15 min.

## Test Isolation

- Destructive tests (delete synonym, delete collection) create their own data first
- Tests don't rely on execution order across spec files
- Each spec file can run independently
- Admin tests share auth state but not data mutations
