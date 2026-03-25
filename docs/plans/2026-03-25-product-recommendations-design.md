# Product Recommendations via Typesense Vector Search

**Date:** 2026-03-25
**Status:** Approved

## Overview

Add a "Recommended Products" slider to the product detail page (PDP) powered by Typesense's vector similarity search. Uses existing embeddings from the conversational search feature to find semantically similar products — no additional indexing or external APIs required.

## Architecture

### How It Works

1. Customer visits a PDP
2. Client-side Alpine.js component initializes
3. Queries Typesense using `vector_search` with the current product's embedding
4. Renders results in Hyva's native slider component
5. Current product is excluded from results

### Prerequisites

- Conversational search must be enabled (provides the embedding field in the collection)
- Products must be indexed with embeddings

### Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Similarity method | Vector search (embeddings) | Semantic similarity gives meaningful recommendations without manual curation |
| Query location | Client-side (browser JS) | Consistent with all other frontend features; no server load |
| Embedding dependency | Requires conversational search enabled | Reuses existing embedding config; avoids duplicating setup |
| Slider component | Hyva native slider | No reinventing the wheel; consistent with theme |
| Admin config | Enable toggle + product count | Minimal config, follows existing patterns |

## Frontend Implementation

### Layout

New layout XML: `catalog_product_view.xml`
- Injects recommendation block below product info

### Template

New template: `view/frontend/templates/product/recommendations.phtml`
- Alpine.js `x-data` component registered via `Alpine.data()`
- CSP-safe DOM rendering (same pattern as search/category templates)
- Uses Hyva slider for product carousel
- Product cards: image, name, price, link (same style as category/search grids)

### ViewModel

New: `RecommendationsConfigViewModel`
- Typesense connection config (search-only key, host, protocol, port)
- Collection name for current store
- Current product ID
- Number of recommendations (from admin config)
- Feature enabled flag
- Conversational search enabled check

## Typesense Query

```js
client.collections('rar_product_default').documents().search({
    q: '*',
    vector_query: 'embedding:([], id: <product_id>)',
    filter_by: 'id:!= <product_id> && in_stock:true',
    per_page: <configured_count>,
    exclude_fields: 'embedding'
})
```

- `id: <product_id>` tells Typesense to use that document's existing embedding vector
- Single query, no need to fetch the vector first
- Excludes current product and out-of-stock items
- Omits embedding field from response (large, unnecessary)

## Admin Configuration

New group in `system.xml` under TypeSense Settings: **Product Recommendations**

| Field | Type | Default | Notes |
|-------|------|---------|-------|
| Enable Recommendations | Yes/No | No | Comment: requires conversational search enabled |
| Number of Products | Text (numeric) | 8 | How many recommendations to show |

New methods in `TypeSenseConfig.php`:
- `isRecommendationsEnabled(): bool`
- `getRecommendationsLimit(): int`

## Files to Create/Modify

### New Files
- `view/frontend/templates/product/recommendations.phtml`
- `view/frontend/layout/catalog_product_view.xml`
- `ViewModel/Frontend/RecommendationsConfigViewModel.php`

### Modified Files
- `etc/adminhtml/system.xml` (new config group)
- `Model/Config/TypeSenseConfig.php` (new getters)
- `etc/frontend/di.xml` or existing DI config (ViewModel registration if needed)

## No Changes Required
- No new database tables
- No new indexers or data builders
- No server-side search logic
- No new Typesense collections
