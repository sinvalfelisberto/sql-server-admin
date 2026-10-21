/**
 * Minimal .xlsx (Office Open XML) generator with no dependencies.
 * An .xlsx is a ZIP of XML files; here the ZIP uses the "stored" method (no compression).
 *
 * Browser: XlsxWriter.build(columns, types, rows) -> Blob
 * Node (tests): require('./xlsx.js').buildBytes(...) -> Uint8Array
 */
(function (root) {
    'use strict';

    const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const XML_HEAD = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n';
    const MAX_CELL_CHARS = 32767; // text limit per cell in Excel
    const NUMERIC_TYPE = /int|decimal|numeric|float|real|money/i;
    const DECIMAL_TYPE = /^(decimal|numeric|money|smallmoney)$/i;
    const encoder = new TextEncoder();

    // ---------- ZIP (stored) ----------
    const CRC_TABLE = (() => {
        const table = new Uint32Array(256);
        for (let n = 0; n < 256; n++) {
            let c = n;
            for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
            table[n] = c >>> 0;
        }
        return table;
    })();

    function crc32(bytes) {
        let c = 0xFFFFFFFF;
        for (let i = 0; i < bytes.length; i++) c = CRC_TABLE[(c ^ bytes[i]) & 0xFF] ^ (c >>> 8);
        return (c ^ 0xFFFFFFFF) >>> 0;
    }

    /** @param {{name: string, data: Uint8Array}[]} files @returns {Uint8Array[]} ZIP parts */
    function zipParts(files) {
        const now = new Date();
        const dosTime = (now.getHours() << 11) | (now.getMinutes() << 5) | (now.getSeconds() >> 1);
        const dosDate = ((now.getFullYear() - 1980) << 9) | ((now.getMonth() + 1) << 5) | now.getDate();
        const body = [];
        const central = [];
        let offset = 0;

        for (const file of files) {
            const name = encoder.encode(file.name);
            const crc = crc32(file.data);
            const size = file.data.length;

            const local = new DataView(new ArrayBuffer(30));
            local.setUint32(0, 0x04034b50, true);
            local.setUint16(4, 20, true);       // minimum version
            local.setUint16(6, 0x0800, true);   // UTF-8 names
            local.setUint16(8, 0, true);        // no compression
            local.setUint16(10, dosTime, true);
            local.setUint16(12, dosDate, true);
            local.setUint32(14, crc, true);
            local.setUint32(18, size, true);
            local.setUint32(22, size, true);
            local.setUint16(26, name.length, true);
            body.push(new Uint8Array(local.buffer), name, file.data);

            const entry = new DataView(new ArrayBuffer(46));
            entry.setUint32(0, 0x02014b50, true);
            entry.setUint16(4, 20, true);
            entry.setUint16(6, 20, true);
            entry.setUint16(8, 0x0800, true);
            entry.setUint16(12, dosTime, true);
            entry.setUint16(14, dosDate, true);
            entry.setUint32(16, crc, true);
            entry.setUint32(20, size, true);
            entry.setUint32(24, size, true);
            entry.setUint16(28, name.length, true);
            entry.setUint32(42, offset, true);
            central.push(new Uint8Array(entry.buffer), name);

            offset += 30 + name.length + size;
        }

        const centralSize = central.reduce((n, part) => n + part.length, 0);
        const end = new DataView(new ArrayBuffer(22));
        end.setUint32(0, 0x06054b50, true);
        end.setUint16(8, files.length, true);
        end.setUint16(10, files.length, true);
        end.setUint32(12, centralSize, true);
        end.setUint32(16, offset, true);

        return [...body, ...central, new Uint8Array(end.buffer)];
    }

    // ---------- Worksheet ----------
    const escapeXml = (s) => s
        .replace(/[\x00-\x08\x0B\x0C\x0E-\x1F￾￿]/g, '') // characters forbidden in XML 1.0
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

    /** 0 -> A, 25 -> Z, 26 -> AA ... */
    function columnName(index) {
        let n = index + 1;
        let name = '';
        while (n > 0) {
            name = String.fromCharCode(65 + ((n - 1) % 26)) + name;
            n = Math.floor((n - 1) / 26);
        }
        return name;
    }

    /** A JS number when it is safe (no precision loss); otherwise null and the cell becomes text. */
    function asNumber(value, type) {
        if (typeof value === 'number') return Number.isFinite(value) ? value : null;
        if (typeof value === 'string' && NUMERIC_TYPE.test(type) && /^-?\d+(\.\d+)?$/.test(value) && value.replace(/\D/g, '').length <= 15) {
            return Number(value);
        }
        return null;
    }

    function textCell(ref, text, style = '') {
        const clipped = text.length > MAX_CELL_CHARS ? text.slice(0, MAX_CELL_CHARS) : text;
        return `<c r="${ref}" t="inlineStr"${style}><is><t xml:space="preserve">${escapeXml(clipped)}</t></is></c>`;
    }

    function cellXml(value, type, ref) {
        if (value === null || value === undefined) return '';
        const number = asNumber(value, type);
        // Exact decimals already formatted with two places ("30000.00") get Excel's 0.00 format.
        const style = typeof value === 'string' && DECIMAL_TYPE.test(type) && /\.\d\d$/.test(value) ? ' s="2"' : '';
        return number !== null ? `<c r="${ref}"${style}><v>${number}</v></c>` : textCell(ref, String(value));
    }

    function columnWidths(columns, rows) {
        return columns.map((name, c) => {
            const longest = rows.slice(0, 200).reduce((max, r) => Math.max(max, String(r[c] ?? '').length), String(name).length);
            return Math.min(60, Math.max(8, Math.round(longest * 1.1 + 2)));
        });
    }

    function sheetXml(columns, types, rows) {
        const lastCol = columnName(Math.max(columns.length, 1) - 1);
        const widths = columnWidths(columns, rows)
            .map((w, i) => `<col min="${i + 1}" max="${i + 1}" width="${w}" customWidth="1"/>`).join('');
        const header = columns.map((name, c) => textCell(`${columnName(c)}1`, String(name), ' s="1"')).join('');
        const body = rows.map((row, r) => {
            const cells = row.map((value, c) => cellXml(value, types[c] ?? '', `${columnName(c)}${r + 2}`)).join('');
            return `<row r="${r + 2}">${cells}</row>`;
        }).join('');

        return `${XML_HEAD}<worksheet xmlns="${NS}">`
            + '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            + (columns.length ? `<cols>${widths}</cols>` : '')
            + `<sheetData><row r="1">${header}</row>${body}</sheetData>`
            + `<autoFilter ref="A1:${lastCol}1"/>`
            + '</worksheet>';
    }

    const STATIC_FILES = {
        '[Content_Types].xml': `${XML_HEAD}<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">`
            + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            + '<Default Extension="xml" ContentType="application/xml"/>'
            + '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            + '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            + '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            + '</Types>',
        '_rels/.rels': `${XML_HEAD}<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">`
            + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            + '</Relationships>',
        'xl/workbook.xml': `${XML_HEAD}<workbook xmlns="${NS}" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">`
            + '<sheets><sheet name="Result" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels': `${XML_HEAD}<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">`
            + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            + '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            + '</Relationships>',
        // style 0 = default, 1 = bold header, 2 = number with two decimal places (0.00)
        'xl/styles.xml': `${XML_HEAD}<styleSheet xmlns="${NS}">`
            + '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            + '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            + '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            + '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            + '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            + '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            + '<xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            + '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
    };

    function files(columns, types, rows) {
        return [...Object.entries(STATIC_FILES), ['xl/worksheets/sheet1.xml', sheetXml(columns, types, rows)]]
            .map(([name, xml]) => ({ name, data: encoder.encode(xml) }));
    }

    /** @param {string[]} columns @param {string[]} types SQL types per column @param {Array<Array>} rows */
    function build(columns, types, rows) {
        return new Blob(zipParts(files(columns, types, rows)), { type: MIME });
    }

    /** Same output as build(), as bytes (useful in tests). */
    function buildBytes(columns, types, rows) {
        const parts = zipParts(files(columns, types, rows));
        const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
        let at = 0;
        for (const p of parts) { out.set(p, at); at += p.length; }
        return out;
    }

    const api = { build, buildBytes, MIME, columnName };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.XlsxWriter = api;
}(typeof self !== 'undefined' ? self : this));
