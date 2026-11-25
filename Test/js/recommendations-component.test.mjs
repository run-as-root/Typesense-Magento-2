// Runs the inline Alpine components of product/recommendations.phtml against stubs (no browser, no Alpine).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const moduleRoot = fileURLToPath(new URL('../../', import.meta.url));
const templateSource = readFileSync(moduleRoot + 'view/frontend/templates/product/recommendations.phtml', 'utf8');

function inlineScripts(source) {
    return [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)]
        .map(match => match[1].replace(/<\?=[\s\S]*?\?>/g, (php) => {
            const phrase = php.match(/__\('((?:[^'\\]|\\.)*)'/);
            return phrase ? phrase[1] : '';
        }))
        .join('\n');
}

const baseConfig = { productCollection: 'products_de', productId: '42', limit: 4, showPrices: true };

function createEnvironment(responder) {
    const env = { requests: [], registered: {} };
    const context = {
        console: { error() {}, warn() {}, log: console.log }, JSON, Math, Number, String, Object, Array, Promise,
        hyva: { formatPrice: value => 'EUR ' + Number(value).toFixed(2) },
        Alpine: { data: (name, fn) => { env.registered[name] = fn; } },
        addEventListener: (type, listener) => { if (type === 'alpine:init') listener(); },
    };
    context.window = context;
    vm.createContext(context);
    vm.runInContext(inlineScripts(templateSource), context);
    context.runAsRootTypesense = {
        getClient: async () => ({
            collections: name => ({
                documents: () => ({
                    search: async (params) => {
                        env.requests.push({ name, params });
                        return responder(params);
                    },
                }),
            }),
        }),
    };
    env.context = context;
    env.mount = async (config = baseConfig) => {
        const component = env.registered.initTypesenseRecommendations();
        component.$root = { querySelector: () => ({ textContent: JSON.stringify(config) }) };
        component.$refs = { slider: { scrolled: [], scrollBy(options) { this.scrolled.push(options.left); } } };
        await component.init();
        return component;
    };
    env.item = (component, item) => Object.assign(env.registered.initTypesenseRecommendation(), {
        item,
        config: component.config,
    });

    return env;
}

const hostile = {
    id: '7', name: '"><img src=x onerror=alert(1)>', url: 'https://shop.test/a.html" onmouseover="x',
    image_url: 'https://shop.test/i.jpg"x', final_price: 19.9, regular_price: 24.5,
};

test('registers both components and searches similar in-stock products through the shared client', async () => {
    const env = createEnvironment(() => ({ hits: [{ document: hostile }] }));
    const component = await env.mount();

    assert.equal(env.requests.length, 1);
    assert.equal(env.requests[0].name, 'products_de');
    assert.equal(env.requests[0].params.vector_query, 'embedding:([], id:42)');
    assert.equal(env.requests[0].params.filter_by, 'id:!=42 && in_stock:true');
    assert.equal(env.requests[0].params.per_page, 4);
    assert.equal(env.requests[0].params.enable_analytics, false, 'a q=* search must not count as a popular query');
    assert.equal(component.loading, false);
    assert.equal(component.visible(), true);
    assert.equal(component.recommendations.length, 1);
});

test('item getters return document values as plain strings for text and attribute bindings', async () => {
    const env = createEnvironment(() => ({ hits: [{ document: hostile }] }));
    const component = await env.mount();
    const item = env.item(component, component.recommendations[0]);

    assert.equal(item.name(), hostile.name);
    assert.equal(item.url(), hostile.url);
    assert.equal(item.imageUrl(), hostile.image_url);
    assert.equal(item.hasImage(), true);
    assert.equal(item.hasNoImage(), false);
});

test('prices go through hyva.formatPrice and the regular price only shows on a discount', async () => {
    const env = createEnvironment(() => ({ hits: [{ document: hostile }, { document: { id: '8', final_price: 10, regular_price: 10 } }] }));
    const component = await env.mount();
    const discounted = env.item(component, component.recommendations[0]);
    const plain = env.item(component, component.recommendations[1]);

    assert.equal(discounted.showPrice(), true);
    assert.equal(discounted.finalPriceText(), 'EUR 19.90');
    assert.equal(discounted.showRegularPrice(), true);
    assert.equal(discounted.regularPriceText(), 'EUR 24.50');
    assert.equal(plain.showRegularPrice(), false);
});

test('prices are hidden when showPrices is false or the price is not above 0', async () => {
    const env = createEnvironment(() => ({ hits: [{ document: hostile }, { document: { id: '8', final_price: 0 } }] }));
    const component = await env.mount(Object.assign({}, baseConfig, { showPrices: false }));
    const hidden = env.item(component, component.recommendations[0]);

    assert.equal(hidden.showPrice(), false);
    assert.equal(hidden.showRegularPrice(), false);

    const shown = await env.mount();
    assert.equal(env.item(shown, shown.recommendations[1]).showPrice(), false);
});

test('a document without image shows the placeholder', async () => {
    const env = createEnvironment(() => ({ hits: [{ document: { id: '9', name: 'X', url: '/x.html' } }] }));
    const component = await env.mount();
    const item = env.item(component, component.recommendations[0]);

    assert.equal(item.hasImage(), false);
    assert.equal(item.hasNoImage(), true);
    assert.equal(item.imageUrl(), null);
});

test('the block hides when nothing is found or Typesense fails', async () => {
    const empty = await createEnvironment(() => ({ hits: [] })).mount();
    assert.equal(empty.visible(), false);

    const failing = await createEnvironment(() => { throw new Error('down'); }).mount();
    assert.equal(failing.loading, false);
    assert.equal(failing.visible(), false);
});

test('arrow buttons scroll the slider', async () => {
    const component = await createEnvironment(() => ({ hits: [] })).mount();

    component.slideLeft();
    component.slideRight();

    assert.deepEqual(component.$refs.slider.scrolled, [-280, 280]);
});

test('the template renders document data only through x-text and attribute bindings', () => {
    assert.doesNotMatch(templateSource, /innerHTML|x-html|toFixed|'\$'/);
    const expressions = [...templateSource.matchAll(/\s(?:x-[a-z-]+|:[a-z-]+|@[a-z-]+)="([^"]*)"/g)]
        .map(match => match[1])
        .filter(expression => !/^[a-zA-Z_$][\w$.]*( in [a-zA-Z_$][\w$]*)?$/.test(expression));
    assert.deepEqual(expressions, []);
});
