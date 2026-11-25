import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const require = createRequire(import.meta.url);
const corePath = fileURLToPath(new URL('../../view/frontend/web/js/listing-core.js', import.meta.url));
const core = require(corePath);

const EVAL = '_eval([ (color:=`Red`):10, (size:=`L`):5 ])';

function searchConfig(overrides = {}) {
    return Object.assign({
        context: 'search',
        productCollection: 'shop_default_products',
        baseFilter: 'visibility:[3,4]',
        facets: [
            { field: 'color', label: 'Color', type: 'dropdown', searchable: true },
            { field: 'size', label: 'Size', type: 'dropdown', searchable: false },
            { field: 'in_stock', label: 'Availability', type: 'checkbox', searchable: false },
            { field: 'price', label: 'Price', type: 'slider', searchable: false },
        ],
        sortOptions: [
            { value: '', label: 'Relevance' },
            { value: 'price:asc', label: 'Price: Low to High' },
            { value: 'price:desc', label: 'Price: High to Low' },
            { value: 'sales_count:desc,rating_summary:desc,created_at:desc', label: 'Popular' },
        ],
        defaultSort: '',
        limits: [12, 24, 48],
        perPage: 24,
        inStockFirst: true,
        requestParams: { query_by: 'name,sku', query_by_weights: '10,9', infix: 'off', prefix: true },
        cacheParams: { use_cache: true, cache_ttl: 60 },
        relevanceSortBy: '_text_match(buckets: 10):desc,' + EVAL + ':desc',
        directMatchesFirst: true,
        maxFacetValues: 50,
        currencyCode: 'EUR',
    }, overrides);
}

function state(overrides = {}) {
    return Object.assign({ q: '', page: 1, sort: '', limit: 24, filters: {}, price: null, facetQueries: {} }, overrides);
}

test('quoteValue wraps commas, spaces and parentheses and rejects backticks', () => {
    assert.equal(core.quoteValue('Birne, Williams'), '`Birne, Williams`');
    assert.equal(core.quoteValue('Gin (Dry) 0,7 l'), '`Gin (Dry) 0,7 l`');
    assert.equal(core.quoteValue('Black-White'), '`Black-White`');
    assert.equal(core.quoteValue('a`b'), null);
    assert.equal(core.quoteValue('grouped\\'), null);
    assert.equal(core.quoteValue('a\\b'), '`a\\b`');

    assert.equal(
        core.buildFilterBy('visibility:[3,4]', { color: ['Birne, Williams', 'a`b', 'Gin (Dry)'] }, null),
        'visibility:[3,4] && color:=[`Birne, Williams`,`Gin (Dry)`]',
    );
    assert.equal(core.buildFilterBy('visibility:[3,4]', { color: ['x`y'] }, null), 'visibility:[3,4]');
    assert.equal(
        core.buildFilterBy('', { type_id: ['grouped\\', 'simple'] }, null),
        'type_id:=[`simple`]',
    );
});

test('buildFilterBy writes booleans unquoted, price as a range and leaves out excluded fields', () => {
    const filters = { in_stock: ['true'], color: ['Red'] };

    assert.equal(
        core.buildFilterBy('', filters, { min: 10, max: 50.5 }),
        'in_stock:=true && color:=[`Red`] && price:[10..50.5]',
    );
    assert.equal(core.buildFilterBy('visibility:[3,4]', filters, { min: 10, max: 50 }, ['color', 'price']),
        'visibility:[3,4] && in_stock:=true');
    assert.equal(core.buildFilterBy('visibility:[3,4]', { 'bad field;': ['x'] }, null), 'visibility:[3,4]');
    assert.equal(core.buildFilterBy('a:=1 || b:=2', {}, { min: 1, max: 2 }), '(a:=1 || b:=2) && price:[1..2]');
});

test('buildFilterBy accepts only true and false on boolean facets', () => {
    assert.equal(core.buildFilterBy('', { in_stock: ['true', 'foo'] }, null, [], ['in_stock']), 'in_stock:=true');
    assert.equal(core.buildFilterBy('', { in_stock: ['false'] }, null, [], ['in_stock']), 'in_stock:=false');
    assert.equal(core.buildFilterBy('v:=1', { in_stock: ['foo'] }, null, [], ['in_stock']), 'v:=1');
    assert.equal(core.buildFilterBy('', { in_stock: ['true', 'false'] }, null, [], ['in_stock']), '');

    const searches = core.buildSearches(state({ filters: { in_stock: ['true', 'foo'], size: ['foo'] } }), searchConfig({
        facets: [
            { field: 'size', type: 'dropdown' },
            { field: 'in_stock', type: 'checkbox' },
            { field: 'is_new', type: 'dropdown', boolean: true },
        ],
    }));
    assert.equal(searches[0].filter_by, 'visibility:[3,4] && in_stock:=true && size:=[`foo`]');

    const onlyHostile = core.buildSearches(state({ filters: { in_stock: ['foo'], is_new: ['1'] } }), searchConfig({
        facets: [{ field: 'in_stock', type: 'checkbox' }, { field: 'is_new', boolean: true }],
    }));
    assert.equal(onlyHostile.length, 2);
    assert.equal(onlyHostile[0].filter_by, 'visibility:[3,4]');
    assert.equal(onlyHostile[1].facet_by, 'in_stock,is_new');
});

test('buildSearches adds disjunctive facet searches with facet_query and search params on both page types', () => {
    const config = searchConfig();
    const searches = core.buildSearches(state({
        q: 'gin',
        filters: { color: ['Red'] },
        price: { min: 10, max: 50 },
        facetQueries: { color: 'bl' },
    }), config);

    assert.equal(searches.length, 5);
    const [main, direct, color, price, rest] = searches;

    assert.equal(main.collection, 'shop_default_products');
    assert.equal(main.q, 'gin');
    assert.equal(main.page, 1);
    assert.equal(main.per_page, 24);
    assert.equal(main.filter_by, 'visibility:[3,4] && color:=[`Red`] && price:[10..50]');
    assert.equal(main.sort_by, 'in_stock:desc,_text_match(buckets: 10):desc,' + EVAL + ':desc');
    assert.equal(main.facet_by, undefined);
    assert.equal(main.facet_query, undefined);

    assert.deepEqual(direct, Object.assign({}, main, { enable_synonyms: false, page: 1 }));

    assert.equal(color.per_page, 0);
    assert.equal(color.facet_by, 'color');
    assert.equal(color.facet_query, 'color:bl');
    assert.equal(color.filter_by, 'visibility:[3,4] && price:[10..50]');
    assert.equal(color.max_facet_values, 50);

    assert.equal(price.facet_by, 'price');
    assert.equal(price.filter_by, 'visibility:[3,4] && color:=[`Red`]');
    assert.equal(price.facet_query, undefined);

    assert.equal(rest.per_page, 0);
    assert.equal(rest.facet_by, 'size,in_stock');
    assert.equal(rest.filter_by, main.filter_by);

    assert.deepEqual(searches.facetSources, { color: 2, size: 4, in_stock: 4, price: 3 });
    assert.equal(JSON.stringify({ searches }).includes('facetSources'), false);

    for (const search of searches) {
        assert.equal(search.query_by, 'name,sku');
        assert.equal(search.query_by_weights, '10,9');
        assert.equal(search.use_cache, true);
        assert.equal(search.cache_ttl, 60);
        assert.equal(search.q, 'gin');
    }

    const categoryConfig = searchConfig({ context: 'category', baseFilter: 'category_ids:=[5] && visibility:[2,4]' });
    const category = core.buildSearches(state({ filters: { size: ['L'] } }), categoryConfig);

    assert.equal(category.length, 3);
    assert.equal(category[0].q, '*');
    assert.equal(category[0].filter_by, 'category_ids:=[5] && visibility:[2,4] && size:=[`L`]');
    assert.equal(category[1].facet_by, 'size');
    assert.equal(category[1].filter_by, 'category_ids:=[5] && visibility:[2,4]');
    assert.equal(category[2].facet_by, 'color,in_stock,price');
    for (const search of category) {
        assert.equal(search.q, '*');
        assert.equal(search.query_by, 'name,sku');
        assert.equal(search.infix, 'off');
        assert.equal(search.use_cache, true);
        assert.equal(search.collection, 'shop_default_products');
    }

    assert.equal(core.buildSearches(state({ q: 'gin', page: 2 }), config).length, 2);
    assert.equal(core.buildSearches(state({ q: 'gin', sort: 'price:asc' }), config).length, 2);
    assert.equal(core.buildSearches(state({ q: 'gin' }), searchConfig({ directMatchesFirst: false })).length, 2);
});

test('mergeFacetCounts reads each facet from its own disjunctive search', () => {
    const filteredColor = { field_name: 'color', counts: [{ value: 'Red', count: 3 }] };
    const ownColor = { field_name: 'color', counts: [{ value: 'Red', count: 3 }, { value: 'Blue', count: 5 }] };
    const size = { field_name: 'size', counts: [{ value: 'L', count: 3 }] };
    const inStock = { field_name: 'in_stock', counts: [{ value: 'true', count: 3 }] };
    const results = [
        { found: 3, hits: [], facet_counts: [filteredColor] },
        { found: 3, hits: [], facet_counts: [] },
        { found: 8, hits: [], facet_counts: [ownColor] },
        { found: 3, hits: [], facet_counts: [size, inStock] },
        { code: 400, error: 'failed' },
    ];

    // Each facet is read from the search built for it, in configured order, even when the main search also
    // returns a single facet.
    const config = searchConfig({ facets: ['color', 'size', 'in_stock'] });
    const current = state({ q: 'gin', filters: { color: ['Red'] } });
    const searches = core.buildSearches(current, config);
    assert.deepEqual(searches.facetSources, { color: 2, size: 3, in_stock: 3 });

    // Each entry carries the total of its search, for options that would not narrow the result.
    const mapped = core.mergeFacetCounts(results.slice(0, 4), current, searches);
    assert.deepEqual(mapped, [{ ...ownColor, found: 8 }, { ...size, found: 3 }, { ...inStock, found: 3 }]);
    assert.deepEqual(core.mergeFacetCounts([results[0], results[1], { code: 500, error: 'down' }, results[3]], current, searches),
        [{ ...size, found: 3 }, { ...inStock, found: 3 }]);
    assert.deepEqual(core.mergeFacetCounts(results, current), [], 'without the search list nothing is guessed');
});

test('facetStats floors min and ceils max', () => {
    const config = searchConfig();
    const searches = core.buildSearches(state({ q: 'gin', price: { min: 20, max: 30 } }), config);
    const ownPrice = { field_name: 'price', counts: [], stats: { min: 4.5, max: 99.2 } };
    const filteredPrice = { field_name: 'price', counts: [], stats: { min: 20, max: 30 } };
    const mapped = [
        { facet_counts: [filteredPrice] },
        { facet_counts: [] },
        { facet_counts: [ownPrice] },
        { facet_counts: [] },
    ];
    assert.equal(searches.facetSources.price, 2);
    assert.deepEqual(core.facetStats(mapped, 'price', searches), { min: 4, max: 100 });
    assert.equal(core.facetStats(mapped, 'weight', searches), null);
    const noStats = [{ facet_counts: [] }, { facet_counts: [] }, { facet_counts: [{ field_name: 'price', counts: [] }] }];
    assert.equal(core.facetStats(noStats, 'price', searches), null);
    assert.equal(core.facetStats(mapped, 'price'), null, 'without the search list nothing is guessed');
});

test('facetStats collapses a single price to one bound, so rounding does not invent a range', () => {
    const config = searchConfig();
    const searches = core.buildSearches(state({ q: 'gin' }), config);
    const single = { field_name: 'price', counts: [], stats: { min: 0.9, max: 0.9 } };
    const results = searches.map(() => ({ facet_counts: [] }));
    results[searches.facetSources.price] = { facet_counts: [single] };

    assert.deepEqual(core.facetStats(results, 'price', searches), { min: 0, max: 0 });

    results[searches.facetSources.price] = { facet_counts: [{ field_name: 'price', counts: [], stats: { min: 0.9, max: 0.95 } }] };
    assert.deepEqual(core.facetStats(results, 'price', searches), { min: 0, max: 1 });
});

test('buildSortBy puts in_stock first and never exceeds three fields', () => {
    const config = searchConfig();

    assert.equal(core.buildSortBy('', config), 'in_stock:desc,_text_match(buckets: 10):desc,' + EVAL + ':desc');
    assert.equal(core.buildSortBy('price:asc', config), 'in_stock:desc,price:asc');
    assert.equal(
        core.buildSortBy('sales_count:desc,rating_summary:desc,created_at:desc', config),
        'in_stock:desc,sales_count:desc,rating_summary:desc',
    );
    assert.equal(core.buildSortBy('bogus:asc', config), core.buildSortBy('', config));

    const plain = searchConfig({ inStockFirst: false, relevanceSortBy: null });
    assert.equal(core.buildSortBy('price:asc', plain), 'price:asc');
    assert.equal(core.buildSortBy('', plain), '');
    assert.equal(
        core.buildSortBy('sales_count:desc,rating_summary:desc,created_at:desc', plain),
        'sales_count:desc,rating_summary:desc,created_at:desc',
    );
    assert.equal(core.buildSortBy('', searchConfig({ relevanceSortBy: null })), 'in_stock:desc,_text_match:desc');
    assert.equal(
        core.buildSortBy('in_stock:desc', searchConfig({ sortOptions: [{ value: 'in_stock:desc' }] })),
        'in_stock:desc',
    );
});

test('buildSortBy keeps in_stock first before a category position sort and its boosting rules', () => {
    const config = searchConfig({
        context: 'category',
        relevanceSortBy: 'category_position_42:asc,' + EVAL + ':desc',
    });

    assert.equal(core.buildSortBy('', config), 'in_stock:desc,category_position_42:asc,' + EVAL + ':desc');
    assert.equal(core.buildSortBy('price:asc', config), 'in_stock:desc,price:asc');
    assert.equal(
        core.buildSortBy('', Object.assign({}, config, { inStockFirst: false })),
        'category_position_42:asc,' + EVAL + ':desc',
    );
});

test('defaultSort uses the configured default when offered, else relevance on search, else the first option', () => {
    assert.equal(core.defaultSort(searchConfig({ defaultSort: 'price:desc' })), 'price:desc');
    assert.equal(core.defaultSort(searchConfig({ defaultSort: 'position:asc' })), '');
    assert.equal(core.defaultSort(searchConfig({
        context: 'category',
        defaultSort: 'position:asc',
        sortOptions: [{ value: 'name:asc' }, { value: 'relevance' }],
    })), 'name:asc');
    assert.equal(core.defaultSort(searchConfig({ sortOptions: [] })), '');
});

test('parseUrlState ignores unknown keys, bad sort, non-numeric and negative page', () => {
    const config = searchConfig();
    const parsed = core.parseUrlState(
        '?q=gin+tonic&page=-3&sort=bogus&color=%3Cscript%3E|Red|Red|&evil=1&__proto__=x&limit=7&price=abc&size=a%60b',
        config,
    );

    assert.equal(parsed.q, 'gin tonic');
    assert.equal(parsed.page, 1);
    assert.equal(parsed.sort, '');
    assert.equal(parsed.limit, 24);
    assert.equal(parsed.price, null);
    assert.deepEqual(Object.keys(parsed.filters), ['color']);
    assert.deepEqual(parsed.filters.color, ['<script>', 'Red']);
    assert.equal(Object.getPrototypeOf(parsed.filters), Object.prototype);

    assert.equal(core.parseUrlState('?page=abc', config).page, 1);
    assert.equal(core.parseUrlState('?page=2.5', config).page, 1);
    assert.equal(core.parseUrlState('?page=0', config).page, 1);
    assert.equal(core.parseUrlState('?page=4', config).page, 4);
    assert.equal(core.parseUrlState('?sort=price%3Adesc&limit=48', config).sort, 'price:desc');
    assert.equal(core.parseUrlState('?limit=48', config).limit, 48);
    assert.deepEqual(core.parseUrlState('?price=50-10', config).price, { min: 10, max: 50 });
    assert.equal(core.parseUrlState('?price=-5-10', config).price, null);
    assert.equal(core.parseUrlState('?color=%E0%A4%A', config).filters.color, undefined);
    assert.equal(core.parseUrlState('?color=a%5C', config).filters.color, undefined);
    assert.equal(core.parseUrlState('?in_stock=foo', config).filters.in_stock, undefined);
    assert.deepEqual(core.parseUrlState('?in_stock=true|foo', config).filters.in_stock, ['true']);
    assert.deepEqual(core.parseUrlState('?in_stock=false', config).filters.in_stock, ['false']);
    assert.deepEqual(core.parseUrlState('', config), state());
});

test('url state round-trips values containing |, - and commas', () => {
    const config = searchConfig();
    const original = state({
        q: 'gin & tonic',
        page: 3,
        sort: 'price:asc',
        limit: 48,
        filters: { color: ['Black|White', 'Black-White', 'Birne, Williams', 'Gin (Dry) 50%'], in_stock: ['true'] },
        price: { min: 10, max: 50.5 },
    });

    const query = core.serializeUrlState(original, config);

    assert.match(query, /^\?q=gin%20%26%20tonic&/);
    assert.ok(query.includes('color=Black%7CWhite|Black-White|Birne%2C%20Williams|Gin%20(Dry)%2050%25'));
    assert.ok(query.includes('price=10-50.5'));

    const parsed = core.parseUrlState(query, config);
    assert.deepEqual(parsed, original);

    assert.equal(core.serializeUrlState(state(), config), '');
    assert.equal(core.serializeUrlState(state({ page: 2, filters: { color: [] } }), config), '?page=2');
});

test('historyAction replaces on initial load and pushes on user change', () => {
    assert.equal(core.historyAction('?page=999', '?page=5', 'init'), 'replace');
    assert.equal(core.historyAction('', '', 'init'), 'replace');
    assert.equal(core.historyAction('?q=gin', '?q=rum', 'keyword'), 'replace');
    assert.equal(core.historyAction('', '?color=Red', 'user'), 'push');
    assert.equal(core.historyAction('?color=Red', '?color=Red', 'user'), 'none');
    assert.equal(core.historyAction('?color=Red', '', 'popstate'), 'none');
});

test('sequencer discards a slower earlier response', async () => {
    const sequencer = core.createSequencer();
    const rendered = [];
    const deferred = () => {
        let resolve;
        const promise = new Promise(done => { resolve = done; });
        return { promise, resolve };
    };

    const fetchAndRender = async response => {
        const id = sequencer.next();
        const result = await response.promise;
        if (sequencer.isCurrent(id)) {
            rendered.push(result);
        }
    };

    const older = deferred();
    const newer = deferred();
    const renders = Promise.all([fetchAndRender(older), fetchAndRender(newer)]);
    newer.resolve('fast, newer');
    await newer.promise;
    older.resolve('slow, older');
    await renders;

    assert.deepEqual(rendered, ['fast, newer']);
    const first = sequencer.next();
    assert.equal(sequencer.isCurrent(first), true);
    sequencer.next();
    assert.equal(sequencer.isCurrent(first), false);
});

test('paginationWindow centres the current page and clamps at both ends', () => {
    assert.deepEqual(core.paginationWindow(1, 10), [1, 2, 3, 4, 5]);
    assert.deepEqual(core.paginationWindow(5, 10), [3, 4, 5, 6, 7]);
    assert.deepEqual(core.paginationWindow(10, 10), [6, 7, 8, 9, 10]);
    assert.deepEqual(core.paginationWindow(99, 10), [6, 7, 8, 9, 10]);
    assert.deepEqual(core.paginationWindow(2, 3), [1, 2, 3]);
    assert.deepEqual(core.paginationWindow(4, 10, 3), [3, 4, 5]);
    assert.deepEqual(core.paginationWindow(1, 0), []);
});

test('clampPage clamps 999 to the last page and 0 to 1', () => {
    assert.equal(core.clampPage(999, 100, 24), 5);
    assert.equal(core.clampPage(0, 100, 24), 1);
    assert.equal(core.clampPage(-2, 100, 24), 1);
    assert.equal(core.clampPage('abc', 100, 24), 1);
    assert.equal(core.clampPage(3, 0, 24), 1);
    assert.equal(core.clampPage(3, 100, 24), 3);
});

const hits = [
    {
        document: {
            name: 'Williams Birne', sku: 'WB-1', url: 'https://shop.test/wb.html', image_url: 'https://shop.test/wb.jpg',
            final_price: 19.9, regular_price: 24.9, in_stock: true, rating_summary: 80, review_count: 4,
        },
    },
    {
        document: {
            name: 'Marille', sku: 'MA-1', url: 'https://shop.test/ma.html', image_url: '',
            final_price: 29, in_stock: false, rating_summary: 0, review_count: 0,
        },
    },
    { document: { name: 'No price', sku: 'NP-1', url: 'https://shop.test/np.html' } },
];

test('buildItemListJsonLd omits AggregateRating without reviews', () => {
    const jsonLd = core.buildItemListJsonLd(hits, searchConfig());

    assert.equal(jsonLd['@context'], 'https://schema.org');
    assert.equal(jsonLd['@type'], 'ItemList');
    assert.equal(jsonLd.itemListElement.length, 2);

    const [first, second] = jsonLd.itemListElement;
    assert.equal(first.position, 1);
    assert.equal(first.item['@type'], 'Product');
    assert.equal(first.item.url, 'https://shop.test/wb.html');
    assert.deepEqual(first.item.offers, {
        '@type': 'Offer', price: 19.9, priceCurrency: 'EUR', availability: 'https://schema.org/InStock',
    });
    assert.deepEqual(first.item.aggregateRating, { '@type': 'AggregateRating', ratingValue: 4, reviewCount: 4 });

    assert.equal(second.position, 2);
    assert.equal(second.item.offers.availability, 'https://schema.org/OutOfStock');
    assert.equal('aggregateRating' in second.item, false);
    assert.equal('image' in second.item, false);
});

test('toGa4Items continues index across pages', () => {
    const items = core.toGa4Items(hits.slice(0, 2), 24, 'Search Results');

    assert.deepEqual(items, [
        { item_id: 'WB-1', item_name: 'Williams Birne', price: 19.9, index: 24, item_list_name: 'Search Results' },
        { item_id: 'MA-1', item_name: 'Marille', price: 29, index: 25, item_list_name: 'Search Results' },
    ]);
    assert.deepEqual(core.toGa4Items([], 0, 'x'), []);
});

test('normalizeError turns typesense 4xx and network errors into a message', () => {
    const malformed = Object.assign(new Error('Request failed with HTTP code 400 | Server said: Could not find a field named `foo`.'), {
        name: 'RequestMalformed', httpStatus: 400,
    });
    assert.deepEqual(core.normalizeError(malformed), {
        kind: 'client', status: 400, message: 'Request failed with HTTP code 400 | Server said: Could not find a field named `foo`.',
    });

    assert.deepEqual(core.normalizeError({ code: 404, error: 'Not found.' }), { kind: 'client', status: 404, message: 'Not found.' });
    assert.equal(core.normalizeError(Object.assign(new Error('Bad gateway'), { httpStatus: 503 })).kind, 'server');

    const network = core.normalizeError(new TypeError('Failed to fetch'));
    assert.equal(network.kind, 'network');
    assert.equal(network.status, null);
    assert.equal(network.message, 'Failed to fetch');
    assert.equal(core.normalizeError(Object.assign(new Error('timeout of 10000ms exceeded'), { code: 'ECONNABORTED' })).kind, 'network');

    const unknown = core.normalizeError(undefined);
    assert.equal(unknown.kind, 'unknown');
    assert.equal(typeof unknown.message, 'string');
    assert.ok(unknown.message.length > 0);
});

test('reorderByDirectMatches keeps its behaviour and only the listing namespace exposes it', () => {
    const doc = id => ({ document: { id } });
    const main = { found: 4, hits: [doc('a'), doc('b'), doc('c'), doc('d')] };
    const direct = { hits: [doc('c'), doc('x')] };

    assert.deepEqual(core.reorderByDirectMatches(main, direct, 1).hits.map(h => h.document.id), ['c', 'x', 'a', 'b']);
    assert.deepEqual(core.reorderByDirectMatches(main, direct, 2).hits.map(h => h.document.id), ['c', 'a', 'b', 'd']);
    assert.equal(core.reorderByDirectMatches(main, { hits: [] }, 1), main);
    assert.equal(core.reorderByDirectMatches(main, direct, 1).found, 4);

    const sandbox = { window: { runAsRootTypesense: { getClient: () => null } } };
    vm.runInNewContext(readFileSync(corePath, 'utf8'), sandbox);
    assert.equal(typeof sandbox.window.runAsRootTypesense.getClient, 'function');
    assert.equal(typeof sandbox.window.runAsRootTypesense.listing.buildSearches, 'function');
    assert.equal(typeof sandbox.window.runAsRootTypesense.listing.reorderByDirectMatches, 'function');
    assert.equal(sandbox.window.runAsRootTypesense.reorderByDirectMatches, undefined);
});

test('buildFilterBy keeps a lone OR base filter byte-identical so merchandising overrides still match', () => {
    const base = '(category_ids:[3] || color:=`Red`) && visibility:[2,4]';

    assert.equal(core.buildFilterBy(base, {}, null), base);
    assert.equal(core.buildFilterBy(base, { color: ['Red'] }, null), '(' + base + ') && color:=[`Red`]');
});

test('toGa4Items sends a price only when it is above 0', () => {
    const items = core.toGa4Items([
        { document: { sku: 'A', name: 'A', final_price: 0 } },
        { document: { sku: 'B', name: 'B' } },
        { document: { sku: 'C', name: 'C', final_price: 12.5 } },
    ], 0, 'Gin');

    assert.deepEqual(items.map(item => item.price), [undefined, undefined, 12.5]);
    assert.equal('price' in items[0], false);
});

test('buildItemListJsonLd and toGa4Items leave prices out when prices are hidden', () => {
    const jsonLd = core.buildItemListJsonLd(hits, searchConfig({ showPrices: false }));

    assert.equal(jsonLd.itemListElement.length, 3);
    for (const element of jsonLd.itemListElement) {
        assert.equal('offers' in element.item, false);
    }
    assert.deepEqual(core.toGa4Items(hits.slice(0, 1), 0, 'Gin', false), [
        { item_id: 'WB-1', item_name: 'Williams Birne', index: 0, item_list_name: 'Gin' },
    ]);
});

test('prepareHits marks the first images eager, keys every hit once and numbers the kept hits', () => {
    const prepared = core.prepareHits([
        { document: { id: '1' } }, { document: { id: '2' } }, { document: { id: '1' } }, { document: { id: '3' } },
    ], 2);

    assert.deepEqual(prepared.map(hit => [hit._key, hit._loading, hit._priority, hit._position]), [
        ['1', 'eager', 'high', 0], ['2', 'eager', 'high', 1], ['3', 'lazy', 'auto', 2],
    ]);
    assert.deepEqual(core.prepareHits(undefined, 4), []);
});

test('wishlistProductIds reads product ids from the wishlist customer section', () => {
    assert.deepEqual(core.wishlistProductIds({ items: [{ product_id: 5 }, { product_id: '7' }, { product_id: 5 }, {}] }), ['5', '7']);
    assert.deepEqual(core.wishlistProductIds({ product_ids: [9, 10], items: [{ product_id: 5 }] }), ['9', '10', '5']);
    assert.deepEqual(core.wishlistProductIds(undefined), []);
});

test('buildCartParams posts qty 1 for a simple tile and super_group for grouped children', () => {
    assert.deepEqual(core.buildCartParams('42', 'fk', 'uenc', null), [
        ['product', '42'], ['form_key', 'fk'], ['uenc', 'uenc'], ['qty', '1'],
    ]);
    assert.deepEqual(core.buildCartParams('42', 'fk', 'uenc', { 7: 2, 8: 0, 9: '3', 10: -1, 11: 'x' }), [
        ['product', '42'], ['form_key', 'fk'], ['uenc', 'uenc'], ['super_group[7]', '2'], ['super_group[9]', '3'],
    ]);
    assert.equal(core.buildCartParams('42', 'fk', 'uenc', { 7: 0 }), null);
});

test('buildCartParams sends every grouped child, unselected ones as 0, as Magento rejects a partial super_group', () => {
    assert.deepEqual(core.buildCartParams('42', 'fk', 'uenc', { 9: 2 }, [7, 9, '11']), [
        ['product', '42'], ['form_key', 'fk'], ['uenc', 'uenc'],
        ['super_group[7]', '0'], ['super_group[9]', '2'], ['super_group[11]', '0'],
    ]);
    assert.equal(core.buildCartParams('42', 'fk', 'uenc', {}, [7, 9]), null);
});

const yesNo = { true: 'Yes', false: 'No' };

test('facetOptions lists counted values, keeps selected values without a count and labels booleans', () => {
    const color = { field: 'color', type: 'dropdown', boolean: false };
    const entry = { field_name: 'color', counts: [{ value: 'Black-White', count: 4 }, { value: '', count: 1 }, { value: 'Red', count: 2 }] };

    assert.deepEqual(core.facetOptions(color, entry, ['Red', 'Blue (dark)'], yesNo), [
        { value: 'Black-White', label: 'Black-White', count: 4, selected: false },
        { value: 'Red', label: 'Red', count: 2, selected: true },
        { value: 'Blue (dark)', label: 'Blue (dark)', count: null, selected: true },
    ]);

    const isNew = { field: 'is_new', type: 'checkbox', boolean: true };
    const flags = { field_name: 'is_new', counts: [{ value: 'true', count: 3 }, { value: 'false', count: 9 }, { value: 'x', count: 1 }] };
    assert.deepEqual(core.facetOptions(isNew, flags, ['true'], yesNo), [
        { value: 'true', label: 'Yes', count: 3, selected: true },
        { value: 'false', label: 'No', count: 9, selected: false },
    ]);
    assert.deepEqual(core.facetOptions(color, null, [], yesNo), []);
});

function categoryFacet(overrides = {}) {
    return Object.assign({
        field: 'category_ids',
        param: 'cat',
        label: 'Category',
        type: 'category',
        searchable: false,
        boolean: false,
        numeric: 'int32',
        values: [{ value: '22', label: 'Kräuterlikör' }, { value: '7', label: 'Schnaps & Likör' }, { value: '9', label: 'Punsch' }],
        maxValues: 350,
    }, overrides);
}

test('facetOptions of a value list keep its order and names and drop every other value', () => {
    const facet = categoryFacet();
    // Products also carry the ids of ancestors and other trees, ranked by count.
    const entry = { field_name: 'category_ids', found: 12, counts: [
        { value: '2', count: 12 },
        { value: '7', count: 5 },
        { value: '40', count: 4 },
        { value: '22', count: 3 },
    ] };

    assert.deepEqual(core.facetOptions(facet, entry, [], yesNo), [
        { value: '22', label: 'Kräuterlikör', count: 3, selected: false },
        { value: '7', label: 'Schnaps & Likör', count: 5, selected: false },
    ]);
    assert.deepEqual(core.facetOptions(facet, entry, ['9', '40'], yesNo), [
        { value: '22', label: 'Kräuterlikör', count: 3, selected: false },
        { value: '7', label: 'Schnaps & Likör', count: 5, selected: false },
        { value: '9', label: 'Punsch', count: null, selected: true },
    ]);
    assert.deepEqual(core.facetOptions(facet, null, [], yesNo), []);
});

test('category options that would not narrow the result are hidden unless selected, as in Magento', () => {
    const facet = categoryFacet();
    const entry = { field_name: 'category_ids', found: 5, counts: [{ value: '7', count: 5 }, { value: '22', count: 3 }] };

    assert.deepEqual(core.facetOptions(facet, entry, [], yesNo).map(option => option.value), ['22']);
    assert.deepEqual(core.facetOptions(facet, entry, ['7'], yesNo).map(option => option.value), ['22', '7']);
    // Without the total of its search nothing is hidden.
    assert.deepEqual(core.facetOptions(facet, { field_name: 'category_ids', counts: entry.counts }, [], yesNo)
        .map(option => option.value), ['22', '7']);
});

test('a facet param is the URL key of its field and accepts only listed values', () => {
    const config = searchConfig({ facets: [categoryFacet(), { field: 'color', label: 'Color', type: 'dropdown' }] });

    const parsed = core.parseUrlState('?cat=7|abc|40|22|7&category_ids=9&categories.lvl0=Gin&color=Red', config);
    assert.deepEqual(parsed.filters, { category_ids: ['7', '22'], color: ['Red'] });
    assert.deepEqual(core.parseUrlState('?cat=40', config).filters, {});

    const query = core.serializeUrlState(state({ filters: { category_ids: ['22', '7'], color: ['Red'] } }), config);
    assert.equal(query, '?cat=22|7&color=Red');
    assert.deepEqual(core.parseUrlState(query, config).filters, { category_ids: ['22', '7'], color: ['Red'] });

    // A param that is not a plain URL key, or that a fixed listing parameter already uses, falls back to the field.
    const odd = searchConfig({ facets: [categoryFacet({ param: 'a b' }), { field: 'color', param: 'page', type: 'dropdown' }] });
    assert.equal(core.serializeUrlState(state({ filters: { category_ids: ['7'], color: ['Red'] } }), odd),
        '?category_ids=7&color=Red');
});

test('a facet with its own value limit gets its own search, with or without an active filter', () => {
    const config = searchConfig({ facets: [categoryFacet(), { field: 'color', type: 'dropdown' }, { field: 'size', type: 'dropdown' }] });

    const idle = core.buildSearches(state({ q: 'gin', page: 2 }), config);
    assert.equal(idle.length, 3);
    assert.equal(idle[1].facet_by, 'category_ids');
    assert.equal(idle[1].max_facet_values, 350);
    assert.equal(idle[1].filter_by, 'visibility:[3,4]');
    assert.equal(idle[2].facet_by, 'color,size');
    assert.equal(idle[2].max_facet_values, 50);
    assert.deepEqual(idle.facetSources, { category_ids: 1, color: 2, size: 2 });

    const active = core.buildSearches(state({ q: 'gin', page: 2, filters: { category_ids: ['7'], color: ['Red'] } }), config);
    assert.equal(active[0].filter_by, 'visibility:[3,4] && category_ids:=[7] && color:=[`Red`]');
    assert.equal(active[1].facet_by, 'category_ids');
    assert.equal(active[1].max_facet_values, 350);
    assert.equal(active[1].filter_by, 'visibility:[3,4] && color:=[`Red`]');
    assert.equal(active[2].facet_by, 'color');
    assert.equal(active[2].max_facet_values, 50);

    const value = core.facetValueSearch(state(), config, 'category_ids');
    assert.equal(value.max_facet_values, 350);
});

test('activeFilterChips are structured per value in facet order, never joined strings', () => {
    const config = searchConfig({ facets: [
        categoryFacet(),
        { field: 'color', label: 'Color', type: 'dropdown' },
        { field: 'in_stock', label: 'Availability', type: 'checkbox', boolean: true },
        { field: 'price', label: 'Price', type: 'slider' },
    ] });
    const chips = core.activeFilterChips(state({
        filters: { in_stock: ['true'], color: ['Black-White', 'Red'], category_ids: ['7'], unknown: ['x'] },
        price: { min: 10, max: 20 },
    }), config, yesNo);

    assert.deepEqual(chips.map(chip => [chip.field, chip.value, chip.facetLabel, chip.valueLabel]), [
        ['category_ids', '7', 'Category', 'Schnaps & Likör'],
        ['color', 'Black-White', 'Color', 'Black-White'],
        ['color', 'Red', 'Color', 'Red'],
        ['in_stock', 'true', 'Availability', 'Yes'],
    ]);
    assert.equal(new Set(chips.map(chip => chip.key)).size, chips.length);
});

test('clampPriceRange keeps values inside the stats bounds and clears a full range', () => {
    const bounds = { min: 10, max: 200 };

    assert.deepEqual(core.clampPriceRange(20, 80, bounds), { min: 20, max: 80 });
    assert.deepEqual(core.clampPriceRange(-5, 80, bounds), { min: 10, max: 80 });
    assert.deepEqual(core.clampPriceRange(90, 40, bounds), { min: 40, max: 90 });
    assert.deepEqual(core.clampPriceRange('abc', 150, bounds), { min: 10, max: 150 });
    assert.equal(core.clampPriceRange(10, 200, bounds), null);
    assert.equal(core.clampPriceRange(0, 999, bounds), null);
    assert.equal(core.clampPriceRange(20, 80, null), null);
});

test('numeric facets are filtered unquoted and accept only finite numbers', () => {
    assert.equal(
        core.buildFilterBy('', { visibility: ['4', '2', 'x', '1e3', ''], color: ['4'] }, null, [], [], { visibility: 'int32' }),
        'visibility:=[4,2] && color:=[`4`]',
    );
    assert.equal(core.buildFilterBy('v:=1', { visibility: ['abc'] }, null, [], [], { visibility: 'int32' }), 'v:=1');
    assert.equal(core.buildFilterBy('', { volume: ['-0.5', '12.75'] }, null, [], [], { volume: 'float' }), 'volume:=[-0.5,12.75]');

    const config = searchConfig({ facets: [
        { field: 'visibility', label: 'Visibility', type: 'dropdown', numeric: 'int32' },
        { field: 'color', label: 'Color', type: 'dropdown' },
    ] });
    const parsed = core.parseUrlState('?visibility=4|abc|%60', config);
    assert.deepEqual(parsed.filters.visibility, ['4']);
    assert.equal(core.parseUrlState('?visibility=abc', config).filters.visibility, undefined);

    const searches = core.buildSearches(state({ filters: { visibility: ['4', 'abc'] } }), config);
    assert.equal(searches[0].filter_by, 'visibility:[3,4] && visibility:=[4]');
    const hostile = core.buildSearches(state({ filters: { visibility: ['abc'] } }), config);
    assert.equal(hostile[0].filter_by, 'visibility:[3,4]');
    assert.equal(hostile.length, 2, 'a facet without a valid value gets no own disjunctive search');

    const facet = config.facets[0];
    const entry = { field_name: 'visibility', counts: [{ value: '4', count: 3 }, { value: 'x', count: 1 }] };
    assert.deepEqual(core.facetOptions(facet, entry, [], {}).map(option => option.value), ['4']);
});

test('the price filter is ignored when prices are hidden', () => {
    const config = searchConfig({ showPrices: false });

    assert.equal(core.parseUrlState('?price=10-20', config).price, null);
    const searches = core.buildSearches(state({ price: { min: 10, max: 20 } }), config);
    assert.equal(searches[0].filter_by, 'visibility:[3,4]');
    assert.equal(core.serializeUrlState(state({ price: { min: 10, max: 20 } }), config), '');
    assert.deepEqual(core.parseUrlState('?price=10-20', searchConfig()).price, { min: 10, max: 20 });
});

test('facetValueSearch builds only the value search of one facet, without its own filter', () => {
    const config = searchConfig();
    const search = core.facetValueSearch(state({
        q: 'gin', filters: { color: ['Red'], size: ['L'] }, facetQueries: { color: 'bl' },
    }), config, 'color');

    assert.equal(search.facet_by, 'color');
    assert.equal(search.facet_query, 'color:bl');
    assert.equal(search.per_page, 0);
    assert.equal(search.q, 'gin');
    assert.equal(search.filter_by, 'visibility:[3,4] && size:=[`L`]');
    assert.equal(core.facetValueSearch(state(), config, 'unknown'), null);
});

test('integer facets accept only whole numbers in their range, float facets decimals', () => {
    const numerics = { visibility: 'int32', big_id: 'int64', volume: 'float' };

    assert.equal(
        core.buildFilterBy('', { visibility: ['4', '4.5', '2147483647', '2147483648', '-2147483648', '-2147483649'] },
            null, [], [], numerics),
        'visibility:=[4,2147483647,-2147483648]',
    );
    assert.equal(
        core.buildFilterBy('', { big_id: ['9007199254740993', '1.5', '12345678901234567890'] }, null, [], [], numerics),
        'big_id:=[9007199254740993]',
    );
    assert.equal(core.buildFilterBy('', { volume: ['0.7', '3', '.5'] }, null, [], [], numerics), 'volume:=[0.7,3]');

    const config = searchConfig({ facets: [
        { field: 'visibility', label: 'Visibility', type: 'dropdown', numeric: 'int32' },
        { field: 'volume', label: 'Volume', type: 'dropdown', numeric: 'float' },
    ] });
    assert.deepEqual(core.parseUrlState('?visibility=4|4.5|99999999999&volume=0.7|x', config).filters, {
        visibility: ['4'], volume: ['0.7'],
    });
    const entry = { field_name: 'visibility', counts: [{ value: '4', count: 3 }, { value: '4.5', count: 1 }] };
    assert.deepEqual(core.facetOptions(config.facets[0], entry, [], {}).map(option => option.value), ['4']);
});

test('only the Typesense numeric kinds mark a facet numeric', () => {
    const config = searchConfig({ facets: [{ field: 'volume', label: 'Volume', type: 'dropdown', numeric: true }] });

    assert.deepEqual(core.parseUrlState('?volume=0.7|x', config).filters, { volume: ['0.7', 'x'] });
    assert.equal(core.buildSearches(state({ filters: { volume: ['0.7'] } }), config)[0].filter_by,
        'visibility:[3,4] && volume:=[`0.7`]');
});
