// Run with: node --test tests/JavaScript/reservation_row_heights.test.mjs
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../assets/controllers/reservations_controller.js', import.meta.url), 'utf8');
const classStart = source.indexOf('export default class');
assert.notEqual(classStart, -1);

function roomTable(roomIds, { available = 600, week = false, scrollbar = 0 } = {}) {
    const rows = roomIds.map((id) => {
        const properties = new Map();
        const row = {
            dataset: { appartment: String(id) },
            style: {
                height: '',
                setProperty: (name, value) => properties.set(name, value),
                removeProperty: (name) => properties.delete(name),
                getPropertyValue: (name) => properties.get(name) || '',
            },
        };
        row.getBoundingClientRect = () => ({ height: parseFloat(row.style.height) || 40 });
        return row;
    });
    const container = {
        style: { maxHeight: `${available}px` },
        offsetHeight: 200,
        clientHeight: 200 - scrollbar,
    };
    const table = {
        closest: () => container,
        querySelectorAll: () => rows,
        querySelector: () => ({
            getBoundingClientRect: () => ({
                height: parseFloat(rows[0]?.style.getPropertyValue('--week-bar-height')) || 28,
            }),
        }),
        get offsetHeight() {
            return 60 + rows.reduce((sum, row) => sum + row.getBoundingClientRect().height, 0);
        },
    };
    // The measured DOM is stubbed; exercise the real controller without loading
    // browser-only imports such as Stimulus or adding a JS build toolchain.
    const ReservationsController = runInNewContext(`(${source.slice(classStart).replace('export default ', '')})`, {
        Controller: class {},
        document: {
            getElementById: () => table,
            body: { classList: { contains: () => week } },
        },
        getComputedStyle: () => ({ marginBottom: '0' }),
    });
    const controller = new ReservationsController();

    return {
        rows,
        stretch: () => controller.stretchRoomRows(),
        heights: () => rows.map((row) => row.getBoundingClientRect().height),
        resize: (height) => { container.style.maxHeight = `${height}px`; },
        setWeek: (enabled) => { week = enabled; },
    };
}

test('one room in normal view grows to at most twice its natural height', () => {
    const table = roomTable([1]);
    table.stretch();

    assert.deepEqual(table.heights(), [80]);
    assert.equal(table.rows[0].style.getPropertyValue('--week-bar-height'), '56px');
});

test('two visible rooms retain the double-height cap after filtering', () => {
    const table = roomTable([3, 9]);
    table.stretch();

    assert.deepEqual(table.heights(), [80, 80]);
});

test('three rooms use one third of the remaining space beyond the cap', () => {
    const table = roomTable([1, 2, 3]);
    table.stretch();

    assert.deepEqual(table.heights(), [113, 113, 113]);
});

test('four rooms use two thirds of the remaining space beyond the cap', () => {
    const table = roomTable([1, 2, 3, 4]);
    table.stretch();

    assert.deepEqual(table.heights(), [116, 116, 116, 116]);
});

test('five rooms fill the available height in normal view', () => {
    const table = roomTable([1, 2, 3, 4, 5]);
    table.stretch();

    assert.deepEqual(table.heights(), [107, 107, 107, 107, 107]);
});

test('five rooms continue to fill the height in a taller window', () => {
    const table = roomTable([1, 2, 3, 4, 5], { available: 1000 });
    table.stretch();

    assert.deepEqual(table.heights(), [187, 187, 187, 187, 187]);
});

test('normal view still uses available space when less than the cap is free', () => {
    const table = roomTable(Array.from({ length: 10 }, (_, i) => i));
    table.stretch();

    assert.deepEqual(table.heights(), Array(10).fill(53));
});

test('split rooms share the available growth across their reservation rows', () => {
    const table = roomTable([1, 1, 1, 2], { available: 260 });
    table.stretch();

    assert.deepEqual(table.heights(), [46, 46, 46, 59]);
});

test('multiple reservation rows still count as one room for the growth cap', () => {
    const table = roomTable([1, 1, 1, 2]);
    table.stretch();

    assert.deepEqual(table.heights(), [80, 80, 80, 80]);
});

test('week view continues to fill the available height without the cap', () => {
    const table = roomTable([1], { week: true });
    table.stretch();

    assert.deepEqual(table.heights(), [539]);
});

test('week view still distributes free space equally per room', () => {
    const table = roomTable([1, 1, 1, 2], { week: true });
    table.stretch();

    assert.deepEqual(table.heights(), [103, 103, 103, 229]);
});

test('a horizontal scrollbar is included in the available height', () => {
    const table = roomTable([1], { available: 125, scrollbar: 15 });
    table.stretch();

    assert.deepEqual(table.heights(), [49]);
});

test('resizing resets the rows before recalculating their height', () => {
    const table = roomTable([1, 2, 3]);
    table.stretch();
    table.stretch();
    assert.deepEqual(table.heights(), [113, 113, 113]);

    table.resize(220);
    table.stretch();
    assert.deepEqual(table.heights(), [53, 53, 53]);

    table.resize(160);
    table.stretch();
    assert.deepEqual(table.heights(), [40, 40, 40]);
    assert.equal(table.rows[0].style.getPropertyValue('--week-bar-height'), '');
});

test('switching back from week view restores the normal view cap', () => {
    const table = roomTable([1], { week: true });
    table.stretch();
    table.setWeek(false);
    table.stretch();

    assert.deepEqual(table.heights(), [80]);
});

test('an empty room list does not acquire row heights', () => {
    const table = roomTable([]);
    table.stretch();

    assert.deepEqual(table.heights(), []);
});
