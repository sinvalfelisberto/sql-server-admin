// Tests the .xlsx generator: node tests/xlsx.test.js
'use strict';
const assert = require('node:assert/strict');
const zlib = require('node:zlib');
const { buildBytes, columnName } = require('../public/assets/js/xlsx.js');

/** Reads the ZIP central directory and returns { name: { text, crcOk } }. */
function readZip(bytes) {
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const eocd = bytes.length - 22;
    assert.equal(view.getUint32(eocd, true), 0x06054b50, 'EOCD signature');
    const count = view.getUint16(eocd + 10, true);
    let at = view.getUint32(eocd + 16, true);
    const entries = {};
    for (let i = 0; i < count; i++) {
        assert.equal(view.getUint32(at, true), 0x02014b50, 'central directory signature');
        const method = view.getUint16(at + 10, true);
        const crc = view.getUint32(at + 16, true);
        const size = view.getUint32(at + 20, true);
        const nameLen = view.getUint16(at + 28, true);
        const local = view.getUint32(at + 42, true);
        const name = Buffer.from(bytes.subarray(at + 46, at + 46 + nameLen)).toString('utf8');
        const dataAt = local + 30 + view.getUint16(local + 26, true) + view.getUint16(local + 28, true);
        const data = Buffer.from(bytes.subarray(dataAt, dataAt + size));
        entries[name] = { texto: data.toString('utf8'), crcOk: method === 0 && zlib.crc32(data) === crc };
        at += 46 + nameLen;
    }
    return entries;
}

let total = 0;
const test = (nome, fn) => { fn(); total++; };

const zip = readZip(buildBytes(
    ['id', 'nome', 'saldo', 'código'],
    ['int', 'nvarchar', 'decimal', 'varchar'],
    [[1, 'A & B <x>', '10.50', '00123'], [2, null, '99999999999999999.99', 'y\u0001z']],
));
const sheet = zip['xl/worksheets/sheet1.xml'].texto;

test('contains the required package parts', () => {
    for (const parte of ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml']) {
        assert.ok(zip[parte], `faltou ${parte}`);
    }
});
test('CRC of every file matches', () => {
    for (const [nome, e] of Object.entries(zip)) assert.ok(e.crcOk, `Invalid CRC in ${nome}`);
});
test('numbers become numeric cells; text becomes inlineStr', () => {
    assert.match(sheet, /<c r="A2"><v>1<\/v><\/c>/);
    assert.match(sheet, /<c r="C2" s="2"><v>10\.5<\/v><\/c>/); // decimal with 2 places: 0.00 format
});

test('a decimal with 2 places uses the 0.00 style; more places, integers and other types do not', () => {
    const d = readZip(buildBytes(
        ['a', 'b', 'c', 'd'],
        ['money', 'decimal', 'int', 'float'],
        [['30000.00', '12.3456', 7, '1.50']],
    ));
    const xml = d['xl/worksheets/sheet1.xml'].texto;
    assert.match(xml, /<c r="A2" s="2"><v>30000<\/v><\/c>/);
    assert.match(xml, /<c r="B2"><v>12\.3456<\/v><\/c>/);
    assert.match(xml, /<c r="C2"><v>7<\/v><\/c>/);
    assert.match(xml, /<c r="D2"><v>1\.5<\/v><\/c>/);
    assert.match(d['xl/styles.xml'].texto, /<cellXfs count="3">[\s\S]*numFmtId="2"/);
});
test('a decimal at risk of precision loss and leading zeros stay text', () => {
    assert.match(sheet, /<c r="C3" t="inlineStr"><is><t xml:space="preserve">99999999999999999\.99<\/t>/);
    assert.match(sheet, /<c r="D2" t="inlineStr"><is><t xml:space="preserve">00123<\/t>/);
});
test('escapes XML and removes control characters', () => {
    assert.match(sheet, /A &amp; B &lt;x&gt;/);
    assert.match(sheet, />yz</);
});
test('NULL generates no cell', () => assert.doesNotMatch(sheet, /r="B3"/));
test('bold header, frozen row and filter', () => {
    assert.match(sheet, /<c r="A1" t="inlineStr" s="1">/);
    assert.match(sheet, /state="frozen"/);
    assert.match(sheet, /<autoFilter ref="A1:D1"\/>/);
});
test('truncates a cell at the Excel limit (32767)', () => {
    const grande = readZip(buildBytes(['a'], ['varchar'], [['x'.repeat(40000)]]))['xl/worksheets/sheet1.xml'].texto;
    assert.equal((grande.match(/x+/g) || []).sort((a, b) => b.length - a.length)[0].length, 32767);
});
test('column names A..Z, AA..', () => {
    assert.deepEqual([0, 25, 26, 51, 52, 701, 702].map(columnName), ['A', 'Z', 'AA', 'AZ', 'BA', 'ZZ', 'AAA']);
});
test('an empty result generates a valid sheet with only a header', () => {
    const vazio = readZip(buildBytes(['a', 'b'], ['int', 'int'], []));
    assert.match(vazio['xl/worksheets/sheet1.xml'].texto, /<row r="1">/);
});

console.log(`OK: ${total} .xlsx generator tests passed.`);
