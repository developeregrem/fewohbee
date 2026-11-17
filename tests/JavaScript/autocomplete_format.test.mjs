// Run with: node --test tests/JavaScript/autocomplete_format.test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../assets/controllers/autocomplete_controller.js', import.meta.url), 'utf8');
const classStart = source.indexOf('export default class');
assert.notEqual(classStart, -1);

const AutocompleteController = runInNewContext(`(${source.slice(classStart).replace('export default ', '')})`, {
    Controller: class {},
});
const controller = new AutocompleteController();
const detail = '{company}, {address}, {zip} {city}';

test('fills a suggestion template with the values of a result', () => {
    const item = { company: 'Muster GmbH', address: 'Hauptstraße 1', zip: '12345', city: 'Musterstadt' };
    assert.equal(controller.format(detail, item), 'Muster GmbH, Hauptstraße 1, 12345 Musterstadt');
});

test('drops the separators of empty values', () => {
    assert.equal(controller.format(detail, { company: null, address: 'Hauptstraße 1', zip: '12345', city: 'Musterstadt' }), 'Hauptstraße 1, 12345 Musterstadt');
    assert.equal(controller.format(detail, { company: 'Muster GmbH', address: null, zip: '12345', city: 'Musterstadt' }), 'Muster GmbH, 12345 Musterstadt');
    assert.equal(controller.format(detail, { company: 'Muster GmbH', address: null, zip: null, city: null }), 'Muster GmbH');
});

test('an address without any values gives an empty text', () => {
    assert.equal(controller.format(detail, { company: null, address: null, zip: null, city: null }), '');
});

test('without a template a result shows its label, its name or itself', () => {
    assert.equal(controller.format('', { label: 'Berlin' }), 'Berlin');
    assert.equal(controller.format('', { name: 'Berlin' }), 'Berlin');
    assert.equal(controller.format('', 'Berlin'), 'Berlin');
});
