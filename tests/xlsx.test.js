// Testa o gerador de .xlsx: node tests/xlsx.test.js
'use strict';
const assert = require('node:assert/strict');
const zlib = require('node:zlib');
const { buildBytes, columnName } = require('../public/assets/js/xlsx.js');

/** Lê o diretório central do ZIP e devolve { nome: { texto, crcOk } }. */
function readZip(bytes) {
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const eocd = bytes.length - 22;
    assert.equal(view.getUint32(eocd, true), 0x06054b50, 'assinatura EOCD');
    const count = view.getUint16(eocd + 10, true);
    let at = view.getUint32(eocd + 16, true);
    const entries = {};
    for (let i = 0; i < count; i++) {
        assert.equal(view.getUint32(at, true), 0x02014b50, 'assinatura do diretório central');
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

test('contém as partes obrigatórias do pacote', () => {
    for (const parte of ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/worksheets/sheet1.xml']) {
        assert.ok(zip[parte], `faltou ${parte}`);
    }
});
test('CRC de todos os arquivos confere', () => {
    for (const [nome, e] of Object.entries(zip)) assert.ok(e.crcOk, `CRC inválido em ${nome}`);
});
test('números viram células numéricas; texto vira inlineStr', () => {
    assert.match(sheet, /<c r="A2"><v>1<\/v><\/c>/);
    assert.match(sheet, /<c r="C2"><v>10\.5<\/v><\/c>/);
});
test('decimal com risco de perda de precisão e zeros à esquerda ficam texto', () => {
    assert.match(sheet, /<c r="C3" t="inlineStr"><is><t xml:space="preserve">99999999999999999\.99<\/t>/);
    assert.match(sheet, /<c r="D2" t="inlineStr"><is><t xml:space="preserve">00123<\/t>/);
});
test('escapa XML e remove caracteres de controle', () => {
    assert.match(sheet, /A &amp; B &lt;x&gt;/);
    assert.match(sheet, />yz</);
});
test('NULL não gera célula', () => assert.doesNotMatch(sheet, /r="B3"/));
test('cabeçalho em negrito, linha congelada e filtro', () => {
    assert.match(sheet, /<c r="A1" t="inlineStr" s="1">/);
    assert.match(sheet, /state="frozen"/);
    assert.match(sheet, /<autoFilter ref="A1:D1"\/>/);
});
test('trunca célula no limite do Excel (32767)', () => {
    const grande = readZip(buildBytes(['a'], ['varchar'], [['x'.repeat(40000)]]))['xl/worksheets/sheet1.xml'].texto;
    assert.equal((grande.match(/x+/g) || []).sort((a, b) => b.length - a.length)[0].length, 32767);
});
test('nomes de coluna A..Z, AA..', () => {
    assert.deepEqual([0, 25, 26, 51, 52, 701, 702].map(columnName), ['A', 'Z', 'AA', 'AZ', 'BA', 'ZZ', 'AAA']);
});
test('resultado vazio gera planilha válida só com cabeçalho', () => {
    const vazio = readZip(buildBytes(['a', 'b'], ['int', 'int'], []));
    assert.match(vazio['xl/worksheets/sheet1.xml'].texto, /<row r="1">/);
});

console.log(`OK: ${total} testes do gerador de .xlsx passaram.`);
