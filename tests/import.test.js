// Tests the CSV/JSON reading for the import: node tests/import.test.js
'use strict';
const assert = require('node:assert/strict');
const I = require('../public/assets/js/import.js');

let total = 0;
const test = (name, fn) => { fn(); total++; };

test('detects the separator', () => {
    assert.equal(I.detectSeparator('a;b;c\n1;2;3'), ';');
    assert.equal(I.detectSeparator('a,b,c\n1,2,3'), ',');
    assert.equal(I.detectSeparator('a\tb\tc'), '\t');
    assert.equal(I.detectSeparator('a|b|c'), '|');
    assert.equal(I.detectSeparator('"a;b",c\n1,2'), ',', 'ignores a separator inside quotes');
    assert.equal(I.detectSeparator('only one column'), ',');
});

test('CSV with quotes, doubled quotes and line breaks inside a cell', () => {
    const rows = I.parseCsv('a,b\r\n"x,1","he said ""hi"""\n"l1\nl2",z\n', ',');
    assert.deepEqual(rows, [['a', 'b'], ['x,1', 'he said "hi"'], ['l1\nl2', 'z']]);
});

test('CSV: BOM, empty lines and a lone CR', () => {
    assert.deepEqual(I.parseCsv('\uFEFFa;b\r\r1;2\n\n3;4', ';'), [['a', 'b'], ['1', '2'], ['3', '4']]);
});

test('CSV: empty cell and a last line with no line break', () => {
    assert.deepEqual(I.parseCsv('a,b,c\n1,,3', ','), [['a', 'b', 'c'], ['1', '', '3']]);
    assert.deepEqual(I.parseCsv('a,b\n,', ','), [['a', 'b'], ['', '']]);
});

test('tableFromCsv: header, unique names and ragged rows', () => {
    const t = I.tableFromCsv('id;nome;;nome\n1;A;x\n2;B;y;z;extra');
    assert.deepEqual(t.columns, ['id', 'nome', 'column_3', 'nome_2', 'column_5']);
    assert.deepEqual(t.rows[0], ['1', 'A', 'x', null, null]);
    assert.deepEqual(t.rows[1], ['2', 'B', 'y', 'z', 'extra']);
    assert.equal(t.separator, ';');
});

test('tableFromCsv without a header row', () => {
    const t = I.tableFromCsv('1,2\n3,4', { header: false });
    assert.deepEqual(t.columns, ['column_1', 'column_2']);
    assert.equal(t.rows.length, 2);
});

test('tableFromCsv empty', () => assert.throws(() => I.tableFromCsv('\n\n'), /no rows/));

test('JSON: union of keys, null, numbers, booleans and nested values', () => {
    const t = I.tableFromJson('[{"id":1,"nome":"A","ativo":true},{"id":2,"extra":{"x":1},"nome":null}]');
    assert.deepEqual(t.columns, ['id', 'nome', 'ativo', 'extra']);
    assert.deepEqual(t.rows[0], ['1', 'A', 'true', null]);
    assert.deepEqual(t.rows[1], ['2', null, null, '{"x":1}']);
});

test('JSON invalid or in the wrong shape', () => {
    assert.throws(() => I.tableFromJson('{oops'), /Invalid JSON/);
    assert.throws(() => I.tableFromJson('{"a":1}'), /array of objects/);
    assert.throws(() => I.tableFromJson('[]'), /empty/);
    assert.throws(() => I.tableFromJson('[1,2]'), /must be an object/);
    assert.throws(() => I.tableFromJson('[[1],[2]]'), /must be an object/);
});

test('normalizeCell', () => {
    assert.equal(I.normalizeCell('', true), null);
    assert.equal(I.normalizeCell('', false), '');
    assert.equal(I.normalizeCell(null, false), null);
    assert.equal(I.normalizeCell('x', true), 'x');
});

test('inferType', () => {
    assert.equal(I.inferType(['1', '22', '-3', null, '']), 'int');
    assert.equal(I.inferType(['1', '1234567890']), 'bigint');
    assert.equal(I.inferType(['1.5', '20.125']), 'decimal(38,3)');
    assert.equal(I.inferType(['1.5', '2']), 'nvarchar(50)', 'a mix of decimal and integer becomes text');
    assert.equal(I.inferType(['true', 'False']), 'bit');
    assert.equal(I.inferType(['2026-10-09', '2025-01-02']), 'date');
    assert.equal(I.inferType(['2026-10-09 11:11:43.144', '2026-10-09T01:02']), 'datetime2');
    assert.equal(I.inferType(['2026-10-09T01:02:03Z']), 'nvarchar(50)', 'a time zone does not become datetime2');
    assert.equal(I.inferType(['00123', '4']), 'nvarchar(50)', 'leading zeros stay text');
    assert.equal(I.inferType(['x'.repeat(300)]), 'nvarchar(1000)');
    assert.equal(I.inferType(['x'.repeat(5000)]), 'nvarchar(max)');
    assert.equal(I.inferType([null, '']), 'nvarchar(255)');
});

test('tableNameFromFile', () => {
    assert.equal(I.tableNameFromFile('sales 2026.csv'), 'sales_2026');
    assert.equal(I.tableNameFromFile('C:\\data\\customers.json'), 'customers');
    assert.equal(I.tableNameFromFile('..csv'), 'imported_data');
});

console.log(`OK: ${total} CSV/JSON reading tests passed.`);
