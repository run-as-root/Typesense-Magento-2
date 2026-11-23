// No DOM access and no dependencies, so the same file runs in the browser and under `node --test`.
(function () {
    'use strict';

    const FIELD_NAME = /^[A-Za-z0-9_][A-Za-z0-9_.]*$/;
    const PRICE_PARAM = /^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/;
    const PAGE_PARAM = /^[1-9]\d{0,5}$/;
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

    function facetClause(field, values, isBoolean) {
        const all = valueList(values);
        // Typesense answers 400 to any other value on a bool field, which would fail the whole listing.
        const list = isBoolean ? all.filter(isBooleanValue) : all;
        if (list.length === 0) {
            return null;
        }

        // Bool values must not be quoted; selecting both true and false filters nothing.
        if (list.every(isBooleanValue)) {
            return list.length === 1 ? field + ':=' + list[0] : null;
        }

        const quoted = list.map(quoteValue).filter(value => value !== null);

        return quoted.length > 0 ? field + ':=[' + quoted.join(',') + ']' : null;
    }

    function buildFilterBy(baseFilter, filters, price, exclude, booleanFields) {
        const excluded = [].concat(exclude || []);
        const booleans = [].concat(booleanFields || []);
        const parts = [];
        const base = String(baseFilter || '').trim();

        if (base !== '') {
            // && binds tighter than ||, so an OR in the base filter must not absorb the facet clauses.
            parts.push(base.includes('||') ? '(' + base + ')' : base);
        }

        const entries = Object.entries(filters || {});
        for (const [field, values] of entries) {
            if (field === 'price' || excluded.includes(field) || !FIELD_NAME.test(field)) {
                continue;
            }
            const clause = facetClause(field, values, booleans.includes(field));
            if (clause !== null) {
                parts.push(clause);
            }
        }

        const range = excluded.includes('price') ? null : priceRange(price);
        if (range !== null) {
            parts.push('price:[' + range.min + '..' + range.max + ']');
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

    function buildSearches(state, config) {
        const q = String(state.q || '').trim();
        const page = positivePage(state.page);
        const filters = state.filters || {};
        const price = priceRange(state.price);
        const facetQueries = state.facetQueries || {};
        const maxFacetValues = Number(config.maxFacetValues) || 50;
        const common = Object.assign({}, config.requestParams, config.cacheParams, {
            collection: config.productCollection,
            q: q || '*',
        });
        const withFilter = (search, filterBy) => (filterBy === '' ? search : Object.assign(search, { filter_by: filterBy }));

        const booleans = booleanFacetFields(config);
        const mainFilter = buildFilterBy(config.baseFilter, filters, price, [], booleans);
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
            const values = valueList(filters[field]);
            const active = field === 'price'
                ? price !== null
                : (booleans.includes(field) ? values.filter(isBooleanValue) : values).length > 0;
            const valueSearch = String(facetQueries[field] || '').trim() !== '';
            (active || valueSearch ? own : rest).push(field);
        }

        const sources = {};
        // Counts for a filtered facet come from a search without its own filter, so its other values stay visible.
        for (const field of own) {
            const search = withFilter(
                Object.assign({}, common, { per_page: 0, facet_by: field, max_facet_values: maxFacetValues }),
                buildFilterBy(config.baseFilter, filters, price, [field], booleans),
            );
            const valueSearch = String(facetQueries[field] || '').trim();
            if (valueSearch !== '') {
                search.facet_query = field + ':' + valueSearch;
            }
            sources[field] = searches.push(search) - 1;
        }

        if (rest.length > 0) {
            const index = searches.push(withFilter(
                Object.assign({}, common, { per_page: 0, facet_by: rest.join(','), max_facet_values: maxFacetValues }),
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
        const seen = [];
        const pairs = rawPairs(search);

        for (const [rawKey, rawValue] of pairs) {
            const key = decode(rawKey);
            if (key === null || seen.includes(key)) {
                continue;
            }
            seen.push(key);

            if (fields.includes(key)) {
                const accept = booleans.includes(key) ? isBooleanValue : value => quoteValue(value) !== null;
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
            } else if (key === 'price') {
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

        const range = priceRange(state.price);
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

    function buildItemListJsonLd(hits, config) {
        const itemListElement = [];

        for (const hit of hits || []) {
            const doc = hitDocument(hit);
            const sku = firstValue(doc.sku);
            const price = hitPrice(doc);
            // A product without a positive price is invalid markup (e.g. a grouped product with no salable child).
            if (!doc.name || !sku || price === null || price <= 0) {
                continue;
            }

            const product = { '@type': 'Product', name: String(doc.name), sku: String(sku) };
            if (doc.url) {
                product.url = String(doc.url);
            }
            if (doc.image_url) {
                product.image = String(doc.image_url);
            }
            product.offers = {
                '@type': 'Offer',
                price,
                priceCurrency: config.currencyCode,
                availability: doc.in_stock === false ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
            };
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

    function toGa4Items(hits, offset, listName) {
        const start = Number(offset) || 0;

        return (hits || []).map((hit, i) => {
            const doc = hitDocument(hit);
            const item = { item_id: String(firstValue(doc.sku) || ''), item_name: String(doc.name || '') };
            const price = hitPrice(doc);
            if (price !== null) {
                item.price = price;
            }
            item.index = start + i;
            item.item_list_name = listName;

            return item;
        });
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
