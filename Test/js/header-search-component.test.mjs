// Runs the Alpine component of search/script/header-search.phtml against stubs (no browser, no Alpine).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const moduleRoot = fileURLToPath(new URL('../../', import.meta.url));

function inlineScripts(file) {
    const source = readFileSync(moduleRoot + 'view/frontend/templates/' + file, 'utf8');

    return [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)]
        .map(match => match[1].replace(/<\?=[\s\S]*?\?>/g, (php) => {
            const phrase = php.match(/__\('((?:[^'\\]|\\.)*)'/);
            return phrase ? phrase[1] : '';
        }))
        .join('\n');
}

const baseConfig = {
    minQueryLength: 3, searchUrl: '/catalogsearch/result/', baseUrl: '/', productCollection: 'p',
    categoryCollection: 'c', pageCollection: null, suggestionCollection: 's', productCount: 4, categoryCount: 3,
    pageCount: 0, suggestionCount: 3, groupBy: null, highlight: true, showPrices: true, requestParams: {},
    directMatchesFirst: false, cacheParams: {}, relevanceSortBy: null,
};

const response = (term) => ({
    results: [
        { found: 7, hits: [{ document: { id: '1', name: term + ' Gin', sku: 'S1', url: '/gin.html', final_price: 20 } }] },
        { hits: [{ document: { id: '9', q: term + ' tonic', count: 4 } }] },
        { hits: [{ document: { id: '5', name: 'Spirits', url: '/spirits.html' } }] },
    ],
});

function createEnvironment(overrides = {}) {
    const config = Object.assign({}, baseConfig, overrides);
    const env = { events: [], timers: [], requests: [], params: [], responder: null, storage: {} };
    const input = {
        value: '',
        focused: true,
        validity: '',
        reported: 0,
        setCustomValidity(message) { this.validity = message; },
        reportValidity() { this.reported += 1; },
        addEventListener() {},
        focus() {},
        select() {},
    };
    const context = {
        console: { error() {}, warn() {}, log: console.log }, JSON, Math, Number, String, Object, Array, Promise,
        URL, encodeURIComponent,
        setTimeout: (fn, ms) => env.timers.push({ fn, ms }),
        clearTimeout: () => { env.timers = []; },
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
        location: { href: '/' },
        document: { get activeElement() { return input.focused ? input : null; } },
        hyva: { str: (template, ...args) => template.replace(/%(\d)/g, (_, i) => args[i - 1]), formatPrice: value => 'EUR ' + value },
        Alpine: { data() {} },
        dispatchEvent: event => env.events.push(event),
        addEventListener() {},
        localStorage: { getItem: key => env.storage[key] ?? null, setItem: (key, value) => { env.storage[key] = value; } },
    };
    context.window = context;
    vm.createContext(context);
    vm.runInContext(readFileSync(moduleRoot + 'view/frontend/web/js/listing-core.js', 'utf8'), context);
    vm.runInContext(inlineScripts('search/script/header-search.phtml'), context);
    context.runAsRootTypesense.getClient = async () => ({
        multiSearch: {
            perform: async (body, params) => {
                env.requests.push(body.searches);
                env.params.push(params);
                return env.responder(body);
            },
        },
    });
    env.context = context;
    env.input = input;
    env.search = () => {
        const component = context.initTypesenseHeaderSearch();
        component.$root = {
            querySelector: () => ({ textContent: JSON.stringify(config) }),
            contains: element => element === input,
        };
        component.$refs = { input };
        component.init();
        return component;
    };
    env.type = (component, value) => {
        input.value = value;
        component.$event = { target: input };
        component.onInput();
    };
    env.key = (component, key) => {
        const event = {
            key, defaultPrevented: false, propagationStopped: false,
            preventDefault() { this.defaultPrevented = true; },
            stopPropagation() { this.propagationStopped = true; },
        };
        component.$event = event;
        component.onKeydown();
        return event;
    };

    return env;
}

const settle = () => new Promise(resolve => setImmediate(resolve));
const tracked = (env, type) => env.events.filter(e => e.type === type).map(e => JSON.parse(JSON.stringify(e.detail)));

test('queries below the minimum length send nothing; longer ones search once after a 300 ms debounce', async () => {
    const env = createEnvironment();
    env.responder = () => response('gin');
    const search = env.search();

    env.type(search, 'gi');
    assert.equal(env.timers.length, 0);
    env.type(search, 'gin');
    env.type(search, 'gin ');
    assert.equal(env.timers.length, 1);
    assert.equal(env.timers[0].ms, 300);

    await env.timers[0].fn();
    assert.equal(env.requests.length, 1);
    assert.equal(env.requests[0][0].q, 'gin');
    assert.equal(search.isOpen, true);
    assert.deepEqual(Array.from(search.rows, r => r.type), ['suggestion', 'product', 'category']);
    assert.equal(search.seeAllUrl(), '/catalogsearch/result/?q=gin');
    assert.equal(search.seeAllText(), 'See all (7)');
});

test('every header search request carries the visitor id kept in localStorage', async () => {
    const env = createEnvironment({ cacheParams: { use_cache: true }, popularSearchCount: 4 });
    env.responder = (body) => (body.searches[0].collection === 's' ? { results: [{ hits: [] }] } : response('gin'));
    const search = env.search();

    search.onFocus();
    await settle();
    env.type(search, 'gin');
    await env.timers[0].fn();

    const visitor = env.storage['typesense-visitor-id'];
    assert.equal(typeof visitor, 'string');
    assert.equal(env.params.length, 2);
    for (const params of env.params) {
        assert.deepEqual(JSON.parse(JSON.stringify(params)), { use_cache: true, 'x-typesense-user-id': visitor });
    }
});

test('a failing Typesense request hides the dropdown and Enter still submits the form', async () => {
    const env = createEnvironment();
    env.responder = () => response('gin');
    const search = env.search();
    env.type(search, 'gin');
    await search.search();
    assert.equal(search.isOpen, true);

    env.responder = () => { throw Object.assign(new Error('Service Unavailable'), { httpStatus: 503 }); };
    env.type(search, 'ginger');
    await search.search();
    assert.equal(search.isOpen, false);
    assert.equal(search.rows.length, 0);

    assert.equal(env.key(search, 'Enter').defaultPrevented, false);
    const submit = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    search.$event = submit;
    search.onSubmit();
    assert.equal(submit.defaultPrevented, false);

    env.responder = () => ({ results: [{ code: 404, error: 'Collection not found' }] });
    await search.search();
    assert.equal(search.isOpen, false);
});

test('a submit below the minimum length is blocked with a validity message', () => {
    const env = createEnvironment();
    const search = env.search();
    env.type(search, 'gi');
    const submit = { defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    search.$event = submit;

    search.onSubmit();

    assert.equal(submit.defaultPrevented, true);
    assert.equal(env.input.validity, 'Minimum Search query length is 3');
    assert.equal(env.input.reported, 1);
});

test('a slower response for an older query never replaces the newer one', async () => {
    const env = createEnvironment();
    const pending = {};
    env.responder = body => new Promise(resolve => { pending[body.searches[0].q] = () => resolve(response(body.searches[0].q)); });
    const search = env.search();

    env.type(search, 'gin');
    const first = search.search();
    await settle();
    env.type(search, 'rum');
    const second = search.search();
    await settle();
    pending.rum();
    await second;
    pending.gin();
    await first;

    assert.equal(search.searchedTerm, 'rum');
    assert.equal(search.sections.products[0].name, 'rum Gin');

    env.type(search, 'vodka');
    const third = search.search();
    await settle();
    env.type(search, 'vo');
    pending.vodka();
    await third;
    assert.equal(search.isOpen, false, 'shortening the query below the minimum drops the pending response');
});

test('arrow keys move the active option, Enter follows it and Escape closes only the dropdown', async () => {
    const env = createEnvironment();
    env.responder = () => response('gin');
    const search = env.search();
    env.type(search, 'gin');
    await search.search();

    assert.equal(search.activeDescendant(), false);
    env.key(search, 'ArrowDown');
    env.key(search, 'ArrowDown');
    assert.equal(search.activeDescendant(), 'typesense-ac-option-1');
    search.row = search.rows[1];
    assert.equal(search.rowSelected(), 'true');
    assert.equal(search.rowClasses(), 'bg-gray-100');
    env.key(search, 'ArrowUp');
    env.key(search, 'ArrowUp');
    assert.equal(search.activeIndex, 2, 'wraps around');
    env.key(search, 'ArrowUp');

    const enter = env.key(search, 'Enter');
    assert.equal(enter.defaultPrevented, true);
    assert.equal(env.context.location.href, '/gin.html');
    assert.deepEqual(tracked(env, 'typesense:suggestion-click'), [{
        type: 'product', term: 'gin', name: 'gin Gin', url: '/gin.html', index: 0,
        item: { item_id: 'S1', item_name: 'gin Gin', price: 20, index: 0, item_list_name: 'search_suggestions' },
    }]);

    const escape = env.key(search, 'Escape');
    assert.equal(escape.propagationStopped, true);
    assert.equal(search.isOpen, false);
    assert.equal(env.key(search, 'Escape').propagationStopped, false, 'a closed dropdown lets the theme close its panel');
    env.key(search, 'ArrowDown');
    assert.equal(search.isOpen, true, 'an arrow key reopens the current results');
    assert.equal(search.activeIndex, -1);
    search.close();

    search.onFocus();
    assert.equal(search.isOpen, true, 'focus reopens the results of the current query');
});

test('row and see-all clicks dispatch suggestion-click events', async () => {
    const env = createEnvironment();
    env.responder = () => response('gin');
    const search = env.search();
    env.type(search, 'gin');
    await search.search();

    search.row = search.sections.categories[0];
    search.onRowClick();
    search.onSeeAllClick();

    assert.deepEqual(tracked(env, 'typesense:suggestion-click'), [
        { type: 'category', term: 'gin', name: 'Spirits', url: '/spirits.html', index: 0 },
        { type: 'see_all', term: 'gin', name: 'gin', url: '/catalogsearch/result/?q=gin', index: 0 },
    ]);
});

test('the dropdown shows the no-results line when no product matches', async () => {
    const env = createEnvironment();
    env.responder = () => ({ results: [{ found: 0, hits: [] }, { hits: [] }, { hits: [] }] });
    const search = env.search();
    env.type(search, 'xyz');
    await search.search();

    assert.equal(search.isOpen, true);
    assert.equal(search.hasFound(), false);
    assert.equal(search.hasNoProducts(), true);
    assert.equal(search.noResultsText(), 'No products for "xyz"');
});

const crossSellConfig = {
    productMetaFields: ['type', 'volume_percent'], crossSellCount: 2, crossSellFallbackField: 'taste',
    crossSellFallbackSortBy: 'sales_count:desc', crossSellMetaFields: ['type'], popularSearchCount: 2,
};
const productHit = (id, extra = {}) => ({
    document: Object.assign({ id: String(id), name: 'Gin ' + id, sku: 'S' + id, url: '/gin-' + id + '.html', final_price: 20 }, extra),
});
// Product hits answer the dropdown's search, cross-sell searches (q '*' with an id or taste filter) the cards.
const crossSellResponder = (body) => {
    const first = body.searches[0];
    if (first.q !== '*') {
        return {
            results: [
                { found: 1, hits: [productHit(1, { type: ['Brandy'], volume_percent: ['42.0 %'], crosssell_ids: [5], taste: ['Pear'] })] },
                { hits: [] },
                { hits: [] },
            ],
        };
    }
    if (first.collection === 's') {
        return { results: [{ hits: [{ document: { id: '1', q: 'pear', count: 9 } }, { document: { id: '2', q: 'gin', count: 3 } }] }] };
    }

    return {
        results: body.searches.map(search => (search.filter_by.startsWith('id:[')
            ? { hits: [productHit(5, { type: ['Liqueur'] })] }
            : { hits: [productHit(8), productHit(9)] })),
    };
};

test('product rows carry their meta line and the found total is worded for the header', async () => {
    const env = createEnvironment(crossSellConfig);
    env.responder = crossSellResponder;
    const search = env.search();
    env.type(search, 'gin');
    await search.search();

    search.row = search.sections.products[0];
    assert.equal(search.rowMeta(), 'Brandy · 42 %');
    assert.equal(search.hasRowMeta(), true);
    assert.equal(search.foundText(), '%1 result'.replace('%1', 1));
    search.found = 3;
    assert.equal(search.foundText(), '3 results');
});

test('the top hit\'s cross-sells load after its hits are shown and fill up with fallback best sellers', async () => {
    const env = createEnvironment(crossSellConfig);
    let release;
    env.responder = (body) => (body.searches[0].q === '*'
        ? new Promise(resolve => { release = () => resolve(crossSellResponder(body)); })
        : crossSellResponder(body));
    const search = env.search();
    env.type(search, 'gin');
    await search.search();
    await settle();

    assert.equal(search.isOpen, true, 'the hits are shown before the cross-sells arrive');
    assert.equal(search.hasCrossSells(), false);
    assert.equal(env.requests.length, 2);
    assert.deepEqual(Array.from(env.requests[1], s => s.filter_by), [
        'id:[`5`] && in_stock:=true && visibility:[2,4]',
        'taste:=[`Pear`] && in_stock:=true && visibility:[2,4] && id:!=[`1`,`5`]',
    ]);

    release();
    await settle();
    assert.deepEqual(Array.from(search.crossSells, card => card.key), ['crosssell-5', 'crosssell-8']);
    search.card = search.crossSells[0];
    assert.equal(search.cardMeta(), 'Liqueur');
    assert.equal(search.hasCardMeta(), true);
    assert.equal(search.cardPriceText(), 'EUR 20');
    search.onCardClick();
    assert.deepEqual(tracked(env, 'typesense:suggestion-click'), [{
        type: 'crosssell', term: 'gin', name: 'Gin 5', url: '/gin-5.html', index: 0,
        item: { item_id: 'S5', item_name: 'Gin 5', price: 20, index: 0, item_list_name: 'search_suggestions' },
    }]);

    env.type(search, 'gins');
    await search.search();
    await settle();
    assert.equal(env.requests.length, 3, 'the same top hit reuses its cards');
    assert.deepEqual(Array.from(search.crossSells, card => card.key), ['crosssell-5', 'crosssell-8']);
});

test('cross-sells of an older query never replace newer results and a failure keeps the hits', async () => {
    const env = createEnvironment(crossSellConfig);
    const pending = [];
    env.responder = (body) => (body.searches[0].q === '*'
        ? new Promise((resolve, reject) => pending.push({ resolve: () => resolve(crossSellResponder(body)), reject }))
        : { results: [{ found: 1, hits: [productHit(1, { crosssell_ids: body.searches[0].q === 'rum' ? [6] : [5] })] }, { hits: [] }, { hits: [] }] });
    const search = env.search();
    env.type(search, 'gin');
    await search.search();
    await settle();
    env.type(search, 'g');
    pending[0].resolve();
    await settle();
    assert.equal(search.hasCrossSells(), false, 'a cleared query drops the late cards');

    env.type(search, 'rum');
    await search.search();
    await settle();
    pending[1].reject(new Error('Service Unavailable'));
    await settle();
    assert.equal(search.isOpen, true);
    assert.equal(search.rows.length, 1);
    assert.equal(search.hasCrossSells(), false);
});

test('focusing the empty input shows the popular searches, loaded once', async () => {
    const env = createEnvironment(crossSellConfig);
    env.responder = crossSellResponder;
    const search = env.search();

    assert.equal(search.isIdle(), true);
    assert.equal(search.hasSearched(), false);
    search.onFocus();
    await settle();
    assert.equal(search.isOpen, true);
    assert.equal(search.hasPopular(), true);
    assert.deepEqual(search.popular.map(chip => [chip.name, chip.url]), [
        ['pear', '/catalogsearch/result/?q=pear'],
        ['gin', '/catalogsearch/result/?q=gin'],
    ]);
    assert.equal(search.hasProducts(), false);

    search.chip = search.popular[1];
    search.onPopularClick();
    assert.deepEqual(tracked(env, 'typesense:suggestion-click'), [
        { type: 'popular', term: '', name: 'gin', url: '/catalogsearch/result/?q=gin', index: 1 },
    ]);

    search.close();
    search.onFocus();
    await settle();
    assert.equal(search.isOpen, true, 'focus reopens them');
    env.type(search, 'gi');
    assert.equal(search.isOpen, true, 'a query below the minimum length keeps them open');
    env.type(search, 'gin');
    await search.search();
    assert.equal(search.isIdle(), false);
    assert.equal(search.hasSearched(), true);
    assert.equal(search.hasPopular(), true, 'and they stay next to the results');
    assert.equal(env.requests.filter(searches => searches[0].collection === 's' && searches[0].q === '*').length, 1);
});

test('without popular searches or cross-sells configured nothing extra is requested', async () => {
    const env = createEnvironment();
    env.responder = () => response('gin');
    const search = env.search();

    search.onFocus();
    await settle();
    assert.equal(search.isOpen, false);
    env.type(search, 'gin');
    await search.search();
    await settle();
    assert.equal(env.requests.length, 1);
    assert.equal(search.hasCrossSells(), false);
});

test('the header search templates bind only CSP-safe property and method references', () => {
    const files = ['form-mini.phtml', 'autocomplete/suggestion.phtml', 'autocomplete/popular.phtml', 'autocomplete/product.phtml',
        'autocomplete/crosssell.phtml', 'autocomplete/category.phtml', 'autocomplete/page.phtml', 'autocomplete/see-all.phtml'];
    for (const file of files) {
        const source = readFileSync(moduleRoot + 'view/frontend/templates/search/' + file, 'utf8');
        assert.doesNotMatch(source, /<script>|x-html|innerHTML/, file);
        const expressions = [...source.matchAll(/\s(?:x-[a-z-]+|:[a-z-]+|@[a-z.-]+)="([^"]*)"/g)]
            .map(match => match[1])
            .filter(expression => !/^[a-zA-Z_$][\w$.]*( in [a-zA-Z_$][\w$.]*)?$/.test(expression));
        assert.deepEqual(expressions, [], file);
    }
});
