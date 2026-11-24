// Runs the Alpine scripts of the filter bar and toolbar components against stubs (no browser, no Alpine).
// Alpine merges the scopes of nested components and x-for variables; the tests merge them with Object.assign.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const moduleRoot = fileURLToPath(new URL('../../', import.meta.url));
const templates = moduleRoot + 'view/frontend/templates/listing/';
const scriptFiles = ['script/events.phtml', 'script/filters/mobile-drawer.phtml', 'script/filters/bar.phtml',
    'script/filters/dropdown.phtml', 'script/filters/checkbox.phtml', 'script/filters/slider.phtml',
    'script/filters/active-filters.phtml', 'script/filters/sorting.phtml', 'script/filters/page-size.phtml'];

function inlineScripts(file) {
    const source = readFileSync(templates + file, 'utf8');

    return [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)]
        .map(match => match[1].replace(/<\?=[\s\S]*?\?>/g, (php) => {
            const phrase = php.match(/__\('((?:[^'\\]|\\.)*)'/);
            return phrase ? phrase[1] : '';
        }))
        .join('\n');
}

const config = {
    context: 'category',
    facets: [
        { field: 'categories.lvl1', label: 'Category', type: 'subcategory', prefix: 'Gin > ' },
        { field: 'color', label: 'Color', type: 'dropdown', searchable: true },
        { field: 'in_stock', label: 'Availability', type: 'checkbox', boolean: true },
        { field: 'is_new', label: 'New', type: 'checkbox', boolean: true },
        { field: 'price', label: 'Price', type: 'slider' },
    ],
    sortOptions: [{ value: '', label: 'Relevance' }, { value: 'price:asc', label: 'Price' }],
    defaultSort: '',
    limits: [12, 24],
    perPage: 36,
    showPrices: true,
};

function snapshot(overrides = {}) {
    return Object.assign({
        context: 'category', isLoading: false, hasLoaded: true, found: 3, page: 1, totalPages: 1, error: null,
        priceStats: { min: 10, max: 200 },
        facets: [
            { field_name: 'categories.lvl1', counts: [{ value: 'Gin > Dry', count: 2 }, { value: 'Rum > White', count: 9 }] },
            { field_name: 'color', counts: [{ value: 'Black-White', count: 2 }, { value: 'Red', count: 1 }] },
            { field_name: 'in_stock', counts: [{ value: 'true', count: 3 }, { value: 'false', count: 1 }] },
            { field_name: 'is_new', counts: [{ value: 'false', count: 3 }] },
        ],
        state: { q: '', page: 1, sort: '', limit: 36, filters: {}, price: null, facetQueries: {} },
    }, overrides);
}

function createEnvironment() {
    const env = { events: [], bodyClasses: new Set(), trapped: null, viewportListeners: [], desktop: true };
    const context = {
        console, JSON, Math, Number, String, Object, Array, Boolean,
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init && init.detail; } },
        document: {
            activeElement: null,
            body: { classList: { add: c => env.bodyClasses.add(c), remove: c => env.bodyClasses.delete(c) } },
            querySelector: () => ({ textContent: JSON.stringify(config) }),
        },
        hyva: {
            str: (template, ...args) => template.replace(/%(\d)/g, (_, i) => args[i - 1]),
            formatPrice: value => 'EUR ' + value,
            trapFocus: element => { env.trapped = element; },
            releaseFocus: () => { env.trapped = null; },
        },
        matchMedia: () => ({
            get matches() { return env.desktop; },
            addEventListener: (type, listener) => env.viewportListeners.push(listener),
        }),
        Alpine: { data() {} },
        dispatchEvent: event => env.events.push(event),
        addEventListener() {},
    };
    context.window = context;
    vm.createContext(context);
    vm.runInContext(readFileSync(moduleRoot + 'view/frontend/web/js/listing-core.js', 'utf8'), context);
    for (const file of scriptFiles) {
        vm.runInContext(inlineScripts(file), context);
    }
    const magics = { $root: { closest: () => null, dataset: {} }, $nextTick: callback => callback(), $refs: {} };
    env.component = (name, scope = {}) => {
        const component = Object.assign(context[name](), magics, scope);
        if (typeof component.init === 'function') {
            component.init();
        }
        component.$event = { detail: snapshot() };
        if (component.onListingState) {
            component.onListingState();
        }
        return component;
    };
    // A child component evaluated inside the bar: the bar's data and methods plus the x-for variables.
    env.child = (bar, name, scope) => Object.assign(Object.create(bar), context[name](), scope);
    env.lastEvent = () => env.events[env.events.length - 1];
    env.context = context;

    return env;
}

test('facets are routed by type and the slider disappears when prices are hidden', () => {
    const env = createEnvironment();
    const bar = env.component('initTypesenseFilterBar');

    assert.deepEqual(bar.subcategoryFacets().map(facet => facet.field), ['categories.lvl1']);
    assert.deepEqual(bar.dropdownFacets().map(facet => facet.field), ['color']);
    // is_new has no `true` count, so the checkbox group only offers in_stock.
    assert.deepEqual(bar.checkboxFacets().map(facet => facet.field), ['in_stock']);
    assert.deepEqual(bar.sliderFacets().map(facet => facet.field), ['price']);

    bar.listingConfig = Object.assign({}, config, { showPrices: false });
    bar.$event = { detail: snapshot({ state: Object.assign(snapshot().state, { price: { min: 20, max: 50 } }) }) };
    bar.onListingState();
    assert.equal(bar.sliderFacets().length, 0);
    assert.equal(bar.hasPriceFilter(), false);
});

test('the price slider is hidden when every hit has the same price', () => {
    const env = createEnvironment();
    const bar = env.component('initTypesenseFilterBar');

    bar.$event = { detail: snapshot({ priceStats: { min: 0, max: 0 } }) };
    bar.onListingState();

    assert.equal(bar.sliderFacets().length, 0);
});

test('dropdown options toggle and search with structured field/value payloads', () => {
    const env = createEnvironment();
    const bar = env.component('initTypesenseFilterBar');
    const facet = bar.dropdownFacets()[0];
    const dropdown = env.child(bar, 'initTypesenseFilterDropdown', { facet });
    dropdown.visibleValues = 1;

    assert.equal(dropdown.visibleOptions().length, 1);
    assert.equal(dropdown.showMoreToggle(), true);
    dropdown.toggleExpanded();
    assert.equal(dropdown.visibleOptions().length, 2);
    assert.equal(dropdown.moreLessText(), 'Less');

    dropdown.option = dropdown.visibleOptions()[0];
    dropdown.toggleOption();
    assert.deepEqual(env.lastEvent().type, 'typesense-filter-change');
    assert.deepEqual({ ...env.lastEvent().detail }, { field: 'color', value: 'Black-White', action: 'toggle' });

    dropdown.$event = { target: { value: ' bla ' } };
    dropdown.onValueSearch();
    assert.deepEqual({ ...env.lastEvent().detail }, { field: 'color', facetQuery: 'bla' });
});

test('subcategory options are the direct children of the current category, by name', () => {
    const env = createEnvironment();
    const bar = env.component('initTypesenseFilterBar');
    const dropdown = env.child(bar, 'initTypesenseFilterDropdown', { facet: bar.subcategoryFacets()[0] });

    assert.deepEqual(JSON.parse(JSON.stringify(dropdown.options().map(option => [option.value, option.label]))), [['Gin > Dry', 'Dry']]);
});

test('a dropdown stays while its value search matches nothing', () => {
    const env = createEnvironment();
    const bar = env.component('initTypesenseFilterBar');
    bar.$event = { detail: snapshot({
        facets: [{ field_name: 'color', counts: [] }],
        state: Object.assign(snapshot().state, { facetQueries: { color: 'zzz' } }),
    }) };
    bar.onListingState();

    assert.deepEqual(bar.dropdownFacets().map(facet => facet.field), ['color']);
    const dropdown = env.child(bar, 'initTypesenseFilterDropdown', { facet: bar.dropdownFacets()[0] });
    assert.equal(dropdown.showNoValues(), true);
});

test('boolean checkboxes only ever send true', () => {
    const env = createEnvironment();
    const bar = env.component('initTypesenseFilterBar');
    const group = env.child(bar, 'initTypesenseFilterCheckbox', { facet: bar.checkboxFacets()[0] });

    assert.equal(group.trueCount(), 3);
    group.toggleFacet();
    assert.deepEqual({ ...env.lastEvent().detail }, { field: 'in_stock', value: 'true', action: 'toggle' });
});

test('the price slider clamps to the stats bounds and clears the filter at the full range', () => {
    const env = createEnvironment();
    const bar = env.component('initTypesenseFilterBar');
    const slider = env.child(bar, 'initTypesenseFilterSlider', { facet: bar.sliderFacets()[0] });
    slider.syncRange();
    assert.deepEqual([slider.minValue, slider.maxValue], [10, 200]);

    slider.$event = { target: { value: '500' } };
    slider.onMaxNumberChange();
    assert.equal(env.events.length, 0, 'the full range is no filter, so nothing is sent');

    slider.$event = { target: { value: '150' } };
    slider.onMinRangeInput();
    slider.$event = { target: { value: '140' } };
    slider.onMaxRangeInput();
    assert.deepEqual([slider.minValue, slider.maxValue], [150, 150], 'the thumbs do not cross');

    slider.minValue = 5;
    slider.maxValue = 80;
    slider.applyRange();
    assert.deepEqual(JSON.parse(JSON.stringify(env.lastEvent().detail)), { price: { min: 10, max: 80 } });
    assert.equal(slider.minValueText(), 'EUR 5');
});

test('active chips remove exactly their value and Clear All clears everything', () => {
    const env = createEnvironment();
    const chips = env.component('initTypesenseActiveFilters');
    chips.$event = { detail: snapshot({ state: Object.assign(snapshot().state, {
        filters: { color: ['Black-White'], in_stock: ['true'] },
        price: { min: 10, max: 50 },
    }) }) };
    chips.onListingState();

    assert.equal(chips.showActiveFilters(), true);
    assert.equal(chips.priceChipText(), 'Price: EUR 10 – EUR 50');
    chips.chip = chips.activeChips()[0];
    assert.equal(chips.chipText(), 'Color: Black-White');
    chips.removeChip();
    assert.deepEqual({ ...env.lastEvent().detail }, { field: 'color', value: 'Black-White', action: 'remove' });
    chips.chip = chips.activeChips()[1];
    assert.equal(chips.chipText(), 'Availability: Yes');

    chips.removePriceChip();
    assert.deepEqual({ ...env.lastEvent().detail }, { price: null });
    chips.clearAll();
    assert.deepEqual({ ...env.lastEvent().detail }, { clear: true });

    chips.listingConfig = Object.assign({}, config, { showPrices: false });
    assert.equal(chips.showPriceChip(), false);
});

test('controls are hidden only on a confirmed empty result without filters', () => {
    const env = createEnvironment();
    const bar = env.component('initTypesenseFilterBar');
    const show = detail => {
        bar.$event = { detail: snapshot(detail) };
        bar.onListingState();
        return [bar.showControlsSkeleton(), bar.showControls()];
    };

    assert.deepEqual(show({ hasLoaded: false, isLoading: true, found: null }), [true, false]);
    assert.deepEqual(show({ found: 0 }), [false, false]);
    assert.deepEqual(show({ found: 0, isLoading: true }), [false, true]);
    assert.deepEqual(show({ found: 0, state: Object.assign(snapshot().state, { filters: { color: ['Red'] } }) }), [false, true]);
    assert.deepEqual(show({ found: 0, error: { kind: 'server' } }), [false, false]);
    assert.deepEqual(show({}), [false, true]);
});

test('the mobile drawer locks scrolling, traps focus and resets when the viewport becomes desktop', () => {
    const env = createEnvironment();
    env.desktop = false;
    const toggle = { focused: false, focus() { this.focused = true; } };
    const bar = env.component('initTypesenseFilterBar', { $refs: { drawer: 'drawer', drawerToggle: toggle } });

    assert.equal(bar.drawerInert(), true);
    bar.openDrawer();
    assert.equal(bar.drawerInert(), false);
    assert.equal(bar.drawerRole(), 'dialog');
    assert.equal(env.bodyClasses.has('overflow-hidden'), true);
    assert.equal(env.trapped, 'drawer');
    assert.equal(bar.showItemsText(), 'Show 3 items');

    bar.togglePanel('facet:color');
    bar.togglePanel('price');
    assert.deepEqual({ ...bar.openPanels }, { 'facet:color': true, price: true }, 'the drawer is an accordion');

    bar.onEscape();
    assert.equal(bar.drawerOpen, false);
    assert.equal(env.bodyClasses.has('overflow-hidden'), false);
    assert.equal(toggle.focused, true);

    bar.openDrawer();
    env.desktop = true;
    env.viewportListeners.forEach(listener => listener());
    assert.equal(bar.drawerOpen, false);
    assert.equal(env.bodyClasses.has('overflow-hidden'), false);
    assert.equal(bar.drawerInert(), false);
    bar.togglePanel('facet:color');
    bar.togglePanel('price');
    assert.deepEqual({ ...bar.openPanels }, { price: true }, 'desktop opens one popover at a time');
});

test('sorting and page size send the chosen value as events', () => {
    const env = createEnvironment();
    const sorting = env.component('initTypesenseSorting');
    assert.equal(sorting.currentSortLabel(), 'Relevance');

    sorting.sortOption = sorting.sortOptions()[1];
    sorting.selectSortOption();
    assert.deepEqual([env.lastEvent().type, { ...env.lastEvent().detail }], ['typesense-sort-change', { sort: 'price:asc' }]);
    const count = env.events.length;
    sorting.sortOption = sorting.sortOptions()[0];
    sorting.selectSortOption();
    assert.equal(env.events.length, count, 'choosing the current sort sends nothing');

    const pageSize = env.component('initTypesensePageSize');
    assert.deepEqual([...pageSize.limitOptions()], [12, 24, 36]);
    pageSize.limitOption = 36;
    assert.equal(pageSize.isLimitSelected(), true);
    pageSize.$event = { target: { value: '12' } };
    pageSize.selectLimit();
    assert.deepEqual([env.lastEvent().type, { ...env.lastEvent().detail }], ['typesense-limit-change', { limit: 12 }]);
});

test('the sort listbox closes when focus leaves it, not when it moves inside or on a plain click', () => {
    const env = createEnvironment();
    const sorting = env.component('initTypesenseSorting');
    const inside = {};
    const wrapper = { contains: element => element === inside };
    sorting.isOpen = true;

    sorting.$event = { currentTarget: wrapper, relatedTarget: inside };
    sorting.onSortFocusOut();
    assert.equal(sorting.isOpen, true);
    sorting.$event = { currentTarget: wrapper, relatedTarget: null };
    sorting.onSortFocusOut();
    assert.equal(sorting.isOpen, true);
    sorting.$event = { currentTarget: wrapper, relatedTarget: {} };
    sorting.onSortFocusOut();
    assert.equal(sorting.isOpen, false);
});

test('a native sort select dispatches only a changed value', () => {
    const env = createEnvironment();
    const sorting = env.component('initTypesenseSorting');

    sorting.$event = { target: { value: '' } };
    sorting.selectSort();
    assert.equal(env.events.length, 0, 'the current sort sends nothing');

    sorting.$event = { target: { value: 'price:asc' } };
    sorting.selectSort();
    assert.deepEqual([env.lastEvent().type, { ...env.lastEvent().detail }], ['typesense-sort-change', { sort: 'price:asc' }]);
});

test('the price range text is the chip value without the label', () => {
    const env = createEnvironment();
    const chips = env.component('initTypesenseActiveFilters');
    chips.$event = { detail: snapshot({ state: Object.assign(snapshot().state, { price: { min: 10, max: 50 } }) }) };
    chips.onListingState();

    assert.equal(chips.priceRangeText(), 'EUR 10 – EUR 50');
    assert.equal(chips.priceChipText(), 'Price: EUR 10 – EUR 50');
});

test('the filter content is always visible on desktop and toggles below md', () => {
    const env = createEnvironment();
    const desktop = env.component('initTypesenseFilterBar');
    assert.equal(desktop.filtersExpanded, false);
    assert.equal(desktop.isFilterContentVisible(), true);

    env.desktop = false;
    const mobile = env.component('initTypesenseFilterBar');
    assert.equal(mobile.isFilterContentVisible(), false);
    mobile.toggleFiltersExpanded();
    assert.equal(mobile.isFilterContentVisible(), true);
    mobile.toggleFiltersExpanded();
    assert.equal(mobile.isFilterContentVisible(), false);
});
