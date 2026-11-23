// No DOM access and no dependencies, so the same file runs in the browser and under `node --test`.
(function () {
    'use strict';

    const FIELD_NAME = /^[A-Za-z0-9_][A-Za-z0-9_.]*$/;
    const PRICE_PARAM = /^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/;
    const PAGE_PARAM = /^[1-9]\d{0,5}$/;
    const NUMBER_VALUE = /^-?\d+(?:\.\d+)?$/;
    const INTEGER_VALUE = /^-?\d{1,19}$/;
    const INT32_MIN = -2147483648;
    const INT32_MAX = 2147483647;
    const MAX_VALUES_PER_FACET = 50;
    const MAX_SORT_FIELDS = 3;
    const IN_STOCK_SORT = 'in_stock:desc';
    const NETWORK_CODES = ['ECONNABORTED', 'ECONNREFUSED', 'ECONNRESET', 'ENOTFOUND', 'ETIMEDOUT', 'ERR_NETWORK'];
    const NETWORK_MESSAGE = /network|failed to fetch|load failed|could not be loaded|timeout|timed out|aborted/i;

    // A trailing backslash would escape the closing backtick and silently match nothing.
    function quoteValue(value) {
        const text = String(value);

        return text.includes('`') || text.endsWith('\\') ? null : '`' + text + '`';
    }

    function isBooleanValue(value) {
        return value === 'true' || value === 'false';
    }

    // kind is the Typesense field type: int32, int64 or float (anything else is treated as float).
    function isNumberValue(value, kind) {
        const text = String(value);
        if (kind === 'int32') {
            return INTEGER_VALUE.test(text) && Number(text) >= INT32_MIN && Number(text) <= INT32_MAX;
        }
        if (kind === 'int64') {
            // Compared as BigInt: int64 values beyond 2^53 lose precision as numbers.
            return INTEGER_VALUE.test(text) && BigInt(text) >= -(2n ** 63n) && BigInt(text) < 2n ** 63n;
        }

        return NUMBER_VALUE.test(text) && Number.isFinite(Number(text));
    }

    // Older configs mark numeric facets with `numeric: true` or pass a list of fields; both mean float.
    function numericKind(numeric) {
        return ['int32', 'int64', 'float'].includes(numeric) ? numeric : 'float';
    }

    function numericKinds(numericFields) {
        if (Array.isArray(numericFields)) {
            return Object.fromEntries(numericFields.map(field => [field, 'float']));
        }

        return Object.assign({}, numericFields);
    }

    function pricesShown(config) {
        return config.showPrices !== false;
    }

    function valueList(values) {
        const list = Array.isArray(values) ? values : [values];
        const unique = [];

        for (const value of list) {
            if (value === null || value === undefined) {
                continue;
            }
            const text = String(value);
            if (text !== '' && !unique.includes(text)) {
                unique.push(text);
            }
        }

        return unique;
    }

    function priceRange(price) {
        if (!price) {
            return null;
        }
        const min = Number(price.min);
        const max = Number(price.max);
        if (!Number.isFinite(min) || !Number.isFinite(max)) {
            return null;
        }

        return min <= max ? { min, max } : { min: max, max: min };
    }

    // Typesense answers 400 to any other value on a bool or numeric field, which would fail the whole listing.
    function validValues(values, isBoolean, numeric) {
        const all = valueList(values);
        if (isBoolean) {
            return all.filter(isBooleanValue);
        }

        return numeric ? all.filter(value => isNumberValue(value, numeric)) : all;
    }

    // numeric: the Typesense type of a numeric field (int32, int64, float), else undefined.
    function facetClause(field, values, isBoolean, numeric) {
        const list = validValues(values, isBoolean, numeric);
        if (list.length === 0) {
            return null;
        }
        // Numeric fields reject backtick-quoted values ("Numerical field has an invalid comparator").
        if (numeric && !isBoolean) {
            return field + ':=[' + list.join(',') + ']';
        }

        // Bool values must not be quoted; selecting both true and false filters nothing.
        if (list.every(isBooleanValue)) {
            return list.length === 1 ? field + ':=' + list[0] : null;
        }

        const quoted = list.map(quoteValue).filter(value => value !== null);

        return quoted.length > 0 ? field + ':=[' + quoted.join(',') + ']' : null;
    }

    function buildFilterBy(baseFilter, filters, price, exclude, booleanFields, numericFields) {
        const excluded = [].concat(exclude || []);
        const booleans = [].concat(booleanFields || []);
        const numerics = numericKinds(numericFields);
        const parts = [];
        const base = String(baseFilter || '').trim();

        if (base !== '') {
            parts.push(base);
        }

        const entries = Object.entries(filters || {});
        for (const [field, values] of entries) {
            if (field === 'price' || excluded.includes(field) || !FIELD_NAME.test(field)) {
                continue;
            }
            const clause = facetClause(field, values, booleans.includes(field), numerics[field]);
            if (clause !== null) {
                parts.push(clause);
            }
        }

        const range = excluded.includes('price') ? null : priceRange(price);
        if (range !== null) {
            parts.push('price:[' + range.min + '..' + range.max + ']');
        }

        // && binds tighter than ||, so an OR in the base filter must not absorb the facet clauses. Alone
        // it stays untouched: merchandising overrides only apply when filter_by equals their rule exactly.
        if (base !== '' && parts.length > 1 && base.includes('||')) {
            parts[0] = '(' + base + ')';
        }

        return parts.join(' && ');
    }

    function facetFields(config) {
        return (config.facets || [])
            .map(facet => (typeof facet === 'string' ? facet : facet && facet.field))
            .filter(field => typeof field === 'string' && FIELD_NAME.test(field));
    }

    function booleanFacetFields(config) {
        return (config.facets || [])
            .filter(facet => facet && typeof facet === 'object' && (facet.type === 'checkbox' || facet.boolean === true))
            .map(facet => facet.field);
    }

    // field => int32 | int64 | float, for the facets on numeric fields.
    function numericFacetFields(config) {
        const kinds = {};
        const facets = (config.facets || [])
            .filter(facet => facet && typeof facet === 'object' && facet.numeric && facet.field !== 'price');
        for (const facet of facets) {
            kinds[facet.field] = numericKind(facet.numeric);
        }

        return kinds;
    }

    function sortValues(config) {
        return (config.sortOptions || []).map(option => String(option && option.value !== undefined ? option.value : ''));
    }

    function isRelevance(value) {
        return value === undefined || value === null || value === '' || value === 'relevance';
    }

    function defaultSort(config) {
        const values = sortValues(config);
        const configured = config.defaultSort === undefined || config.defaultSort === null
            ? ''
            : String(config.defaultSort);

        if (values.includes(configured)) {
            return configured;
        }
        if (config.context !== 'category') {
            const relevance = values.find(isRelevance);
            if (relevance !== undefined) {
                return relevance;
            }
        }

        return values.length > 0 ? values[0] : '';
    }

    function resolveSort(sortValue, config) {
        const value = sortValue === undefined || sortValue === null ? '' : String(sortValue);

        return sortValues(config).includes(value) ? value : defaultSort(config);
    }

    // Commas inside _eval([...]) or _text_match(...) belong to that clause, not to the sort_by list.
    function splitSortClauses(sortBy) {
        const clauses = [];
        let depth = 0;
        let quoted = false;
        let current = '';
        const chars = String(sortBy || '');

        for (const char of chars) {
            if (char === '`') {
                quoted = !quoted;
            } else if (!quoted && (char === '(' || char === '[')) {
                depth++;
            } else if (!quoted && (char === ')' || char === ']')) {
                depth--;
            }
            if (char === ',' && depth === 0 && !quoted) {
                clauses.push(current.trim());
                current = '';
                continue;
            }
            current += char;
        }
        clauses.push(current.trim());

        return clauses.filter(clause => clause !== '');
    }

    function buildSortBy(sortValue, config) {
        const value = resolveSort(sortValue, config);
        let base = value;

        if (isRelevance(value)) {
            // Once in_stock leads, Typesense no longer falls back to text match on its own.
            base = config.relevanceSortBy || (config.inStockFirst ? '_text_match:desc' : '');
        }

        const clauses = splitSortClauses(base);
        if (!config.inStockFirst) {
            return clauses.slice(0, MAX_SORT_FIELDS).join(',');
        }

        // Typesense allows at most three sort fields, so in_stock takes the place of the last one.
        const rest = clauses.filter(clause => !/^in_stock\s*:/.test(clause));

        return [IN_STOCK_SORT].concat(rest.slice(0, MAX_SORT_FIELDS - 1)).join(',');
    }

    function perPage(config) {
        const value = Number(config.perPage);

        return Number.isInteger(value) && value > 0 ? value : 24;
    }

    function resolveLimit(limit, config) {
        const value = Number(limit);
        const limits = (config.limits || []).map(Number);

        return Number.isInteger(value) && (value === perPage(config) || limits.includes(value)) ? value : perPage(config);
    }

    function positivePage(page) {
        const value = Number(page);

        return Number.isInteger(value) && value > 0 ? value : 1;
    }

    function searchContext(state, config) {
        const q = String(state.q || '').trim();

        return {
            q,
            filters: state.filters || {},
            price: pricesShown(config) ? priceRange(state.price) : null,
            facetQueries: state.facetQueries || {},
            booleans: booleanFacetFields(config),
            numerics: numericFacetFields(config),
            common: Object.assign({}, config.requestParams, config.cacheParams, {
                collection: config.productCollection,
                q: q || '*',
            }),
        };
    }

    function withFilter(search, filterBy) {
        return filterBy === '' ? search : Object.assign(search, { filter_by: filterBy });
    }

    // Counts for a filtered facet come from a search without its own filter, so its other values stay visible.
    function ownFacetSearch(context, config, field) {
        const search = withFilter(
            Object.assign({}, context.common, {
                per_page: 0,
                facet_by: field,
                max_facet_values: Number(config.maxFacetValues) || 50,
            }),
            buildFilterBy(config.baseFilter, context.filters, context.price, [field], context.booleans, context.numerics),
        );
        const valueSearch = String(context.facetQueries[field] || '').trim();
        if (valueSearch !== '') {
            search.facet_query = field + ':' + valueSearch;
        }

        return search;
    }

    function buildSearches(state, config) {
        const context = searchContext(state, config);
        const page = positivePage(state.page);
        const { q, filters, price, facetQueries, booleans, numerics, common } = context;

        const mainFilter = buildFilterBy(config.baseFilter, filters, price, [], booleans, numerics);
        const main = withFilter(Object.assign({}, common, { page, per_page: resolveLimit(state.limit, config) }), mainFilter);
        const sortBy = buildSortBy(state.sort, config);
        if (sortBy !== '') {
            main.sort_by = sortBy;
        }
        const searches = [main];

        // Direct matches can only be merged into page 1 of a relevance-ordered keyword search.
        if (config.context === 'search' && config.directMatchesFirst && q !== '' && page <= 1
            && isRelevance(resolveSort(state.sort, config))) {
            searches.push(Object.assign({}, main, { enable_synonyms: false, page: 1 }));
        }

        const own = [];
        const rest = [];
        const fields = facetFields(config);
        for (const field of fields) {
            const active = field === 'price'
                ? price !== null
                : validValues(filters[field], booleans.includes(field), numerics[field]).length > 0;
            const valueSearch = String(facetQueries[field] || '').trim() !== '';
            (active || valueSearch ? own : rest).push(field);
        }

        const sources = {};
        for (const field of own) {
            sources[field] = searches.push(ownFacetSearch(context, config, field)) - 1;
        }

        if (rest.length > 0) {
            const index = searches.push(withFilter(
                Object.assign({}, common, {
                    per_page: 0,
                    facet_by: rest.join(','),
                    max_facet_values: Number(config.maxFacetValues) || 50,
                }),
                mainFilter,
            )) - 1;
            for (const field of rest) {
                sources[field] = index;
            }
        }

        // Non-enumerable, so typesense-js never sends it; key order follows the configured facet order.
        const facetSources = {};
        for (const field of fields) {
            facetSources[field] = sources[field];
        }
        Object.defineProperty(searches, 'facetSources', { value: facetSources });

        return searches;
    }

    // A facet value search only needs that facet's own search; the products and other facets stay as they are.
    function facetValueSearch(state, config, field) {
        const fields = facetFields(config).filter(candidate => candidate !== 'price');

        return fields.includes(field) ? ownFacetSearch(searchContext(state, config), config, field) : null;
    }

    function activeFacets(state) {
        const active = [];
        const filterEntries = Object.entries(state.filters || {});
        for (const [field, values] of filterEntries) {
            if (valueList(values).length > 0) {
                active.push(field);
            }
        }
        const queryEntries = Object.entries(state.facetQueries || {});
        for (const [field, text] of queryEntries) {
            if (String(text || '').trim() !== '') {
                active.push(field);
            }
        }
        if (priceRange(state.price) !== null) {
            active.push('price');
        }

        return active;
    }

    function facetEntries(result) {
        if (result && Array.isArray(result.facet_counts)) {
            return result.facet_counts;
        }

        return result && typeof result.field_name === 'string' ? [result] : [];
    }

    function sourceEntry(results, searches, field) {
        const index = searches.facetSources[field];
        const entries = facetEntries((results || [])[index]);

        return entries.find(facet => facet && facet.field_name === field) || null;
    }

    function hasFacetSources(searches) {
        return Boolean(searches && searches.facetSources);
    }

    // Pass the buildSearches() list to read each facet from the search built for it; without it, a
    // single-facet result counts as the facet's own disjunctive search.
    function mergeFacetCounts(results, state, searches) {
        if (hasFacetSources(searches)) {
            const fields = Object.keys(searches.facetSources);

            return fields.map(field => sourceEntry(results, searches, field)).filter(facet => facet !== null);
        }

        const active = activeFacets(state || {});
        const merged = new Map();

        for (const result of results || []) {
            const entries = facetEntries(result);
            const isOwnSearch = Array.isArray(result && result.facet_counts) && entries.length === 1;
            for (const facet of entries) {
                const field = facet && facet.field_name;
                if (typeof field !== 'string') {
                    continue;
                }
                if (!merged.has(field) || (isOwnSearch && active.includes(field))) {
                    merged.set(field, facet);
                }
            }
        }

        return Array.from(merged.values());
    }

    function statsOf(facet) {
        const min = Number(facet && facet.stats && facet.stats.min);
        const max = Number(facet && facet.stats && facet.stats.max);

        return Number.isFinite(min) && Number.isFinite(max) ? { min: Math.floor(min), max: Math.ceil(max) } : null;
    }

    function facetStats(results, field, searches) {
        if (hasFacetSources(searches)) {
            return Object.keys(searches.facetSources).includes(field) ? statsOf(sourceEntry(results, searches, field)) : null;
        }

        let stats = null;

        for (const result of results || []) {
            const entries = facetEntries(result);
            for (const facet of entries) {
                const facetRange = facet && facet.field_name === field ? statsOf(facet) : null;
                // The facet's own search ignores the active price filter, so it holds the full range.
                if (facetRange !== null && (stats === null || entries.length === 1)) {
                    stats = facetRange;
                }
            }
        }

        return stats;
    }

    function rawPairs(search) {
        const query = String(search || '').replace(/^\?/, '');
        const pairs = [];
        const parts = query.split('&');

        for (const part of parts) {
            if (part === '') {
                continue;
            }
            const index = part.indexOf('=');
            pairs.push(index === -1 ? [part, ''] : [part.slice(0, index), part.slice(index + 1)]);
        }

        return pairs;
    }

    function decode(raw) {
        try {
            return decodeURIComponent(raw.replace(/\+/g, ' '));
        } catch (e) {
            return null;
        }
    }

    function emptyState(config) {
        return { q: '', page: 1, sort: defaultSort(config), limit: perPage(config), filters: {}, price: null, facetQueries: {} };
    }

    function parseUrlState(search, config) {
        const state = emptyState(config);
        const fields = facetFields(config).filter(field => field !== 'price');
        const booleans = booleanFacetFields(config);
        const numerics = numericFacetFields(config);
        const seen = [];
        const pairs = rawPairs(search);

        for (const [rawKey, rawValue] of pairs) {
            const key = decode(rawKey);
            if (key === null || seen.includes(key)) {
                continue;
            }
            seen.push(key);

            if (fields.includes(key)) {
                let accept = value => quoteValue(value) !== null;
                if (booleans.includes(key)) {
                    accept = isBooleanValue;
                } else if (numerics[key]) {
                    accept = value => isNumberValue(value, numerics[key]);
                }
                // Values are encoded one by one, so a literal | can only be the separator.
                const values = valueList(rawValue.split('|').map(decode))
                    .filter(accept)
                    .slice(0, MAX_VALUES_PER_FACET);
                if (values.length > 0) {
                    state.filters[key] = values;
                }
                continue;
            }

            const value = decode(rawValue);
            if (value === null) {
                continue;
            }
            if (key === 'q') {
                state.q = value.trim();
            } else if (key === 'page') {
                state.page = PAGE_PARAM.test(value) ? Number(value) : 1;
            } else if (key === 'sort') {
                state.sort = resolveSort(value, config);
            } else if (key === 'limit') {
                state.limit = resolveLimit(value, config);
            } else if (key === 'price' && pricesShown(config)) {
                const match = PRICE_PARAM.exec(value);
                state.price = match ? priceRange({ min: match[1], max: match[2] }) : null;
            }
        }

        return state;
    }

    function serializeUrlState(state, config) {
        const parts = [];
        const q = String(state.q || '').trim();
        const filters = state.filters || {};

        if (q !== '') {
            parts.push('q=' + encodeURIComponent(q));
        }

        const fields = facetFields(config).filter(field => field !== 'price');
        for (const field of fields) {
            const values = valueList(filters[field]);
            if (values.length > 0) {
                parts.push(encodeURIComponent(field) + '=' + values.map(encodeURIComponent).join('|'));
            }
        }

        const range = pricesShown(config) ? priceRange(state.price) : null;
        if (range !== null) {
            parts.push('price=' + range.min + '-' + range.max);
        }

        const sort = resolveSort(state.sort, config);
        if (sort !== defaultSort(config)) {
            parts.push('sort=' + encodeURIComponent(sort));
        }

        const limit = resolveLimit(state.limit, config);
        if (limit !== perPage(config)) {
            parts.push('limit=' + limit);
        }

        const page = positivePage(state.page);
        if (page > 1) {
            parts.push('page=' + page);
        }

        return parts.length > 0 ? '?' + parts.join('&') : '';
    }

    // Ceymox pushed an entry on every fetch, including the first load, so Back needed two presses.
    function historyAction(prev, next, cause) {
        if (cause === 'popstate') {
            return 'none';
        }
        if (cause === 'init') {
            return 'replace';
        }
        if (prev === next) {
            return 'none';
        }

        return cause === 'keyword' ? 'replace' : 'push';
    }

    function paginationWindow(page, totalPages, size = 5) {
        const total = Math.max(0, Math.floor(Number(totalPages) || 0));
        if (total === 0) {
            return [];
        }
        const current = Math.min(positivePage(page), total);
        const length = Math.min(Math.max(1, Math.floor(size)), total);
        const start = Math.max(1, Math.min(current - Math.floor(length / 2), total - length + 1));

        return Array.from({ length }, (_, i) => start + i);
    }

    function clampPage(page, found, perPageValue) {
        const size = Number(perPageValue) > 0 ? Number(perPageValue) : 1;
        const totalPages = Math.max(1, Math.ceil((Number(found) || 0) / size));

        return Math.min(positivePage(page), totalPages);
    }

    function createSequencer() {
        let current = 0;

        return {
            next() {
                current += 1;

                return current;
            },
            isCurrent(id) {
                return id === current;
            },
        };
    }

    function reorderByDirectMatches(main, direct, page) {
        const mainHits = (main && main.hits) || [];
        const directHits = (direct && direct.hits) || [];
        if (mainHits.length === 0 || directHits.length === 0) return main;

        const directIds = new Set(directHits.map(h => h.document.id));
        let hits;
        if ((page || 1) <= 1) {
            // The no-synonym search is capped at the same page size, so slicing keeps the page size.
            hits = directHits.concat(mainHits.filter(h => !directIds.has(h.document.id)))
                .slice(0, mainHits.length);
        } else {
            // The direct search only covers page 1, so later pages can only be regrouped in place.
            hits = mainHits.filter(h => directIds.has(h.document.id))
                .concat(mainHits.filter(h => !directIds.has(h.document.id)));
        }

        return Object.assign({}, main, { hits });
    }

    function hitDocument(hit) {
        return hit && hit.document ? hit.document : hit || {};
    }

    function firstValue(value) {
        return Array.isArray(value) ? value[0] : value;
    }

    function hitPrice(doc) {
        const price = Number(doc.final_price !== undefined && doc.final_price !== null ? doc.final_price : doc.price);

        return Number.isFinite(price) ? price : null;
    }

    // With prices hidden (config.showPrices false) products are listed without offers.
    function buildItemListJsonLd(hits, config) {
        const itemListElement = [];
        const showPrices = config.showPrices !== false;

        for (const hit of hits || []) {
            const doc = hitDocument(hit);
            const sku = firstValue(doc.sku);
            const price = hitPrice(doc);
            // An offer without a positive price is invalid markup (e.g. a grouped product with no salable child).
            if (!doc.name || !sku || (showPrices && (price === null || price <= 0))) {
                continue;
            }

            const product = { '@type': 'Product', name: String(doc.name), sku: String(sku) };
            if (doc.url) {
                product.url = String(doc.url);
            }
            if (doc.image_url) {
                product.image = String(doc.image_url);
            }
            if (showPrices) {
                product.offers = {
                    '@type': 'Offer',
                    price,
                    priceCurrency: config.currencyCode,
                    availability: doc.in_stock === false ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
                };
            }
            const reviewCount = Number(doc.review_count) || 0;
            // Google rejects an AggregateRating with zero reviews.
            if (reviewCount > 0) {
                product.aggregateRating = {
                    '@type': 'AggregateRating',
                    ratingValue: Math.round((Number(doc.rating_summary) || 0) / 20 * 10) / 10,
                    reviewCount,
                };
            }

            itemListElement.push({ '@type': 'ListItem', position: itemListElement.length + 1, item: product });
        }

        return { '@context': 'https://schema.org', '@type': 'ItemList', itemListElement };
    }

    function toGa4Items(hits, offset, listName, showPrices = true) {
        const start = Number(offset) || 0;

        return (hits || []).map((hit, i) => {
            const doc = hitDocument(hit);
            const item = { item_id: String(firstValue(doc.sku) || ''), item_name: String(doc.name || '') };
            const price = hitPrice(doc);
            if (showPrices && price !== null) {
                item.price = price;
            }
            item.index = start + i;
            item.item_list_name = listName;

            return item;
        });
    }

    // Images above the fold load eagerly for LCP; x-for needs one unique key per tile.
    function prepareHits(hits, eagerCount) {
        const eager = Math.max(0, Number(eagerCount) || 0);
        const seen = [];
        const prepared = [];

        for (const hit of hits || []) {
            const key = String(hitDocument(hit).id);
            if (seen.includes(key)) {
                continue;
            }
            seen.push(key);
            const isEager = prepared.length < eager;
            prepared.push(Object.assign({}, hit, {
                _key: key,
                _loading: isEager ? 'eager' : 'lazy',
                _priority: isEager ? 'high' : 'auto',
            }));
        }

        return prepared;
    }

    // Core's wishlist section only lists the last few items; a project can add the full product_ids list.
    function wishlistProductIds(section) {
        const ids = [].concat((section && section.product_ids) || []);
        const items = (section && section.items) || [];
        for (const item of items) {
            ids.push(item && item.product_id);
        }

        return valueList(ids);
    }

    // Grouped products are added per child (super_group[childId]); returns null when no child has a quantity.
    function buildCartParams(productId, formKey, uenc, childQuantities) {
        const params = [['product', String(productId)], ['form_key', String(formKey)], ['uenc', String(uenc)]];
        if (childQuantities === null || childQuantities === undefined) {
            params.push(['qty', '1']);

            return params;
        }

        const entries = Object.entries(childQuantities);
        let selected = 0;
        for (const [childId, quantity] of entries) {
            const qty = Number(quantity);
            if (Number.isInteger(qty) && qty > 0) {
                params.push(['super_group[' + childId + ']', String(qty)]);
                selected++;
            }
        }

        return selected > 0 ? params : null;
    }

    // Subcategory values are full category paths; only direct children of the prefix are offered, by name.
    function optionLabel(facet, value, labels) {
        if (facet.type === 'subcategory') {
            const prefix = String(facet.prefix || '');
            const name = value.startsWith(prefix) ? value.slice(prefix.length) : '';

            return name === '' || name.includes(' > ') ? null : name;
        }
        if (facet.type === 'checkbox' || facet.boolean === true) {
            return isBooleanValue(value) ? String((labels || {})[value] || value) : null;
        }

        return facet.numeric && !isNumberValue(value, numericKind(facet.numeric)) ? null : value;
    }

    // Selected values stay listed without a count, so they can still be unchecked when a value search
    // or max_facet_values leaves them out of the counts.
    function facetOptions(facet, entry, selected, labels) {
        const chosen = valueList(selected);
        const counts = entry && Array.isArray(entry.counts) ? entry.counts : [];
        const options = [];
        const seen = [];
        const add = (value, count) => {
            const label = value === '' || seen.includes(value) ? null : optionLabel(facet, value, labels);
            if (label !== null) {
                seen.push(value);
                options.push({ value, label, count, selected: chosen.includes(value) });
            }
        };

        for (const item of counts) {
            add(String(item && item.value !== undefined && item.value !== null ? item.value : ''), Number(item && item.count) || 0);
        }
        for (const value of chosen) {
            add(value, null);
        }

        return options;
    }

    function activeFilterChips(state, config, labels) {
        const chips = [];
        const filters = (state && state.filters) || {};
        const facets = (config.facets || []).filter(facet => facet && typeof facet === 'object' && facet.field !== 'price');

        for (const facet of facets) {
            const values = valueList(filters[facet.field]);
            for (const value of values) {
                const label = optionLabel(facet, value, labels);
                chips.push({
                    key: JSON.stringify([facet.field, value]),
                    field: facet.field,
                    value,
                    facetLabel: String(facet.label || facet.field),
                    valueLabel: label === null ? value : label,
                });
            }
        }

        return chips;
    }

    // Returns null for the full range, so a slider moved back to both ends removes the price filter.
    function clampPriceRange(min, max, bounds) {
        if (!bounds) {
            return null;
        }
        const clamp = (value, fallback) => {
            const number = Number(value);

            return Number.isFinite(number) && String(value).trim() !== ''
                ? Math.min(Math.max(number, bounds.min), bounds.max)
                : fallback;
        };
        const low = clamp(min, bounds.min);
        const high = clamp(max, bounds.max);
        const range = low <= high ? { min: low, max: high } : { min: high, max: low };

        return range.min <= bounds.min && range.max >= bounds.max ? null : range;
    }

    // typesense-js errors carry httpStatus; a failed search inside multi_search comes back as {code, error}.
    function normalizeError(err) {
        const fallback = 'Search request failed';
        if (err === undefined || err === null) {
            return { kind: 'unknown', status: null, message: fallback };
        }
        if (typeof err === 'string') {
            return { kind: 'unknown', status: null, message: err || fallback };
        }

        const rawStatus = err.httpStatus !== undefined ? err.httpStatus
            : (err.response && err.response.status) || (typeof err.code === 'number' ? err.code : null);
        const status = Number.isInteger(Number(rawStatus)) && Number(rawStatus) > 0 ? Number(rawStatus) : null;
        const message = (typeof err.error === 'string' && err.error)
            || (typeof err.message === 'string' && err.message)
            || fallback;

        let kind = 'unknown';
        if (status !== null && status >= 400 && status < 500) {
            kind = 'client';
        } else if (status !== null && status >= 500) {
            kind = 'server';
        } else if (err.name === 'TypeError' || NETWORK_CODES.includes(err.code) || NETWORK_MESSAGE.test(message)) {
            kind = 'network';
        }

        return { kind, status, message };
    }

    const listing = {
        quoteValue,
        buildFilterBy,
        buildSearches,
        facetValueSearch,
        mergeFacetCounts,
        facetStats,
        buildSortBy,
        defaultSort,
        parseUrlState,
        serializeUrlState,
        historyAction,
        paginationWindow,
        clampPage,
        createSequencer,
        reorderByDirectMatches,
        buildItemListJsonLd,
        toGa4Items,
        normalizeError,
        prepareHits,
        wishlistProductIds,
        buildCartParams,
        facetOptions,
        activeFilterChips,
        clampPriceRange,
    };

    if (typeof window !== 'undefined') {
        window.runAsRootTypesense = window.runAsRootTypesense || {};
        window.runAsRootTypesense.listing = listing;
        // Legacy alias for the header autocomplete until it moves to the listing core.
        window.runAsRootTypesense.reorderByDirectMatches = reorderByDirectMatches;
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = listing;
    }
})();
