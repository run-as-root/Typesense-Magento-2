// Runs the inline Alpine component scripts of the listing templates against stubs (no browser, no Alpine):
// the templates' PHP output is replaced by the source strings, methods are called directly.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const moduleRoot = fileURLToPath(new URL('../../', import.meta.url));
const templates = moduleRoot + 'view/frontend/templates/listing/';
const scriptFiles = ['script/events.phtml', 'script/getters.phtml', 'script/extras.phtml', 'script.phtml',
    'tile/script/requests.phtml', 'tile/script.phtml'];

function inlineScripts(file) {
    const source = readFileSync(templates + file, 'utf8');

    return [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)]
        .map(match => match[1].replace(/<\?=[\s\S]*?\?>/g, (php) => {
            const phrase = php.match(/__\('((?:[^'\\]|\\.)*)'/);
            return phrase ? phrase[1] : '';
        }))
        .join('\n');
}

const listingConfig = {
    context: 'category', productCollection: 'products', baseFilter: 'category_ids:[3] && visibility:[2,4]',
    facets: [{ field: 'color', type: 'dropdown' }, { field: 'price', type: 'slider' }],
    sortOptions: [{ value: '', label: 'Relevance' }, { value: 'price:asc', label: 'Price' }], defaultSort: '',
    limits: [12, 24], perPage: 12, inStockFirst: false, requestParams: {}, cacheParams: {}, relevanceSortBy: null,
    directMatchesFirst: false, listName: 'Gin', currencyCode: 'EUR', showPrices: true, seoTextSelector: '.seo',
    noResultsCategoryId: null, conversationalSearch: { enabled: false }, locale: 'de-DE', showRatings: true,
    wishlistEnabled: true, addToCartUrl: '/checkout/cart/add/', wishlistAddUrl: '/wishlist/index/add/',
    wishlistUrl: '/wishlist/',
};

function hit(id, extra = {}) {
    return { document: Object.assign({ id: String(id), name: 'P' + id, sku: 'S' + id, final_price: 10, in_stock: true }, extra) };
}

function createEnvironment(search = '') {
    const env = { events: [], history: [], seo: { style: { display: 'flex' } }, responder: null };
    const context = {
        console: { error() {}, warn() {}, log: console.log }, URLSearchParams, JSON, Math, Number, String, Object,
        Array, Promise, setTimeout, Intl, Map,
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
        location: { pathname: '/gin.html', search, hash: '' },
        history: {
            state: null,
            pushState: (state, title, url) => env.history.push(['push', url]),
            replaceState: (state, title, url) => env.history.push(['replace', url]),
        },
        document: {
            getElementById: () => null,
            querySelectorAll: selector => (selector === '.seo' ? [env.seo] : []),
        },
        hyva: {
            str: (template, ...args) => template.replace(/%(\d)/g, (_, i) => args[i - 1]),
            formatPrice: value => 'EUR ' + value,
            getFormKey: () => 'FK',
            getUenc: () => 'UENC',
            safeParseNumber: value => Number(value),
        },
        Alpine: { data() {} },
        dispatchEvent: event => env.events.push(event),
        addEventListener() {},
        scrollTo() {},
    };
    context.window = context;
    vm.createContext(context);
    vm.runInContext(readFileSync(moduleRoot + 'view/frontend/web/js/listing-core.js', 'utf8'), context);
    for (const file of scriptFiles) {
        vm.runInContext(inlineScripts(file), context);
    }
    context.runAsRootTypesense.getClient = async () => ({
        multiSearch: { perform: async body => env.responder(body) },
    });
    env.context = context;
    env.listing = () => {
        const component = context.initTypesenseListing();
        component.$root = {
            querySelector: () => ({ textContent: JSON.stringify(listingConfig) }),
            dataset: { eagerImages: '1' },
        };
        return component;
    };

    return env;
}

const settle = () => new Promise(resolve => setTimeout(resolve, 10));
const results = (found, hits) => body => ({ results: body.searches.map((s, i) => (i === 0 ? { found, hits } : { facet_counts: [] })) });

test('an exception before the request still stops loading and shows the error state', async () => {
    const env = createEnvironment();
    env.responder = results(1, [hit(1)]);
    const listing = env.listing();
    listing.init();
    await settle();
    assert.equal(listing.isLoading, false);

    env.context.runAsRootTypesense.listing.buildSearches = () => {
        throw new Error('broken config');
    };
    listing.setSort('price:asc');
    await settle();

    assert.equal(listing.isLoading, false);
    assert.equal(listing.hasError(), true);
    assert.equal(listing.showNoResults(), true);
    assert.equal(listing.showOverlay(), false);
    assert.equal(env.events.filter(e => e.type === 'typesense-state').pop().detail.isLoading, false);
});

test('a failing Typesense request stops loading and shows the error state', async () => {
    const env = createEnvironment();
    env.responder = () => {
        throw Object.assign(new Error('Service Unavailable'), { httpStatus: 503 });
    };
    const listing = env.listing();
    listing.init();
    await settle();

    assert.equal(listing.isLoading, false);
    assert.equal(listing.showSkeleton(), false);
    assert.equal(listing.hasError(), true);
    assert.equal(listing.error.kind, 'server');
});

test('hostile URL state is cleaned on load and the page is clamped with a replace', async () => {
    const env = createEnvironment('?color=Red&page=999&utm_source=x&sort=bogus');
    env.responder = results(30, [hit(1), hit(2)]);
    const listing = env.listing();
    listing.init();
    await settle();

    assert.deepEqual(env.history, [['replace', '/gin.html?color=Red&page=999'], ['replace', '/gin.html?color=Red&page=3']]);
    assert.equal(listing.state.page, 3);
    assert.equal(listing.hits[0]._loading, 'eager');
    assert.equal(listing.hits[1]._loading, 'lazy');
});

test('SEO text is hidden by inline style after page 1 and its display restored on page 1', async () => {
    const env = createEnvironment('?page=2');
    env.responder = results(30, [hit(1)]);
    const listing = env.listing();
    listing.init();
    await settle();
    assert.equal(env.seo.style.display, 'none');

    listing.setPage(1);
    await settle();
    assert.equal(env.seo.style.display, 'flex');
});

test('pagination keeps modified clicks for the browser and ignores disabled links', async () => {
    const env = createEnvironment();
    env.responder = results(30, [hit(1)]);
    const listing = env.listing();
    listing.init();
    await settle();
    const click = (extra = {}) => Object.assign({ button: 0, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } }, extra);

    listing.$event = click({ ctrlKey: true });
    listing.pageNumber = 2;
    listing.goToPage();
    assert.equal(listing.$event.defaultPrevented, false);
    assert.equal(listing.state.page, 1);

    listing.$event = click();
    listing.goToPrevPage();
    assert.equal(listing.$event.defaultPrevented, true);
    assert.equal(listing.prevPageTabindex(), '-1');
    await settle();
    assert.equal(env.history.length, 1, 'a disabled previous link writes no history');

    listing.$event = click();
    listing.goToPage();
    assert.equal(listing.$event.defaultPrevented, true);
    await settle();
    assert.deepEqual(env.history.pop(), ['push', '/gin.html?page=2']);
});

test('tile buttons show a busy class while their request runs', async () => {
    const env = createEnvironment();
    let finishRequest;
    env.context.fetch = () => new Promise(resolve => {
        finishRequest = () => resolve({ ok: true, redirected: false, json: async () => ({ success: true }) });
    });
    const tile = Object.assign(env.context.initTypesenseTile(), {
        config: listingConfig, wishlistIds: [], item: hit(9, { type_id: 'simple' }),
    });

    const adding = tile.addToCart();
    assert.match(tile.cartButtonClasses(), /is-busy/);
    finishRequest();
    await adding;
    assert.equal(tile.cartButtonClasses(), '');

    const wishing = tile.addToWishlist();
    assert.match(tile.wishlistButtonClasses(), /is-busy/);
    assert.equal(tile.isNotAddingToWishlist(), false);
    finishRequest();
    await wishing;
    assert.equal(tile.isWished(), true);
    assert.equal(tile.wishlistUrl(), '/wishlist/');
});

test('a facet value search requests only that facet and leaves products, overlay and history alone', async () => {
    const env = createEnvironment();
    env.responder = body => ({ results: body.searches.map((s, i) => (i === 0
        ? { found: 1, hits: [hit(1)] }
        : { facet_counts: [{ field_name: 'color', counts: [{ value: 'Red', count: 1 }, { value: 'Blue', count: 1 }] }] })) });
    const listing = env.listing();
    listing.init();
    await settle();
    const historyLength = env.history.length;
    const hits = listing.hits;

    const requests = [];
    env.responder = body => {
        requests.push(body.searches);
        return { results: [{ facet_counts: [{ field_name: 'color', counts: [{ value: 'Blue', count: 1 }] }] }] };
    };
    listing.applyFilterChange({ field: 'color', facetQuery: 'bl' });
    assert.equal(listing.isLoading, false);
    assert.equal(listing.showOverlay(), false);
    await settle();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].length, 1);
    assert.equal(requests[0][0].facet_by, 'color');
    assert.equal(requests[0][0].facet_query, 'color:bl');
    assert.equal(listing.hits, hits);
    assert.deepEqual(listing.facets.find(f => f.field_name === 'color').counts.map(c => c.value), ['Blue']);
    assert.equal(env.history.length, historyLength);
    assert.equal(env.events.filter(e => e.type === 'typesense-state').pop().detail.state.facetQueries.color, 'bl');
});

test('a facet value search response is dropped once a full request has started', async () => {
    const env = createEnvironment();
    env.responder = results(1, [hit(1)]);
    const listing = env.listing();
    listing.init();
    await settle();

    let releaseFacet;
    env.responder = body => (body.searches.length === 1
        ? new Promise(resolve => { releaseFacet = () => resolve({ results: [{ facet_counts: [{ field_name: 'color', counts: [{ value: 'Stale', count: 9 }] }] }] }); })
        : { results: body.searches.map((s, i) => (i === 0 ? { found: 1, hits: [hit(1)] } : { facet_counts: [{ field_name: 'color', counts: [{ value: 'Fresh', count: 1 }] }] })) });
    listing.applyFilterChange({ field: 'color', facetQuery: 'st' });
    await settle();
    listing.setSort('price:asc');
    await settle();
    releaseFacet();
    await settle();

    assert.deepEqual(listing.facets.find(f => f.field_name === 'color').counts.map(c => c.value), ['Fresh']);
});

test('a value search on one facet does not drop the pending value search of another', async () => {
    const env = createEnvironment();
    env.responder = results(1, [hit(1)]);
    const listing = env.listing();
    const twoFacets = Object.assign({}, listingConfig, {
        facets: [{ field: 'color', type: 'dropdown' }, { field: 'size', type: 'dropdown' }],
    });
    listing.$root.querySelector = () => ({ textContent: JSON.stringify(twoFacets) });
    listing.init();
    await settle();

    const pending = {};
    env.responder = body => new Promise(resolve => {
        const field = body.searches[0].facet_by;
        pending[field] = () => resolve({ results: [{ facet_counts: [{ field_name: field, counts: [{ value: field + '-hit', count: 1 }] }] }] });
    });
    listing.applyFilterChange({ field: 'color', facetQuery: 'r' });
    await settle();
    listing.applyFilterChange({ field: 'size', facetQuery: 'l' });
    await settle();
    pending.color();
    pending.size();
    await settle();

    assert.deepEqual(listing.facets.map(f => [f.field_name, f.counts[0].value]), [['color', 'color-hit'], ['size', 'size-hit']]);
});

// JSON round trip: details are created in the vm context, whose prototypes differ from this realm's.
const tracked = (env, type) => env.events.filter(e => e.type === type).map(e => JSON.parse(JSON.stringify(e.detail)));

test('results carry the listing state key and sort and filter changes dispatch tracking events', async () => {
    const env = createEnvironment('?color=Red');
    env.responder = results(30, [hit(1), hit(2)]);
    const listing = env.listing();
    listing.init();
    await settle();

    const [first] = tracked(env, 'typesense:results');
    assert.equal(first.stateKey, '?color=Red');
    assert.equal(first.items.length, 2);

    listing.setSort('price:asc');
    listing.setSort('price:asc');
    await settle();
    assert.deepEqual(tracked(env, 'typesense:sort-change'), [
        { context: 'category', listName: 'Gin', sort: 'price:asc', previous: '' },
    ]);

    listing.applyFilterChange({ field: 'color', value: 'Blue, dark', action: 'add' });
    listing.applyFilterChange({ field: 'color', facetQuery: 'b' });
    listing.applyFilterChange({ price: { min: 10, max: 20 } });
    listing.applyFilterChange({ clear: true });
    await settle();
    assert.deepEqual(tracked(env, 'typesense:filter-change'), [
        { context: 'category', listName: 'Gin', added: [{ field: 'color', value: 'Blue, dark' }], removed: [] },
        { context: 'category', listName: 'Gin', added: [{ field: 'price', value: '10-20' }], removed: [] },
        {
            context: 'category', listName: 'Gin', added: [],
            removed: [{ field: 'color', value: 'Red' }, { field: 'color', value: 'Blue, dark' }, { field: 'price', value: '10-20' }],
        },
    ]);
    assert.equal(tracked(env, 'typesense:results').pop().stateKey, '?sort=price%3Aasc');

    listing.onPopState();
    await settle();
    assert.equal(tracked(env, 'typesense:sort-change').length, 1, 'history navigation is no interaction');
});

test('a tile click dispatches select-item with the GA4 item at its listing position', async () => {
    const env = createEnvironment('?page=2');
    env.responder = results(30, [hit(1), hit(2)]);
    const listing = env.listing();
    listing.init();
    await settle();

    const tile = Object.assign(env.context.initTypesenseTile(), {
        config: listing.config, state: listing.state, currentLimit: listing.currentLimit, hits: listing.hits, item: listing.hits[1],
    });
    tile.trackSelect();

    assert.deepEqual(tracked(env, 'typesense:select-item'), [{
        item: { item_id: 'S2', item_name: 'P2', price: 10, index: 13, item_list_name: 'Gin' },
        index: 13,
        listName: 'Gin',
        document: JSON.parse(JSON.stringify(listing.hits[1].document)),
    }]);

    const hidden = Object.assign({}, tile, { config: Object.assign({}, listing.config, { showPrices: false }) });
    hidden.trackSelect();
    assert.equal('price' in tracked(env, 'typesense:select-item').pop().item, false);

    const suggested = Object.assign({}, tile, { hits: [], item: Object.assign({}, hit(7), { _key: '7', _position: 0 }) });
    suggested.trackSelect();
    assert.equal(tracked(env, 'typesense:select-item').length, 2, 'no-results suggestions are not the tracked list');
});
