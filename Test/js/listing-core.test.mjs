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

test('mergeFacetCounts prefers each facet own disjunctive counts', () => {
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

    const merged = core.mergeFacetCounts(results, state({ filters: { color: ['Red'] } }));

    assert.deepEqual(merged.map(facet => facet.field_name).sort(), ['color', 'in_stock', 'size']);
    assert.deepEqual(merged.find(facet => facet.field_name === 'color'), ownColor);

    // With the search list, each facet is read from the search built for it, in configured order,
    // even when the main search also returns a single facet.
    const config = searchConfig({ facets: ['color', 'size', 'in_stock'] });
    const current = state({ q: 'gin', filters: { color: ['Red'] } });
    const searches = core.buildSearches(current, config);
    assert.deepEqual(searches.facetSources, { color: 2, size: 3, in_stock: 3 });

    const mapped = core.mergeFacetCounts(results.slice(0, 4), current, searches);
    assert.deepEqual(mapped, [ownColor, size, inStock]);
    assert.deepEqual(core.mergeFacetCounts([results[0], results[1], { code: 500, error: 'down' }, results[3]], current, searches),
        [size, inStock]);
});

test('facetStats floors min and ceils max', () => {
    const results = [
        { facet_counts: [] },
        { facet_counts: [{ field_name: 'price', counts: [], stats: { min: 9.99, max: 120.01, avg: 40 } }] },
    ];

    assert.deepEqual(core.facetStats(results, 'price'), { min: 9, max: 121 });
    assert.equal(core.facetStats(results, 'weight'), null);
    assert.equal(core.facetStats([{ facet_counts: [{ field_name: 'price', counts: [] }] }], 'price'), null);

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

test('reorderByDirectMatches keeps its behaviour and the legacy window alias', () => {
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
    assert.equal(
        sandbox.window.runAsRootTypesense.reorderByDirectMatches,
        sandbox.window.runAsRootTypesense.listing.reorderByDirectMatches,
    );
});
