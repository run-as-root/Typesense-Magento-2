// No DOM access and no dependencies, so the same file runs in the browser and under `node --test`.
(function () {
    'use strict';

    const FIELD_NAME = /^[A-Za-z0-9_][A-Za-z0-9_.]*$/;
    const PARAM_NAME = /^[A-Za-z][A-Za-z0-9_]*$/;
    const STATE_PARAMS = ['q', 'page', 'sort', 'limit', 'price'];
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

    const NUMERIC_KINDS = ['int32', 'int64', 'float'];

    function numericKind(numeric) {
        return NUMERIC_KINDS.includes(numeric) ? numeric : null;
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
        const numerics = Object.assign({}, numericFields);
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

    function facetObjects(config) {
        return (config.facets || []).filter(facet => facet && typeof facet === 'object');
    }

    function findFacet(config, field) {
        return facetObjects(config).find(facet => facet.field === field) || null;
    }

    // The URL key of a facet: its `param` alias (e.g. `cat` for category_ids), else the field name.
    function facetParam(facet) {
        const param = facet && facet.param;

        return typeof param === 'string' && PARAM_NAME.test(param) && !STATE_PARAMS.includes(param) ? param : facet.field;
    }

    // `values` is a fixed list of [{value, label}]: only those values are offered and accepted, in that order.
    function listedValues(facet) {
        return facet && Array.isArray(facet.values)
            ? facet.values.filter(item => item && item.value !== undefined && item.value !== null)
            : null;
    }

    function listedLabel(facet, value) {
        const item = listedValues(facet).find(candidate => String(candidate.value) === value);

        return item ? String(item.label) : null;
    }

    function maxFacetValues(config, field) {
        const facet = findFacet(config, field);
        const own = Number(facet && facet.maxValues);

        return Number.isInteger(own) && own > 0 ? own : Number(config.maxFacetValues) || 50;
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
            .filter(facet => facet && typeof facet === 'object' && numericKind(facet.numeric) && facet.field !== 'price');
        for (const facet of facets) {
            kinds[facet.field] = facet.numeric;
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

    // Typesense's popular-queries analytics count every product search that doesn't opt out: only the term a
    // visitor typed may count, never `*`, a re-sent term or the facet and direct-match copies of a search.
    function withoutAnalytics(search) {
        return Object.assign(search, { enable_analytics: false });
    }

    const VISITOR_ID_KEY = 'typesense-visitor-id';
    let pageVisitorId = null;

    function randomId() {
        if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }

        return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
    }

    /**
     * Typesense aggregates the keystrokes of one user ("bir", "birn", "birne") into the query they settled on,
     * identified by the client IP unless told otherwise; behind a proxy every visitor shares one IP. A random
     * id in localStorage carries no personal data; without storage one id per page view still groups keystrokes.
     */
    function visitorId(storage) {
        try {
            const store = storage === undefined ? window.localStorage : storage;
            const stored = store.getItem(VISITOR_ID_KEY);
            if (stored) {
                return stored;
            }
            const id = randomId();
            store.setItem(VISITOR_ID_KEY, id);

            return id;
        } catch (e) {
            pageVisitorId = pageVisitorId || randomId();

            return pageVisitorId;
        }
    }

    /**
     * URL parameters of a multi_search. Typesense reads the user id from the URL or a header only (inside a search
     * the client IP takes precedence); a URL parameter needs no CORS change and is no part of the cache key.
     */
    function searchParams(config, storage) {
        return Object.assign({}, config.cacheParams, { 'x-typesense-user-id': visitorId(storage) });
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
                max_facet_values: maxFacetValues(config, field),
                enable_analytics: false,
            }),
            buildFilterBy(config.baseFilter, context.filters, context.price, [field], context.booleans, context.numerics),
        );
        const valueSearch = String(context.facetQueries[field] || '').trim();
        if (valueSearch !== '') {
            search.facet_query = field + ':' + valueSearch;
        }

        return search;
    }

    /** `recordQuery`: the term is newly searched (page load), not re-sent by paging, sorting or filtering. */
    function buildSearches(state, config, recordQuery = false) {
        const context = searchContext(state, config);
        const page = positivePage(state.page);
        const { q, filters, price, facetQueries, booleans, numerics, common } = context;

        const mainFilter = buildFilterBy(config.baseFilter, filters, price, [], booleans, numerics);
        const main = withFilter(Object.assign({}, common, { page, per_page: resolveLimit(state.limit, config) }), mainFilter);
        const sortBy = buildSortBy(state.sort, config);
        if (sortBy !== '') {
            main.sort_by = sortBy;
        }
        if (!recordQuery || q === '') {
            withoutAnalytics(main);
        }
        const searches = [main];

        // Direct matches can only be merged into page 1 of a relevance-ordered keyword search.
        if (config.context === 'search' && config.directMatchesFirst && q !== '' && page <= 1
            && isRelevance(resolveSort(state.sort, config))) {
            searches.push(withoutAnalytics(Object.assign({}, main, { enable_synonyms: false, page: 1 })));
        }

        const own = [];
        const rest = [];
        const fields = facetFields(config);
        for (const field of fields) {
            const active = field === 'price'
                ? price !== null
                : validValues(filters[field], booleans.includes(field), numerics[field]).length > 0;
            const valueSearch = String(facetQueries[field] || '').trim() !== '';
            // A facet with its own value limit must not raise the limit of the shared search.
            const ownLimit = maxFacetValues(config, field) !== maxFacetValues(config, null);
            (active || valueSearch || ownLimit ? own : rest).push(field);
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
                    max_facet_values: maxFacetValues(config, null),
                    enable_analytics: false,
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

    // `found` is the total of the facet's search, which facetOptions compares the counts with.
    function countsEntry(results, searches, field) {
        const entry = sourceEntry(results, searches, field);
        const result = (results || [])[searches.facetSources[field]];

        return entry === null ? null : Object.assign({}, entry, { found: Number(result && result.found) || 0 });
    }

    function hasFacetSources(searches) {
        return Boolean(searches && searches.facetSources);
    }

    // Reads each facet from the search buildSearches() built for it (its facetSources).
    function mergeFacetCounts(results, state, searches) {
        if (!hasFacetSources(searches)) {
            return [];
        }
        const fields = Object.keys(searches.facetSources);

        return fields.map(field => countsEntry(results, searches, field)).filter(facet => facet !== null);
    }

    function statsOf(facet) {
        const min = Number(facet && facet.stats && facet.stats.min);
        const max = Number(facet && facet.stats && facet.stats.max);
        if (!Number.isFinite(min) || !Number.isFinite(max)) {
            return null;
        }

        // A single price (0.9) would round to 0-1, a range the slider shows although there is nothing to narrow.
        return min === max ? { min: Math.floor(min), max: Math.floor(min) } : { min: Math.floor(min), max: Math.ceil(max) };
    }

    // The facet's own search ignores the active price filter, so it holds the full range.
    function facetStats(results, field, searches) {
        if (!hasFacetSources(searches) || !Object.keys(searches.facetSources).includes(field)) {
            return null;
        }

        return statsOf(sourceEntry(results, searches, field));
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

    // URL key => facet field, for every facet but price.
    function facetParams(config) {
        const params = {};
        const fields = facetFields(config).filter(field => field !== 'price');
        for (const field of fields) {
            const facet = findFacet(config, field);
            params[facet ? facetParam(facet) : field] = field;
        }

        return params;
    }

    function urlValueFilter(config, field) {
        const facet = findFacet(config, field);
        const numeric = numericFacetFields(config)[field];
        const listed = listedValues(facet);
        if (booleanFacetFields(config).includes(field)) {
            return isBooleanValue;
        }
        if (numeric) {
            return value => isNumberValue(value, numeric) && (listed === null || listedLabel(facet, value) !== null);
        }

        return value => quoteValue(value) !== null && (listed === null || listedLabel(facet, value) !== null);
    }

    function parseUrlState(search, config) {
        const state = emptyState(config);
        const params = facetParams(config);
        const seen = [];
        const pairs = rawPairs(search);

        for (const [rawKey, rawValue] of pairs) {
            const key = decode(rawKey);
            if (key === null || seen.includes(key)) {
                continue;
            }
            seen.push(key);

            if (Object.prototype.hasOwnProperty.call(params, key)) {
                const field = params[key];
                // Values are encoded one by one, so a literal | can only be the separator.
                const values = valueList(rawValue.split('|').map(decode))
                    .filter(urlValueFilter(config, field))
                    .slice(0, MAX_VALUES_PER_FACET);
                if (values.length > 0) {
                    state.filters[field] = values;
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

        const params = Object.entries(facetParams(config));
        for (const [param, field] of params) {
            const values = valueList(filters[field]);
            if (values.length > 0) {
                parts.push(encodeURIComponent(param) + '=' + values.map(encodeURIComponent).join('|'));
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
            // 0 means no salable price (e.g. a grouped product without buyable children), not a free product.
            if (showPrices && price !== null && price > 0) {
                item.price = price;
            }
            item.index = start + i;
            item.item_list_name = listName;

            return item;
        });
    }

    // Images above the fold load eagerly for LCP; x-for needs one unique key per tile; _position is the GA4 index
    // on the page (the same order toGa4Items numbers the hits in).
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
                _position: prepared.length,
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
    // Magento's grouped type answers "Please specify the quantity" when a salable child is missing from
    // super_group, so every child of childIds is sent, unselected ones as 0 (as the product page form does).
    function buildCartParams(productId, formKey, uenc, childQuantities, childIds) {
        const params = [['product', String(productId)], ['form_key', String(formKey)], ['uenc', String(uenc)]];
        if (childQuantities === null || childQuantities === undefined) {
            params.push(['qty', '1']);

            return params;
        }

        const quantities = {};
        const allChildIds = childIds || [];
        for (const childId of allChildIds) {
            quantities[childId] = 0;
        }
        const entries = Object.entries(childQuantities);
        let selected = 0;
        for (const [childId, quantity] of entries) {
            const qty = Number(quantity);
            if (Number.isInteger(qty) && qty > 0) {
                quantities[childId] = qty;
                selected++;
            }
        }
        if (selected === 0) {
            return null;
        }

        const quantityEntries = Object.entries(quantities);
        for (const [childId, qty] of quantityEntries) {
            params.push(['super_group[' + childId + ']', String(qty)]);
        }

        return params;
    }

    function optionLabel(facet, value, labels) {
        if (listedValues(facet) !== null) {
            return listedLabel(facet, value);
        }
        if (facet.type === 'checkbox' || facet.boolean === true) {
            return isBooleanValue(value) ? String((labels || {})[value] || value) : null;
        }

        const kind = numericKind(facet.numeric);

        return kind && !isNumberValue(value, kind) ? null : value;
    }

    // Magento's category filter leaves out a category holding every product of the result: it narrows nothing.
    function narrowsResult(facet, entry, count) {
        const found = Number(entry && entry.found);

        return facet.type !== 'category' || !(found > 0) || count < found;
    }

    // A value list keeps its own order: counted values first in that order, then the selected ones without a count.
    function listedOptions(facet, entry, chosen) {
        const counts = {};
        const entries = entry && Array.isArray(entry.counts) ? entry.counts : [];
        for (const item of entries) {
            counts[String(item && item.value)] = Number(item && item.count) || 0;
        }
        const options = [];
        const uncounted = [];
        const listed = listedValues(facet);
        for (const item of listed) {
            const value = String(item.value);
            const selected = chosen.includes(value);
            const counted = Object.prototype.hasOwnProperty.call(counts, value) && counts[value] > 0;
            if (counted && (selected || narrowsResult(facet, entry, counts[value]))) {
                options.push({ value, label: String(item.label), count: counts[value], selected });
            } else if (selected) {
                uncounted.push({ value, label: String(item.label), count: null, selected });
            }
        }

        return options.concat(uncounted);
    }

    // Selected values stay listed without a count, so they can still be unchecked when a value search
    // or max_facet_values leaves them out of the counts.
    function facetOptions(facet, entry, selected, labels) {
        const chosen = valueList(selected);
        if (listedValues(facet) !== null) {
            return listedOptions(facet, entry, chosen);
        }
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

    // Snippets mark matches with <mark>; everything else is document text and must stay text (rendered via x-text).
    function highlightSegments(snippet) {
        const segments = [];
        let marked = false;
        for (const part of String(snippet === undefined || snippet === null ? '' : snippet).split(/(<\/?mark>)/i)) {
            if (/^<mark>$/i.test(part)) {
                marked = true;
            } else if (/^<\/mark>$/i.test(part)) {
                marked = false;
            } else if (part !== '') {
                segments.push({ key: segments.length, text: part, mark: marked, plain: !marked });
            }
        }

        return segments;
    }

    function highlightSnippet(hit, field) {
        const highlight = hit && hit.highlight && hit.highlight[field];
        if (highlight && typeof highlight.snippet === 'string') {
            return highlight.snippet;
        }
        const legacy = ((hit && hit.highlights) || []).find(entry => entry && entry.field === field);

        return legacy && typeof legacy.snippet === 'string' ? legacy.snippet : null;
    }

    function hitSegments(hit, field, fallback, enabled) {
        const snippet = enabled ? highlightSnippet(hit, field) : null;
        if (snippet) {
            return highlightSegments(snippet);
        }
        const text = fallback === undefined || fallback === null ? '' : String(fallback);

        return text === '' ? [] : [{ key: 0, text, mark: false, plain: true }];
    }

    function searchResultUrl(searchUrl, q) {
        const base = String(searchUrl || '');

        return base + (base.includes('?') ? '&' : '?') + 'q=' + encodeURIComponent(String(q));
    }

    function cmsPageUrl(baseUrl, urlKey) {
        const base = String(baseUrl || '').replace(/\/?$/, '/');

        return base + String(urlKey || '').split('/').map(encodeURIComponent).join('/');
    }

    // With group_by, `found` counts groups; the header shows products, so it uses found_docs.
    function flattenGroupedResult(result) {
        if (!result || !Array.isArray(result.grouped_hits)) {
            return result;
        }
        const hits = [];
        for (const group of result.grouped_hits) {
            hits.push(...((group && group.hits) || []));
        }

        return Object.assign({}, result, {
            hits,
            found: result.found_docs !== undefined ? result.found_docs : result.found,
        });
    }

    const AUTOCOMPLETE_LIST_NAME = 'search_suggestions';
    // Everything a product row and its GA4 item read; keystrokes must not fetch children[] or descriptions.
    // Fallback for configs without productFields (AutocompleteConfigViewModel::getProductFields()).
    const AUTOCOMPLETE_PRODUCT_FIELDS = ['id', 'name', 'sku', 'url', 'image_url', 'final_price', 'price'];

    function autocompleteProductFields(config) {
        const fields = Array.isArray(config.productFields) && config.productFields.length > 0
            ? config.productFields
            : AUTOCOMPLETE_PRODUCT_FIELDS;

        return config.groupBy && !fields.includes(config.groupBy) ? fields.concat([config.groupBy]) : fields;
    }
    // Magento's 404 CMS page is indexed like any other page but is no search result.
    const HIDDEN_PAGE_KEYS = ['no-route'];

    // Anyone can make Typesense count any text. Suggestion documents are checked server-side (SuggestionPruner:
    // `is_valid`, deletions) and need a minimum count; the browser repeats the term rules of SuggestionTermFilter.
    const DEFAULT_SUGGESTION_MAX_LENGTH = 60;

    function suggestionFilter(config) {
        return 'is_valid:=true && count:>=' + Math.max(1, Math.floor(Number(config.suggestionMinCount)) || 1);
    }

    function suggestionAllowed(term, config) {
        const text = String(term || '').trim();
        const maxLength = Number(config.suggestionMaxLength) > 0 ? Number(config.suggestionMaxLength) : DEFAULT_SUGGESTION_MAX_LENGTH;
        if (text === '' || Array.from(text).length > maxLength || /@|:\/\/|(^|\s)www\./i.test(text)) {
            return false;
        }
        const letters = (text.match(/\p{L}/gu) || []).length;
        if (2 * letters < (text.match(/\S/gu) || []).length) {
            return false;
        }
        const lower = text.toLowerCase();
        const denylist = Array.isArray(config.suggestionDenylist) ? config.suggestionDenylist : [];

        return !denylist.some(blocked => blocked !== '' && lower.includes(String(blocked).toLowerCase()));
    }

    function buildAutocompleteSearches(q, config) {
        const query = String(q || '').trim();
        const searches = [];
        const sources = { products: 0, direct: null, suggestions: null, categories: null, pages: null };
        const add = search => searches.push(search) - 1;

        const product = Object.assign({}, config.requestParams, {
            collection: config.productCollection,
            q: query,
            filter_by: 'visibility:[3,4]',
            per_page: Number(config.productCount) || 6,
            include_fields: autocompleteProductFields(config).join(','),
        });
        if (config.relevanceSortBy) {
            product.sort_by = config.relevanceSortBy;
        }
        if (config.groupBy) {
            product.group_by = config.groupBy;
            product.group_limit = 1;
        }
        if (query === '') {
            withoutAnalytics(product);
        }
        add(product);
        if (config.directMatchesFirst) {
            sources.direct = add(withoutAnalytics(Object.assign({}, product, { enable_synonyms: false })));
        }
        if (config.suggestionCollection) {
            sources.suggestions = add({
                collection: config.suggestionCollection, q: query, query_by: 'q', filter_by: suggestionFilter(config),
                per_page: Number(config.suggestionCount), enable_analytics: false,
            });
        }
        if (config.categoryCollection) {
            sources.categories = add({
                collection: config.categoryCollection, q: query, query_by: 'name',
                filter_by: 'is_active:=true && level:>1', per_page: Number(config.categoryCount), enable_analytics: false,
            });
        }
        if (config.pageCollection) {
            sources.pages = add({
                collection: config.pageCollection, q: query, query_by: 'title',
                filter_by: 'is_active:=true', per_page: Number(config.pageCount), enable_analytics: false,
            });
        }

        return { searches, sources };
    }

    function sectionHits(results, index) {
        const result = index === null ? null : results[index];

        return result && !result.error && Array.isArray(result.hits) ? result.hits : [];
    }

    function autocompleteRow(type, hit, index, fields) {
        const id = String(hitDocument(hit).id);

        return Object.assign({
            key: type + '-' + id,
            id,
            type,
            index,
            position: 0,
            domId: '',
            imageUrl: '',
            hasImage: false,
            price: null,
            hasPrice: false,
            meta: '',
            hasMeta: false,
            item: null,
        }, fields);
    }

    // Category paths ("A > B") show their last name; whole decimals lose their zeros ("42.0 %" -> "42 %").
    function metaValue(doc, field) {
        const raw = doc[field];
        const value = Array.isArray(raw) ? raw.find(entry => entry !== null && String(entry).trim() !== '') : raw;
        if (value === undefined || value === null || typeof value === 'object') {
            return '';
        }
        let text = String(value).trim();
        if (/^categories\.lvl\d+$/.test(field)) {
            text = text.split(' > ').pop().trim();
        }

        return text.replace(/(\d)\.0+(?!\d)/g, '$1').replace(/(\d\.\d*?[1-9])0+(?!\d)/g, '$1');
    }

    /**
     * One line of document values (e.g. "Old Varieties · 42 % · Pear") for header search rows and cards; lists
     * show their first value, missing or empty fields are left out.
     */
    function metaLine(doc, fields) {
        if (!doc || !Array.isArray(fields)) {
            return '';
        }

        return fields
            .filter(field => FIELD_NAME.test(String(field)))
            .map(field => metaValue(doc, field))
            .filter(text => text !== '')
            .join(' · ');
    }

    function productCard(type, hit, index, config, metaFields) {
        const doc = hitDocument(hit);
        const price = hitPrice(doc);
        const shownPrice = config.showPrices !== false && price !== null && price > 0 ? price : null;
        const meta = metaLine(doc, metaFields);

        return autocompleteRow(type, hit, index, {
            name: String(doc.name || ''),
            url: String(doc.url || ''),
            imageUrl: String(doc.image_url || ''),
            hasImage: Boolean(doc.image_url),
            price: shownPrice,
            hasPrice: shownPrice !== null,
            meta,
            hasMeta: meta !== '',
            item: toGa4Items([hit], index, AUTOCOMPLETE_LIST_NAME, config.showPrices !== false)[0],
        });
    }

    /**
     * Rows of the header dropdown in display order (suggestions, products, categories, pages); `position` is the
     * keyboard index across all sections. Throws the failed product result, so the caller hides the dropdown;
     * a failed side section (e.g. a collection not indexed yet) is only left out.
     */
    function autocompleteResults(results, sources, q, config) {
        const main = results[sources.products];
        if (!main || main.error || (main.code && !main.hits && !main.grouped_hits)) {
            throw main || new Error('Typesense returned no result');
        }
        let products = flattenGroupedResult(main);
        if (sources.direct !== null) {
            products = reorderByDirectMatches(products, flattenGroupedResult(results[sources.direct]), 1);
        }
        const highlight = Boolean(config.highlight);

        const sections = {
            suggestions: sectionHits(results, sources.suggestions)
                .filter(hit => suggestionAllowed(hitDocument(hit).q, config))
                .map((hit, i) => {
                    const doc = hitDocument(hit);
                    return autocompleteRow('suggestion', hit, i, {
                        name: String(doc.q),
                        url: searchResultUrl(config.searchUrl, doc.q),
                        segments: hitSegments(hit, 'q', doc.q, highlight),
                    });
                }),
            products: ((products && products.hits) || []).map((hit, i) => Object.assign(
                productCard('product', hit, i, config, config.productMetaFields),
                { segments: hitSegments(hit, 'name', hitDocument(hit).name, highlight) },
            )).filter(row => row.url !== ''),
            categories: sectionHits(results, sources.categories).map((hit, i) => {
                const doc = hitDocument(hit);
                return autocompleteRow('category', hit, i, {
                    name: String(doc.name || ''),
                    url: String(doc.url || ''),
                    segments: hitSegments(hit, 'name', doc.name, highlight),
                });
            }).filter(row => row.url !== ''),
            pages: sectionHits(results, sources.pages)
                .filter(hit => !HIDDEN_PAGE_KEYS.includes(hitDocument(hit).url_key))
                .map((hit, i) => {
                    const doc = hitDocument(hit);
                    return autocompleteRow('page', hit, i, {
                        name: String(doc.title || ''),
                        url: doc.url_key ? cmsPageUrl(config.baseUrl, doc.url_key) : '',
                        segments: hitSegments(hit, 'title', doc.title, highlight),
                    });
                }).filter(row => row.url !== ''),
        };

        const rows = [...sections.suggestions, ...sections.products, ...sections.categories, ...sections.pages];
        rows.forEach((row, position) => {
            row.position = position;
            row.domId = 'typesense-ac-option-' + position;
        });

        const topHit = ((products && products.hits) || [])[0];

        return {
            sections,
            rows,
            found: Number(products && products.found) || 0,
            topDocument: topHit ? hitDocument(topHit) : null,
        };
    }

    // Catalog visibility, as Magento's own cross-sell block lists them.
    const CROSS_SELL_FILTER = 'in_stock:=true && visibility:[2,4]';
    // Typesense's maximum page size.
    const MAX_PER_PAGE = 250;

    /**
     * Searches for the cross-sell cards of the top hit, sent after its results arrived: its Magento cross-sells
     * (salable, visible in the catalog), and, with a fallback field configured, best sellers sharing one of the
     * top hit's values of that field to fill up. Products shown as rows are left out. Null when nothing is asked.
     */
    function buildCrossSellSearches(top, shownIds, config) {
        const count = Number(config.crossSellCount) || 0;
        if (!top || count <= 0 || !config.productCollection) {
            return null;
        }
        const excluded = valueList([top.id].concat(shownIds || []));
        const linked = valueList(top.crosssell_ids)
            .filter(id => INTEGER_VALUE.test(id) && Number(id) > 0 && !excluded.includes(id));
        const base = {
            collection: config.productCollection,
            q: '*',
            include_fields: autocompleteProductFields(config).join(','),
            enable_analytics: false,
        };
        const searches = [];
        const sources = { crossSells: null, fallback: null, order: linked, count };
        const quoteAll = ids => ids.map(quoteValue).filter(value => value !== null).join(',');

        if (linked.length > 0) {
            sources.crossSells = searches.push(Object.assign({}, base, {
                per_page: Math.min(linked.length, MAX_PER_PAGE),
                filter_by: 'id:[' + quoteAll(linked) + '] && ' + CROSS_SELL_FILTER,
            })) - 1;
        }
        const field = config.crossSellFallbackField;
        const values = field && FIELD_NAME.test(field)
            ? valueList(top[field]).map(quoteValue).filter(value => value !== null)
            : [];
        if (values.length > 0) {
            const fallback = Object.assign({}, base, {
                per_page: Math.min(count, MAX_PER_PAGE),
                filter_by: field + ':=[' + values.join(',') + '] && ' + CROSS_SELL_FILTER
                    + ' && id:!=[' + quoteAll(excluded.concat(linked)) + ']',
            });
            if (config.crossSellFallbackSortBy) {
                fallback.sort_by = config.crossSellFallbackSortBy;
            }
            sources.fallback = searches.push(fallback) - 1;
        }

        return searches.length > 0 ? { searches, sources, key: JSON.stringify(searches) } : null;
    }

    function crossSellResults(results, sources, config) {
        const order = sources.order || [];
        const linked = sectionHits(results, sources.crossSells).slice()
            .sort((a, b) => order.indexOf(String(hitDocument(a).id)) - order.indexOf(String(hitDocument(b).id)));
        const cards = [];
        const seen = [];

        for (const hit of linked.concat(sectionHits(results, sources.fallback))) {
            const doc = hitDocument(hit);
            if (cards.length >= sources.count || seen.includes(String(doc.id)) || !doc.url) {
                continue;
            }
            seen.push(String(doc.id));
            cards.push(productCard('crosssell', hit, cards.length, config, config.crossSellMetaFields));
        }

        return cards;
    }

    function buildPopularSearch(config) {
        const count = Number(config.popularSearchCount) || 0;
        if (count <= 0 || !config.suggestionCollection) {
            return null;
        }

        return {
            collection: config.suggestionCollection,
            q: '*',
            query_by: 'q',
            filter_by: suggestionFilter(config),
            sort_by: 'count:desc',
            per_page: Math.min(count, MAX_PER_PAGE),
            enable_analytics: false,
        };
    }

    function popularResults(result, config) {
        const hits = result && !result.error && Array.isArray(result.hits) ? result.hits : [];

        return hits.filter(hit => suggestionAllowed(hitDocument(hit).q, config)).map((hit, i) => {
            const query = String(hitDocument(hit).q);

            return autocompleteRow('popular', hit, i, { name: query, url: searchResultUrl(config.searchUrl, query) });
        });
    }

    function suggestionClickDetail(row, term) {
        const detail = { type: row.type, term: String(term), name: row.name, url: row.url, index: row.index };
        if (row.item) {
            detail.item = row.item;
        }

        return detail;
    }

    function priceValue(price) {
        return price ? price.min + '-' + price.max : '';
    }

    function filterChanges(prev, next) {
        const before = (prev && prev.filters) || {};
        const after = (next && next.filters) || {};
        const added = [];
        const removed = [];

        for (const field of Object.keys(after)) {
            for (const value of after[field]) {
                if (!(before[field] || []).includes(value)) {
                    added.push({ field, value });
                }
            }
        }
        for (const field of Object.keys(before)) {
            for (const value of before[field]) {
                if (!(after[field] || []).includes(value)) {
                    removed.push({ field, value });
                }
            }
        }
        const prevPrice = priceValue(prev && prev.price);
        const nextPrice = priceValue(next && next.price);
        if (nextPrice !== '' && nextPrice !== prevPrice) {
            added.push({ field: 'price', value: nextPrice });
        } else if (nextPrice === '' && prevPrice !== '') {
            removed.push({ field: 'price', value: prevPrice });
        }

        return { added, removed };
    }

    function urlPath(url) {
        try {
            return new URL(String(url), 'https://localhost').pathname;
        } catch (e) {
            return String(url);
        }
    }

    /**
     * Maps the module's `typesense:*` window events to GA4 dataLayer pushes. Every push is preceded by
     * {ecommerce: null} so GTM does not merge the previous ecommerce object into it.
     */
    function createGa4Tracker() {
        let lastListKey = null;
        const withReset = events => events.flatMap(event => [{ ecommerce: null }, event]);

        const mappers = {
            'typesense:results'(detail) {
                const items = detail.items || [];
                const key = String(detail.context) + '\n' + String(detail.stateKey !== undefined ? detail.stateKey : detail.page);
                if (items.length === 0 || key === lastListKey) {
                    return [];
                }
                lastListKey = key;

                return [{ event: 'view_item_list', ecommerce: { item_list_name: detail.listName, items } }];
            },
            'typesense:select-item'(detail) {
                return detail.item
                    ? [{ event: 'select_item', ecommerce: { item_list_name: detail.listName, items: [detail.item] } }]
                    : [];
            },
            'typesense:suggestion-click'(detail) {
                const event = {
                    event: 'search_suggestion_click',
                    search_term: detail.term,
                    suggestion_type: detail.type,
                    suggestion_name: detail.name,
                    suggestion_url: urlPath(detail.url),
                    index: detail.index,
                };
                if (detail.item) {
                    event.ecommerce = { item_list_name: detail.item.item_list_name, items: [detail.item] };
                }

                return [event];
            },
            'typesense:sort-change'(detail) {
                return [{ event: 'sorting_interaction', sorting_option: detail.sort || 'relevance' }];
            },
            'typesense:filter-change'(detail) {
                const events = (detail.added || []).map(change => ({
                    event: 'filter_interaction', filter_type: change.field, filter_value: change.value,
                }));
                if ((detail.removed || []).length > 0) {
                    events.push({ event: 'filter_interaction', filter_type: 'filter_deactivation', filter_value: '' });
                }

                return events;
            },
        };

        return {
            events(name, detail) {
                const mapper = Object.prototype.hasOwnProperty.call(mappers, name) ? mappers[name] : null;

                return mapper ? withReset(mapper(detail || {})) : [];
            },
        };
    }

    const listing = {
        quoteValue,
        buildFilterBy,
        buildSearches,
        facetValueSearch,
        visitorId,
        searchParams,
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
        highlightSegments,
        hitSegments,
        searchResultUrl,
        cmsPageUrl,
        flattenGroupedResult,
        buildAutocompleteSearches,
        autocompleteResults,
        metaLine,
        buildCrossSellSearches,
        crossSellResults,
        buildPopularSearch,
        popularResults,
        suggestionAllowed,
        suggestionClickDetail,
        filterChanges,
        createGa4Tracker,
    };

    if (typeof window !== 'undefined') {
        window.runAsRootTypesense = window.runAsRootTypesense || {};
        window.runAsRootTypesense.listing = listing;
    }
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = listing;
    }
})();
