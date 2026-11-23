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
    const env = { events: [], history: [], parsed: [], seo: { style: { display: 'flex' } }, responder: null };
    const context = {
        console: { error() {}, warn() {}, log: console.log }, URLSearchParams, JSON, Math, Number, String, Object,
        Array, Promise, setTimeout, clearTimeout, Intl, Map,
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
        // Like the browser's parser for what the tile relies on: tags dropped, then entities decoded once.
        DOMParser: class {
            parseFromString(source, type) {
                env.parsed.push(type);
                const entities = { amp: '&', nbsp: '\u00a0', lt: '<', gt: '>', quot: '"', '#39': "'" };
                const text = source.replace(/<[^>]*>/g, '').replace(/&(#?\w+);/g, (entity, name) => entities[name] ?? entity);
                return { body: { textContent: text } };
            }
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

test('a typed child quantity is rounded up to the next multiple of its quantity increment', () => {
    const env = createEnvironment();
    const tile = Object.assign(env.context.initTypesenseTile(), {
        config: listingConfig, wishlistIds: [],
        item: hit(9, { type_id: 'grouped', children: [{ id: 21, qty_increment: 6 }, { id: 22, qty_increment: 0 }] }),
    });
    const type = (child, value) => {
        const target = { value };
        Object.assign(tile, { child, $event: { target } }).setChildQty();
        return [tile.childQty(), target.value];
    };
    const [sixPack, single] = tile.variants();

    assert.deepEqual(type(sixPack, '5'), [6, 6]);
    assert.deepEqual(type(sixPack, '7'), [12, 12]);
    assert.deepEqual(type(sixPack, '12'), [12, 12]);
    assert.deepEqual(type(sixPack, '0'), [0, 0]);
    assert.deepEqual(type(sixPack, '-3'), [0, 0]);
    assert.deepEqual(type(single, '2.4'), [3, 3]);
});

test('the child quantity input is normalised on change and recorded on every keystroke', () => {
    const source = readFileSync(templates + 'tile/variants.phtml', 'utf8');

    assert.match(source, /@change="setChildQty"/);
    assert.match(source, /@input="recordChildQty"/);
    assert.doesNotMatch(source, /@input="setChildQty"/);
});

test('add to cart sends a typed quantity rounded to its increment even when no change event fired', async () => {
    const env = createEnvironment();
    let sent;
    env.context.fetch = (url, options) => {
        sent = Object.fromEntries(options.body);
        return Promise.resolve({ ok: true, redirected: false, json: async () => ({ success: true }) });
    };
    const tile = Object.assign(env.context.initTypesenseTile(), {
        config: listingConfig, wishlistIds: [],
        item: hit(9, { type_id: 'grouped', children: [{ id: 21, qty_increment: 6 }, { id: 22, qty_increment: 0 }] }),
    });
    const [sixPack, single] = tile.variants();
    Object.assign(tile, { child: sixPack, $event: { target: { value: '7' } } }).recordChildQty();
    Object.assign(tile, { child: single, $event: { target: { value: '2' } } }).recordChildQty();

    await tile.addToCart();

    assert.equal(sent['super_group[21]'], '12');
    assert.equal(sent['super_group[22]'], '2');
});

function tile(env, document, dataset = {}, config = listingConfig) {
    const component = Object.assign(env.context.initTypesenseTile(), {
        config, wishlistIds: [], item: hit(9, document), $root: { dataset },
    });
    component.init();
    return component;
}

const sizes = [
    { id: 21, name: 'Gin - 0.5 l - 40 %', final_price: 2.5, qty_increment: 6, in_stock: true },
    { id: 22, name: 'Gin - 1 l', final_price: 10, in_stock: false, stock_text: 'Back in May', base_price: 'EUR 10 / l' },
    { id: 23, name: 'Gin', final_price: 30, in_stock: false },
];

test('tile getters return defaults when the optional fields are absent', () => {
    const env = createEnvironment();
    const plain = tile(env, { type_id: 'simple', url: '/gin.html' });

    assert.deepEqual([...plain.labels()], []);
    assert.equal(plain.hasLabels(), false);
    assert.equal(plain.shortDescription(), '');
    assert.equal(plain.hasShortDescription(), false);
    assert.deepEqual([...plain.visibleVariants()], []);
    assert.equal(plain.hasMoreVariants(), false);
    assert.equal(plain.stockText(), 'Out of stock');
    assert.equal(plain.notifyUrl(), '/gin.html#notify-9');
    assert.equal(plain.totalQty(), 0);
    assert.equal(plain.hasTotalQty(), false);
    assert.equal(plain.showTotal(), false);
    assert.equal(plain.isVariantsPanelOpen(), false);
    assert.equal(plain.isAddedToCart, false);

    plain.child = { id: 5 };
    assert.deepEqual([...plain.sizeParts()], []);
    assert.equal(plain.hasChildBasePrice(), false);
    assert.equal(plain.childBasePrice(), '');
    assert.equal(plain.childStockText(), 'Out of stock');
    assert.equal(plain.isChildQtyEmpty(), true);
    assert.equal(env.parsed.length, 0, 'an absent description is not parsed');
});

test('labels are strings and a non-array labels field is ignored', () => {
    const env = createEnvironment();

    assert.deepEqual([...tile(env, { labels: ['New', 5] }).labels()], ['New', '5']);
    assert.equal(tile(env, { labels: ['New'] }).hasLabels(), true);
    assert.deepEqual([...tile(env, { labels: 'New' }).labels()], []);
});

test('the short description is plain text with entities decoded and markup never parsed back', () => {
    const env = createEnvironment();
    const described = tile(env, { short_description: ' <p>Gin &amp; Tonic&nbsp;</p> ' });

    assert.equal(described.shortDescription(), 'Gin & Tonic');
    assert.equal(described.hasShortDescription(), true);
    assert.deepEqual(env.parsed, ['text/html']);

    const hostile = tile(env, { short_description: 'Dry &lt;script&gt;alert(1)&lt;/script&gt; <b>gin</b>' });
    assert.equal(hostile.shortDescription(), 'Dry <script>alert(1)</script> gin');
    assert.equal(typeof hostile.shortDescription(), 'string');
    assert.equal(tile(env, { short_description: '<p> </p>' }).hasShortDescription(), false);
});

test('visible variants follow data-visible-variants and default to every child', () => {
    const env = createEnvironment();
    const grouped = { type_id: 'grouped', children: sizes };

    const all = tile(env, grouped);
    assert.deepEqual(all.visibleVariants().map(child => child.id), [21, 22, 23]);
    assert.equal(all.hasMoreVariants(), false);

    const two = tile(env, grouped, { visibleVariants: '2' });
    assert.deepEqual(two.visibleVariants().map(child => child.id), [21, 22]);
    assert.equal(two.hasMoreVariants(), true);

    assert.equal(tile(env, grouped, { visibleVariants: '3' }).hasMoreVariants(), false);
    assert.equal(tile(env, grouped, { visibleVariants: 'all' }).visibleVariants().length, 3);
    assert.equal(tile(env, grouped, { visibleVariants: '0' }).visibleVariants().length, 0);
});

test('the variants panel opens, closes and toggles', () => {
    const env = createEnvironment();
    const panel = tile(env, { type_id: 'grouped', children: sizes });

    panel.openVariantsPanel();
    assert.equal(panel.isVariantsPanelOpen(), true);
    panel.closeVariantsPanel();
    assert.equal(panel.isVariantsPanelOpen(), false);
    panel.toggleVariantsPanel();
    assert.equal(panel.variantsPanelOpen, true);
    panel.toggleVariantsPanel();
    assert.equal(panel.variantsPanelOpen, false);
});

test('child rows expose size parts, panel input ids, base prices and stock texts', () => {
    const env = createEnvironment();
    const rows = tile(env, { type_id: 'grouped', children: sizes });
    const [sixPack, litre, bare] = rows.variants();

    rows.child = sixPack;
    assert.deepEqual([...rows.sizeParts()], ['0.5 l', '40 %']);
    assert.equal(rows.childPanelInputId(), 'typesense-qty-9-21-panel');
    assert.equal(rows.isChildQtyEmpty(), true);
    rows.incrementChildQty();
    assert.equal(rows.isChildQtyEmpty(), false);
    assert.equal(rows.hasChildBasePrice(), false);

    rows.child = litre;
    assert.deepEqual([...rows.sizeParts()], ['1 l']);
    assert.equal(rows.hasChildBasePrice(), true);
    assert.equal(rows.childBasePrice(), 'EUR 10 / l');
    assert.equal(rows.childStockText(), 'Back in May');

    rows.child = bare;
    assert.deepEqual([...rows.sizeParts()], []);
    assert.equal(rows.childStockText(), 'Out of stock');

    const hidden = tile(env, { type_id: 'grouped', children: sizes }, {}, Object.assign({}, listingConfig, { showPrices: false }));
    hidden.child = litre;
    assert.equal(hidden.hasChildBasePrice(), false);
});

test('the parent stock text prefers stock_text', () => {
    const env = createEnvironment();

    assert.equal(tile(env, { in_stock: false, stock_text: 'Sold out until May' }).stockText(), 'Sold out until May');
    assert.equal(tile(env, { in_stock: false, stock_text: '' }).stockText(), 'Out of stock');
});

test('notify URLs use the hash prefix and fall back from the first sold-out child to the first child to the product', () => {
    const env = createEnvironment();
    const grouped = { type_id: 'grouped', url: '/gin.html', children: sizes };

    const custom = tile(env, grouped, { notifyHashPrefix: 'alert-' });
    custom.child = sizes[0];
    assert.equal(custom.childNotifyUrl(), '/gin.html#alert-21');
    assert.equal(custom.notifyUrl(), '/gin.html#alert-22');

    const allInStock = tile(env, Object.assign({}, grouped, { children: [sizes[0]] }));
    assert.equal(allInStock.notifyUrl(), '/gin.html#notify-21');
    assert.equal(tile(env, { url: '/gin.html' }).notifyUrl(), '/gin.html#notify-9');
    assert.equal(tile(env, {}).notifyUrl(), '#notify-9');
});

test('totals use the quantities rounded to each increment and respect hidden prices', () => {
    const env = createEnvironment();
    const grouped = { type_id: 'grouped', children: sizes };
    const totals = tile(env, grouped);
    const [sixPack, litre] = totals.variants();

    Object.assign(totals, { child: sixPack, $event: { target: { value: '7' } } }).recordChildQty();
    Object.assign(totals, { child: litre, $event: { target: { value: '2' } } }).setChildQty();

    assert.equal(totals.totalQty(), 14);
    assert.equal(totals.hasTotalQty(), true);
    assert.equal(totals.totalPriceText(), 'EUR 50');
    assert.equal(totals.showTotal(), true);

    const hidden = tile(env, grouped, {}, Object.assign({}, listingConfig, { showPrices: false }));
    Object.assign(hidden, { child: sixPack, $event: { target: { value: '6' } } }).setChildQty();
    assert.equal(hidden.totalQty(), 6);
    assert.equal(hidden.showTotal(), false);
    assert.equal(hidden.totalPriceText(), '');
});

test('isAddedToCart is set for 1.5 s after a successful add and its timer is cleared by a new add', async () => {
    const env = createEnvironment();
    const timers = new Map();
    let nextTimer = 0;
    env.context.setTimeout = (callback, delay) => {
        timers.set(++nextTimer, { callback, delay });
        return nextTimer;
    };
    env.context.clearTimeout = id => timers.delete(id);
    let ok = true;
    env.context.fetch = () => Promise.resolve({ ok, status: ok ? 200 : 500, redirected: false, json: async () => ({}) });
    const adding = tile(env, { type_id: 'simple' });

    await adding.addToCart();
    assert.equal(adding.isAddedToCart, true);
    assert.deepEqual([...timers.values()].map(timer => timer.delay), [1500]);

    const pending = adding.addToCart();
    assert.equal(adding.isAddedToCart, false, 'a new add resets the label');
    assert.equal(timers.size, 0, 'and clears the previous timer');
    await pending;
    assert.equal(timers.size, 1);
    [...timers.values()][0].callback();
    assert.equal(adding.isAddedToCart, false);

    ok = false;
    timers.clear();
    await adding.addToCart();
    assert.equal(adding.isAddedToCart, false);
    assert.equal(timers.size, 0);
});

test('item numbers cover the current page and are 0 without results', async () => {
    const env = createEnvironment('?page=2');
    env.responder = results(30, Array.from({ length: 12 }, (_, i) => hit(i + 1)));
    const listing = env.listing();
    listing.init();
    await settle();
    assert.deepEqual([listing.firstItemNumber(), listing.lastItemNumber()], [13, 24]);

    env.responder = results(30, Array.from({ length: 6 }, (_, i) => hit(i + 1)));
    listing.setPage(3);
    await settle();
    assert.deepEqual([listing.firstItemNumber(), listing.lastItemNumber()], [25, 30]);

    env.responder = results(0, []);
    listing.setPage(1);
    await settle();
    assert.deepEqual([listing.firstItemNumber(), listing.lastItemNumber()], [0, 0]);
});
