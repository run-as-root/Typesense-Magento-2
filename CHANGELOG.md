# Changelog

All notable changes to this project are documented in this file.

## [Unreleased]

### Added

- German decompounding (Stores > Configuration > TypeSense > Search, per store): products get a `name_decompounded` field with the tails of German compound words ("Treppensackkarre" -> "Treppensackkarre Sackkarre Karre"), searchable once it is given a weight under Searchable Attributes. Words of an optional "never split" product attribute (e.g. a product line) are kept whole. Upgrade note: the module now requires `run-as-root/german-compound-splitter` (add its VCS repository until it is on Packagist, see README); run a full reindex after enabling.
- Configurable infix search (fields and `always`/`fallback` mode) and stemming under Stores > Configuration > TypeSense > Search; the `infix` request parameter is aligned with `query_by`. Upgrade note: both are schema flags, so run a full reindex after changing them (stemming is on by default, so existing installs need one after upgrading).
- Admin-configurable weighted searchable attributes (Stores > Configuration > TypeSense > Search); they drive `query_by` and `query_by_weights` for storefront product searches. Defaults: name (10), SKU (9), description (1).

### Fixed

- Partial reindexes (mview/`executeList`) no longer build a new collection and swap the alias to it; they upsert into the live alias and skip with a log when no index exists yet.
- Partial reindexes delete documents for entities that are disabled, removed from the store's website, not visible, or deleted; document ids are mapped per entity type (`EntityIndexerInterface::getDocumentId()`), so prefixed ids (customers, orders, stores, config) are removed too.
- Changing a child product re-syncs its parent products (configurable, grouped, bundle) via `catalog_product_relation`.
- Products are indexed only into the collections of stores whose website they belong to; inactive stores and stores with TypeSense disabled are skipped; indexing runs inside store emulation.
- Autocomplete suggestions query the `query` field (previously the non-existent `q`).
- Synonyms and category/query merchandising overrides survive zero-downtime reindexes: they are re-applied to the new collection before the alias swap.
- Category merchandising pins/hides apply again: the storefront category listing and the override `rule.filter_by` are built by one `CategoryListingFilterBuilder` (category filter + `visibility:[2,4]`), since Typesense matches override filters exactly.
- `typesense_product` mview subscribes to `catalog_product_website` and `catalog_product_relation` (`parent_id`), so removing a product from a website or changing a parent's children triggers a partial reindex.
- Product visibility is filtered on the attribute, not via `catalog_category_product_index`, so a stale category index no longer decides what is indexed and products without a category are indexed.
- Every mview indexer ignores an empty id list instead of running a full reindex.
- A store enabled only at store-view scope is indexed even when TypeSense is disabled at default scope.
- A failed zero-downtime build deletes its half-built collection instead of leaving it behind.
- Deleting a synonym removes it from Typesense first; a Typesense failure leaves the stored synonym intact.

### Added

- `run_as_root_typesense_synonym` table; admin synonyms are persisted in Magento and pushed to Typesense.
- `typesense:collection:rollback --entity=<type> --store=<code>` points an alias back to the previous collection version.
- Partial product reindexes go through the `typesense.reindex` message queue when queue reindex is enabled (message carries `entity_ids`).
- Search settings for text match type, drop/typo token thresholds, minimum word lengths for 1/2 typos, exact-match priority, split/join words, prefix search and the Typesense server-side search cache (`run_as_root_typesense/search/*`, store scope; defaults `max_weight`, 0, 1, 5, 8, yes, `fallback`, yes, cache on with 300 s TTL). The search page, autocomplete products and category listings send them from one `SearchRequestParamsBuilder`; "Enable Typo Tolerance" now sets `num_typos` (2 or 0).

### Changed

- `openai-php/client` constraint widened to `^0.19 || ^0.20` (installs that already lock 0.20 can require the module).
- Product collections now include "Catalog" visibility; the search page and autocomplete filter `visibility:[3,4]`, category listings `visibility:[2,4]`.
- Zero-downtime cleanup keeps the new collection and the one the alias pointed at before the swap (the rollback target) and deletes every other version. Rollback moves to the highest lower version, or to the other remaining version when none is lower.
- A plain (non-versioned) collection that has the alias name is replaced by a versioned collection + alias on the next full reindex.
- `typesense_product` mview batch size is 200; the reindex consumer processes up to 100 messages per run.
- `EntityIndexerInterface` has a new method `getDocumentId(int $entityId): string` — custom indexers must implement it.
- Removed the unused "Minimum Word Length for Typo Tolerance" setting (`search/typo_tolerance_min_word_length`); use the new per-typo minimum word lengths.
- Removed the unused `view/frontend/web/js/instant-search.js` and `autocomplete.js` (the templates carry their own inline scripts).
- `TypeSenseConfigInterface` has new search-parameter getters, and the instant search, autocomplete and category search ViewModels take `SearchRequestParamsBuilder` and `SearchParametersProvider` as new constructor arguments — custom implementations or subclasses must be updated.

### Upgrade notes

- **Full reindex required** after upgrading (`bin/magento typesense:reindex`): product documents are now website-scoped and include catalog-only products.
- Synonyms that exist only in Typesense (created before this version) are not imported into the new table and will be dropped on the next zero-downtime reindex — re-create them in the admin.
- The one-time plain-collection migration deletes the old collection before creating the alias; if the alias creation fails, search for that store is unavailable until the next successful full reindex.
- `typesense:collection:rollback` does not re-apply synonyms/overrides saved after that version was built.
- A synonym saved while a full reindex is finishing may miss the new collection until the next full reindex.
- Partial product updates via the queue need the `typesenseReindexConsumer` consumer running (`cron_consumers_runner` in `env.php`, or a supervisor-managed `bin/magento queue:consumers:start typesenseReindexConsumer`).
- A failed partial update from the queue is retried up to 5 times (republished with an `attempt` counter); after that the ids are logged and picked up by the next full reindex.
- A rollback target holds catalog data from when it was built; partial updates made since then are missing until the next full reindex.
- An inactive store, or one with TypeSense disabled, keeps its old collection; it is neither updated nor deleted.
- With zero-downtime disabled, stored synonyms are not pushed when a collection is (re)created; re-save them in the admin or use zero-downtime mode.
- Installs without zero-downtime reindex must re-save each category's merchandising once after upgrading so stored overrides get the new listing filter (zero-downtime installs refresh them on the required full reindex).

## [0.2.0]

Initial public release.
