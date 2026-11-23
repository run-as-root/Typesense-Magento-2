// Runs the inline Alpine component of search/form-mini.phtml against stubs (no browser, no Alpine).
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

const config = {
    minQueryLength: 3, searchUrl: '/catalogsearch/result/', baseUrl: '/', productCollection: 'p',
    categoryCollection: 'c', pageCollection: null, suggestionCollection: 's', productCount: 4, categoryCount: 3,
    pageCount: 0, suggestionCount: 3, groupBy: null, highlight: true, showPrices: true, requestParams: {},
    directMatchesFirst: false, cacheParams: {}, relevanceSortBy: null,
};

const response = (term) => ({
    results: [
        { found: 7, hits: [{ document: { id: '1', name: term + ' Gin', sku: 'S1', url: '/gin.html', final_price: 20 } }] },
        { hits: [{ document: { id: '9', query: term + ' tonic' } }] },
        { hits: [{ document: { id: '5', name: 'Spirits', url: '/spirits.html' } }] },
    ],
});

function createEnvironment() {
    const env = { events: [], timers: [], requests: [], responder: null };
    const input = {
        value: '',
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
        document: { activeElement: input },
        hyva: { str: (template, ...args) => template.replace(/%(\d)/g, (_, i) => args[i - 1]), formatPrice: value => 'EUR ' + value },
        Alpine: { data() {} },
        dispatchEvent: event => env.events.push(event),
        addEventListener() {},
    };
    context.window = context;
    vm.createContext(context);
    vm.runInContext(readFileSync(moduleRoot + 'view/frontend/web/js/listing-core.js', 'utf8'), context);
    vm.runInContext(inlineScripts('search/form-mini.phtml'), context);
    context.runAsRootTypesense.getClient = async () => ({
        multiSearch: { perform: async (body) => { env.requests.push(body.searches); return env.responder(body); } },
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
