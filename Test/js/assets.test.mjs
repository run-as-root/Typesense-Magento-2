// The listing core is a shared asset: exactly one template includes it (js/listing-core.phtml in default.xml).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';

const viewRoot = fileURLToPath(new URL('../../view/frontend/', import.meta.url));

function files(dir) {
    return readdirSync(dir, { withFileTypes: true }).flatMap(entry => (entry.isDirectory()
        ? files(join(dir, entry.name))
        : [join(dir, entry.name)]));
}

test('listing-core.js is included by a single template', () => {
    const includes = files(viewRoot + 'templates')
        .filter(file => readFileSync(file, 'utf8').includes('js/listing-core.js'))
        .map(file => file.slice(viewRoot.length));

    assert.deepEqual(includes, ['templates/js/listing-core.phtml']);
});
