// Header search and GA4 helpers of listing-core.js.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const core = require(fileURLToPath(new URL('../../view/frontend/web/js/listing-core.js', import.meta.url)));

function acConfig(overrides = {}) {
    return Object.assign({
        minQueryLength: 3,
        searchUrl: 'https://shop.test/de/catalogsearch/result/',
        baseUrl: 'https://shop.test/de/',
        productCollection: 'p',
        categoryCollection: 'c',
        pageCollection: 'cms',
        suggestionCollection: 's',
        productCount: 4,
        categoryCount: 3,
        pageCount: 2,
        suggestionCount: 5,
        groupBy: null,
        highlight: true,
        showPrices: true,
        requestParams: { query_by: 'name,sku', infix: 'off' },
        directMatchesFirst: false,
        cacheParams: { use_cache: true },
        relevanceSortBy: null,
    }, overrides);
}

const product = (id, extra = {}) => ({
    document: Object.assign({ id: String(id), name: 'Gin ' + id, sku: 'S' + id, url: '/gin-' + id + '.html', final_price: 20, image_url: '/img/' + id + '.jpg' }, extra),
});

test('highlightSegments splits a snippet on mark tags into plain and marked text', () => {
    assert.deepEqual(core.highlightSegments('Wil<mark>liams</mark> Birne <mark>x</mark>'), [
        { key: 0, text: 'Wil', mark: false, plain: true },
        { key: 1, text: 'liams', mark: true, plain: false },
        { key: 2, text: ' Birne ', mark: false, plain: true },
        { key: 3, text: 'x', mark: true, plain: false },
    ]);
    // Markup inside the document is text, never parsed.
    assert.deepEqual(core.highlightSegments('<img src=x onerror=alert(1)><mark>Gin</mark>').map(s => s.text), [
        '<img src=x onerror=alert(1)>',
        'Gin',
    ]);
    assert.deepEqual(core.highlightSegments(''), []);
    assert.deepEqual(core.highlightSegments('a</mark>b<MARK>c'), [
        { key: 0, text: 'a', mark: false, plain: true },
        { key: 1, text: 'b', mark: false, plain: true },
        { key: 2, text: 'c', mark: true, plain: false },
    ]);
});

test('hitSegments reads the v26 highlight object or legacy highlights and falls back to the plain value', () => {
    const v26 = { highlight: { name: { snippet: '<mark>Gin</mark> Tonic' } } };
    const legacy = { highlights: [{ field: 'name', snippet: 'Dry <mark>Gin</mark>' }] };

    assert.deepEqual(core.hitSegments(v26, 'name', 'Gin Tonic', true).map(s => [s.text, s.mark]), [['Gin', true], [' Tonic', false]]);
    assert.deepEqual(core.hitSegments(legacy, 'name', 'Dry Gin', true).map(s => [s.text, s.mark]), [['Dry ', false], ['Gin', true]]);
    assert.deepEqual(core.hitSegments(v26, 'name', 'Gin Tonic', false), [{ key: 0, text: 'Gin Tonic', mark: false, plain: true }]);
    assert.deepEqual(core.hitSegments({}, 'name', 'Gin', true), [{ key: 0, text: 'Gin', mark: false, plain: true }]);
    assert.deepEqual(core.hitSegments({}, 'name', '', true), []);
});

test('searchResultUrl encodes the query and cmsPageUrl encodes each path segment', () => {
    assert.equal(
        core.searchResultUrl('https://shop.test/catalogsearch/result/', 'Gin & Tonic|50% #1'),
        'https://shop.test/catalogsearch/result/?q=Gin%20%26%20Tonic%7C50%25%20%231',
    );
    assert.equal(core.searchResultUrl('https://shop.test/catalogsearch/result/?x=1', 'a'), 'https://shop.test/catalogsearch/result/?x=1&q=a');
    assert.equal(core.cmsPageUrl('https://shop.test/de', 'service/faq ä'), 'https://shop.test/de/service/faq%20%C3%A4');
    assert.equal(core.cmsPageUrl('https://shop.test/de/', 'about-us'), 'https://shop.test/de/about-us');
});

test('flattenGroupedResult flattens grouped hits and counts products, not groups', () => {
    const grouped = { found: 2, found_docs: 5, grouped_hits: [{ hits: [product(1)] }, { hits: [product(2), product(3)] }] };

    const flat = core.flattenGroupedResult(grouped);
    assert.deepEqual(flat.hits.map(h => h.document.id), ['1', '2', '3']);
    assert.equal(flat.found, 5);
    assert.equal(grouped.hits, undefined, 'the response is not mutated');

    const plain = { found: 1, hits: [product(1)] };
    assert.equal(core.flattenGroupedResult(plain), plain);
});

test('buildAutocompleteSearches sends one multi search with only the configured sections', () => {
    const plan = core.buildAutocompleteSearches('  gin ', acConfig({ relevanceSortBy: '_text_match:desc', groupBy: 'variant_group', directMatchesFirst: true }));

    assert.deepEqual(plan.searches[0], {
        query_by: 'name,sku', infix: 'off', collection: 'p', q: 'gin', filter_by: 'visibility:[3,4]', per_page: 4,
        sort_by: '_text_match:desc', group_by: 'variant_group', group_limit: 1,
    });
    assert.equal(plan.searches[1].enable_synonyms, false);
    assert.equal(plan.searches[1].group_by, 'variant_group');
    assert.deepEqual(plan.sources, { products: 0, direct: 1, suggestions: 2, categories: 3, pages: 4 });
    assert.deepEqual(plan.searches[2], { collection: 's', q: 'gin', query_by: 'query', per_page: 5 });
    assert.deepEqual(plan.searches[3], { collection: 'c', q: 'gin', query_by: 'name', filter_by: 'is_active:=true && level:>1', per_page: 3 });
    assert.deepEqual(plan.searches[4], { collection: 'cms', q: 'gin', query_by: 'title', filter_by: 'is_active:=true', per_page: 2 });

    const minimal = core.buildAutocompleteSearches('gin', acConfig({ categoryCollection: null, pageCollection: null, suggestionCollection: null }));
    assert.equal(minimal.searches.length, 1);
    assert.equal(minimal.searches[0].group_by, undefined);
    assert.deepEqual(minimal.sources, { products: 0, direct: null, suggestions: null, categories: null, pages: null });
});

test('autocompleteResults builds ordered rows with highlights, URLs, prices and GA4 items', () => {
    const config = acConfig({ directMatchesFirst: true, groupBy: 'variant_group' });
    const plan = core.buildAutocompleteSearches('gin', config);
    const results = [
        { found: 2, found_docs: 3, grouped_hits: [{ hits: [Object.assign(product(1), { highlight: { name: { snippet: '<mark>Gin</mark> 1' } } })] }, { hits: [product(2, { final_price: 0 })] }] },
        { found: 1, grouped_hits: [{ hits: [product(2, { final_price: 0 })] }] },
        { hits: [{ document: { id: '9', query: 'gin tonic' }, highlight: { query: { snippet: '<mark>gin</mark> tonic' } } }] },
        { hits: [{ document: { id: '5', name: 'Gin', url: '/spirits/gin.html' } }, { document: { id: '6', name: 'No URL' } }] },
        { hits: [{ document: { id: '7', title: 'Gin FAQ', url_key: 'gin-faq' } }, { document: { id: '8', title: '404', url_key: 'no-route' } }] },
    ];

    const result = core.autocompleteResults(results, plan.sources, 'gin', config);

    assert.equal(result.found, 3);
    assert.deepEqual(result.rows.map(r => [r.type, r.position, r.url]), [
        ['suggestion', 0, 'https://shop.test/de/catalogsearch/result/?q=gin%20tonic'],
        ['product', 1, '/gin-2.html'],
        ['product', 2, '/gin-1.html'],
        ['category', 3, '/spirits/gin.html'],
        ['page', 4, 'https://shop.test/de/gin-faq'],
    ]);
    assert.deepEqual(result.sections.products.map(r => r.key), ['product-2', 'product-1']);
    const [direct, first] = result.sections.products;
    assert.equal(direct.price, null, 'a price of 0 is never shown');
    assert.equal(direct.hasPrice, false);
    assert.equal(first.price, 20);
    assert.equal(first.hasImage, true);
    assert.equal(first.domId, 'typesense-ac-option-2');
    assert.deepEqual(first.segments.map(s => s.text), ['Gin', ' 1']);
    assert.deepEqual(first.item, { item_id: 'S1', item_name: 'Gin 1', price: 20, index: 1, item_list_name: 'search_suggestions' });
    assert.equal(result.sections.suggestions[0].item, null);
    assert.equal(result.sections.suggestions[0].name, 'gin tonic');
});

test('autocompleteResults leaves prices out of rows and GA4 items when prices are hidden', () => {
    const config = acConfig({ showPrices: false, categoryCollection: null, pageCollection: null, suggestionCollection: null });
    const plan = core.buildAutocompleteSearches('gin', config);

    const [row] = core.autocompleteResults([{ found: 1, hits: [product(1)] }], plan.sources, 'gin', config).rows;

    assert.equal(row.price, null);
    assert.equal(row.hasPrice, false);
    assert.equal('price' in row.item, false);
});

test('autocompleteResults throws on a failed product search and drops failed side sections', () => {
    const config = acConfig({ pageCollection: null });
    const plan = core.buildAutocompleteSearches('gin', config);

    assert.throws(() => core.autocompleteResults([{ code: 404, error: 'Not found' }, { hits: [] }, { hits: [] }], plan.sources, 'gin', config), (error) => error.code === 404);
    assert.throws(() => core.autocompleteResults([], plan.sources, 'gin', config));

    const result = core.autocompleteResults(
        [{ found: 1, hits: [product(1)] }, { code: 404, error: 'Not found' }, { code: 400, error: 'Bad' }],
        plan.sources, 'gin', config,
    );
    assert.deepEqual(result.rows.map(r => r.type), ['product']);
});

test('suggestionClickDetail describes the clicked row for tracking', () => {
    const config = acConfig({ categoryCollection: null, pageCollection: null, suggestionCollection: null });
    const plan = core.buildAutocompleteSearches('gin', config);
    const [row] = core.autocompleteResults([{ found: 1, hits: [product(1)] }], plan.sources, 'gin', config).rows;

    assert.deepEqual(core.suggestionClickDetail(row, 'gin'), {
        type: 'product', term: 'gin', name: 'Gin 1', url: '/gin-1.html', index: 0, item: row.item,
    });
    assert.deepEqual(
        core.suggestionClickDetail({ type: 'category', name: 'Gin', url: '/gin.html', index: 2, item: null }, 'gin'),
        { type: 'category', term: 'gin', name: 'Gin', url: '/gin.html', index: 2 },
    );
});

test('filterChanges lists added and removed facet values and price ranges', () => {
    const prev = { filters: { color: ['Red', 'Blue'] }, price: { min: 10, max: 50 } };
    const next = { filters: { color: ['Blue', 'Green, dark'], size: ['L'] }, price: null };

    assert.deepEqual(core.filterChanges(prev, next), {
        added: [{ field: 'color', value: 'Green, dark' }, { field: 'size', value: 'L' }],
        removed: [{ field: 'color', value: 'Red' }, { field: 'price', value: '10-50' }],
    });
    assert.deepEqual(core.filterChanges({ filters: {}, price: { min: 1, max: 2 } }, { filters: {}, price: { min: 1, max: 3 } }), {
        added: [{ field: 'price', value: '1-3' }],
        removed: [],
    });
    assert.deepEqual(core.filterChanges(next, next), { added: [], removed: [] });
});

test('the GA4 tracker precedes every push with an ecommerce reset', () => {
    const tracker = core.createGa4Tracker();
    const item = { item_id: 'S1', item_name: 'Gin', index: 0, item_list_name: 'Gin' };

    assert.deepEqual(tracker.events('typesense:select-item', { item, index: 0, listName: 'Gin' }), [
        { ecommerce: null },
        { event: 'select_item', ecommerce: { item_list_name: 'Gin', items: [item] } },
    ]);
    assert.deepEqual(tracker.events('typesense:sort-change', { sort: 'price:asc', previous: '' }), [
        { ecommerce: null },
        { event: 'sorting_interaction', sorting_option: 'price:asc' },
    ]);
    assert.deepEqual(tracker.events('typesense:sort-change', { sort: '', previous: 'price:asc' })[1].sorting_option, 'relevance');
    assert.deepEqual(tracker.events('typesense:filter-change', {
        added: [{ field: 'color', value: 'Red' }, { field: 'price', value: '10-50' }],
        removed: [{ field: 'size', value: 'L' }],
    }), [
        { ecommerce: null },
        { event: 'filter_interaction', filter_type: 'color', filter_value: 'Red' },
        { ecommerce: null },
        { event: 'filter_interaction', filter_type: 'price', filter_value: '10-50' },
        { ecommerce: null },
        { event: 'filter_interaction', filter_type: 'filter_deactivation', filter_value: '' },
    ]);
    assert.deepEqual(tracker.events('typesense:unknown', {}), []);
});

test('search_suggestion_click carries the term, the path and an ecommerce item only for products', () => {
    const tracker = core.createGa4Tracker();
    const item = { item_id: 'S1', item_name: 'Gin', price: 20, index: 0, item_list_name: 'search_suggestions' };

    assert.deepEqual(tracker.events('typesense:suggestion-click', {
        type: 'product', term: 'gin', name: 'Gin', url: 'https://shop.test/de/gin.html?x=1', index: 0, item,
    }), [
        { ecommerce: null },
        {
            event: 'search_suggestion_click', search_term: 'gin', suggestion_type: 'product', suggestion_name: 'Gin',
            suggestion_url: '/de/gin.html', index: 0, ecommerce: { item_list_name: 'search_suggestions', items: [item] },
        },
    ]);
    assert.deepEqual(tracker.events('typesense:suggestion-click', { type: 'category', term: 'gin', name: 'Gin', url: '/spirits/gin.html', index: 1 }), [
        { ecommerce: null },
        { event: 'search_suggestion_click', search_term: 'gin', suggestion_type: 'category', suggestion_name: 'Gin', suggestion_url: '/spirits/gin.html', index: 1 },
    ]);
});

test('view_item_list is pushed once per listing state and never for empty results', () => {
    const tracker = core.createGa4Tracker();
    const items = [{ item_id: 'S1', item_name: 'Gin', index: 0, item_list_name: 'Gin' }];
    const detail = (stateKey, list = items) => ({ context: 'category', listName: 'Gin', page: 1, stateKey, items: list });

    assert.deepEqual(tracker.events('typesense:results', detail('?color=Red')), [
        { ecommerce: null },
        { event: 'view_item_list', ecommerce: { item_list_name: 'Gin', items } },
    ]);
    assert.deepEqual(tracker.events('typesense:results', detail('?color=Red')), [], 'same state: no second push');
    assert.equal(tracker.events('typesense:results', detail('?color=Red&page=2')).length, 2);
    assert.equal(tracker.events('typesense:results', detail('?color=Red')).length, 2, 'back to an earlier state counts again');
    assert.deepEqual(tracker.events('typesense:results', detail('?q=none', [])), []);
});
