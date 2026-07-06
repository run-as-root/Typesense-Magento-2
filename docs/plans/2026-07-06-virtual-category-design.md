# Virtual Category (Rule-Based Categories)

**Date:** 2026-07-06
**Status:** Approved
**Issue:** [run-as-root/Typesense-Magento-2#13](https://github.com/run-as-root/Typesense-Magento-2/issues/13)

## Overview

Let a category's product membership be computed automatically from attribute conditions ("virtual category") instead of manual assignment — feature parity with Smile ElasticSuite's virtual categories, adapted for Typesense's `filter_by` query syntax instead of Elasticsearch's query DSL.

A virtual category **replaces** manual assignment entirely (a category is either static or virtual, matching ElasticSuite's model) and resolves at **query time** — the rule is translated to a `filter_by` string and swapped in for `category_ids:={id}` in the existing frontend search flow. No reindexing, no product-side data changes.

## Key Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Virtual vs manual | Replace, not augment | Matches ElasticSuite exactly; simpler mental model, matches the literal issue request |
| Resolution strategy | Query-time `filter_by` translation | No reindex needed, rules apply instantly; matches how ElasticSuite itself resolves rules against Elasticsearch |
| Rule builder UI | Reuse `Magento\Rule` engine (extend `Magento\CatalogRule` condition classes) | Native nested AND/OR condition builder merchants already know from Catalog/Cart Price Rules; battle-tested, no custom UI to build |
| Rule condition attributes | Restricted to fields already in the Typesense product schema | Typesense (unlike Elasticsearch) can only `filter_by` indexed schema fields — every saved rule must be guaranteed executable. (Note: ElasticSuite itself does *not* restrict attributes this way, since Elasticsearch indexes everything — this is a deliberate divergence forced by Typesense's schema model, not a parity gap.) |
| Rule storage | New custom table + repository, matching the existing `CategoryMerchandising` pattern | Consistent with this module's established pattern (no EAV attributes used anywhere else in the module); easy to test with the same conventions as `CategoryMerchandisingRepositoryTest` |
| Virtual root | Included in v1 | Category mirrors another category's effective filter (`root filter && own rule`), not a separate tree-cloning mechanism |
| Anchor bleed-up | Replicated (virtual categories always merge child results) | Matches ElasticSuite's documented behavior; applies to virtual categories' own children only, not a change to existing static category behavior |

## Data Model

New table: `run_as_root_typesense_category_virtual_rule`

| Column | Notes |
|--------|-------|
| `category_id` | int, composite key |
| `store_id` | int, composite key (same grain as `category_merchandising`) |
| `is_virtual` | bool — on/off toggle |
| `conditions_serialized` | text — serialized `Magento\Rule` condition tree |
| `virtual_category_root_id` | nullable int — mirrors another category's effective filter |
| `compiled_filter_cache` | text, nullable — cached compiled `filter_by`, invalidated on save (see Caching) |

New interfaces/classes, mirroring existing `CategoryMerchandising*` shape:
- `Api/Data/CategoryVirtualRuleInterface`
- `Api/CategoryVirtualRuleRepositoryInterface`
- `Model/VirtualRule/CategoryVirtualRule` (+ Factory, Repository)
- `Model/ResourceModel/CategoryVirtualRule` (+ Collection)

New module dependency: `Magento_CatalogRule` (sequenced in `module.xml`) — needed for the reusable `Combine`/`Product` condition classes.

## Admin UX

New fieldset in `view/adminhtml/ui_component/category_form.xml` (`typesense_virtual_category`), same `htmlContent` pattern as the existing `typesense_merchandising` fieldset. Backed by `Block/Adminhtml/Category/VirtualRule.php`, extending `Magento\Rule\Block\Conditions` for the native nested AND/OR condition builder. The attribute dropdown is populated only from fields in the Typesense product schema (core fields + configured "additional attributes").

## Query-Time Resolution

### Filter Compilation

`VirtualRule\ConditionsToFilterByCompiler` walks the `Combine`/`Product` condition tree and emits a `filter_by` string:
- `all` combinators join with `&&`, `any` with `||`; nested combines wrap in parentheses
- Operators map: `==` → `:=`, `!=` → `:!=`, `>` → `:>`, `<` → `:<`, `{}` (in) → `:[...]`
- Empty rule (no conditions) compiles to a filter matching **zero** products, never "everything"
- Boolean-valued conditions (`false`/`0`) are handled explicitly — must not be dropped as falsy during compilation (known ElasticSuite bug class, see Testing)

### Anchor Bleed-Up

A virtual category's **effective filter** = its own compiled rule `||`'d with each **direct** child's effective filter, recursively. A child's effective filter already folds in its own descendants, so a parent only ORs in immediate children. Static children contribute `category_ids:={child_id}`; virtual children contribute their own (already-merged) compiled filter.

### Virtual Root

`virtual_category_root_id` makes a category mirror another's subtree for listing purposes: effective filter = *(root category's effective filter)* `&&` *(this category's own rule, if any)* — filter composition, not data duplication.

### Caching & Invalidation

The effective filter is compiled once and cached per category+store. Because anchor bleed-up means a rule edit can change every ancestor's effective filter, saving a virtual rule invalidates the cache for that category **and all its ancestors**. This cascading invalidation is the highest-risk correctness edge in the feature and needs explicit test coverage.

### Merchandising (Pin/Hide) Compatibility

`CategoryMerchandisingSync` currently upserts a Typesense override matched by the literal string `category_ids:={$categoryId}`. Once filter strings are compiled/dynamic per virtual category, string-matching the override rule becomes brittle. Plan: switch the override's match rule to an explicit tag (`cat_merch_{categoryId}`) passed explicitly in frontend search params, rather than relying on Typesense inferring the override from `filter_by` content.

> **Open technical risk:** Typesense 28's exact override-tag API surface has not been verified against source/docs — this is a concrete spike item for the implementation plan, not an assertion of fact.

## Error Handling

- **Unknown/stale attribute** (config changed after rule was saved referencing it): compiler skips the field with a logged warning rather than emitting a `filter_by` that errors on every page load.
- **Boolean/falsy values**: explicit handling so `false`/`0` conditions aren't silently dropped during compilation.
- **Circular virtual root reference** (A mirrors B, B mirrors A): detected and rejected at save time.
- **Empty rule**: compiles to zero matches, not the whole catalog.
- **Compilation/query failure at render time**: category page falls back to an empty result set with a logged error, following the same graceful-degradation pattern conversational search already uses.

## Testing

- Unit: `ConditionsToFilterByCompiler` — one case per operator, nesting, and the boolean edge case (mirrors `CategoryDataBuilderTest` style)
- Unit: recursive anchor-merge resolver, including 3-level-tree cache invalidation cascading to ancestors
- Unit: `CategoryVirtualRuleRepository` (mirrors `CategoryMerchandisingRepositoryTest`)
- E2E (Playwright, matching existing `tests/e2e/tests/admin/category-merchandiser.spec.ts` + `frontend/category-page.spec.ts`): admin rule save flow, frontend listing reflecting rule matches
- PHPStan level 6 + PHPCS, per repo standard

## Rollout

Purely additive — a category with no row in the new table behaves exactly as today (static). No data migration, no behavior change for existing categories. Standard smoke test after merge: `composer update`, `setup:upgrade` (new table), `setup:di:compile`, redis flush, `cache:flush`.

## Research Notes

Findings from deep-research into ElasticSuite's implementation (`Smile-SA/elasticsuite/module-elasticsuite-virtual-category`):
- ElasticSuite's `Rule` model implements `VirtualRuleInterface`, extends its catalog-rule base, and compiles the `Combine`/`Product` condition tree into an Elasticsearch query at search time via a `Layer/Filter/Category` subclass overriding the native category filter with `addQueryFilter()`; root and rule queries merge via `mergeCategoryQueries()`.
- Admin UX lives in the category edit page's "Products in Category" section with real-time rule evaluation and a back-office preview (not yet scoped for this module's v1 — flagged as a fast-follow, not blocking).
- Refuted during verification: rule condition attributes are **not** restricted to "Use in search"/"layered navigation" attributes in ElasticSuite (Typesense forces this restriction on us regardless, per Key Decisions above); virtual categories are **not** a paid/premium feature.
