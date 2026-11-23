// Runs the inline script of tracking/ga4.phtml against a stub window.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const moduleRoot = fileURLToPath(new URL('../../', import.meta.url));
const script = [...readFileSync(moduleRoot + 'view/frontend/templates/tracking/ga4.phtml', 'utf8')
    .matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1]).join('\n');

test('ga4.phtml maps the typesense window events to dataLayer pushes', () => {
    const listeners = {};
    const context = {
        URL, Object, Array, String, Number, JSON,
        addEventListener: (name, fn) => { listeners[name] = fn; },
    };
    context.window = context;
    vm.createContext(context);
    vm.runInContext(readFileSync(moduleRoot + 'view/frontend/web/js/listing-core.js', 'utf8'), context);
    vm.runInContext(script, context);

    assert.deepEqual(Object.keys(listeners).sort(), [
        'typesense:filter-change', 'typesense:results', 'typesense:select-item', 'typesense:sort-change',
        'typesense:suggestion-click',
    ]);
    const items = [{ item_id: 'S1', item_name: 'Gin', index: 0, item_list_name: 'Gin' }];
    const results = { type: 'typesense:results', detail: { context: 'category', listName: 'Gin', page: 1, stateKey: '', items } };
    listeners['typesense:results'](results);
    listeners['typesense:results'](results);
    listeners['typesense:sort-change']({ type: 'typesense:sort-change', detail: { sort: 'price:asc' } });

    assert.deepEqual(JSON.parse(JSON.stringify(context.dataLayer)), [
        { ecommerce: null },
        { event: 'view_item_list', ecommerce: { item_list_name: 'Gin', items } },
        { ecommerce: null },
        { event: 'sorting_interaction', sorting_option: 'price:asc' },
    ]);
});
