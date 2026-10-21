/**
 * Reads CSV and JSON files for the data import: everything runs in the browser.
 *
 * Browser: ImportData.tableFromCsv(...), ImportData.tableFromJson(...)
 * Node (tests): require('./import.js')
 *
 * A "parsed table" is { columns: string[], rows: Array<Array<string|null>> }; every cell is
 * text (or null), and SQL Server converts it to the column type on insert.
 */
(function (root) {
    'use strict';

    const SEPARATORS = [';', ',', '\t', '|'];
    const MAX_ROWS = 1000000;

    /** Picks the most frequent separator on the first line (outside quotes). With no hint, a comma. */
    function detectSeparator(text) {
        const counts = Object.fromEntries(SEPARATORS.map((s) => [s, 0]));
        let quoted = false;
        for (let i = 0; i < text.length; i++) {
            const c = text[i];
            if (c === '"') quoted = !quoted;
            else if (!quoted && (c === '\n' || c === '\r')) break;
            else if (!quoted && c in counts) counts[c]++;
        }
        const best = SEPARATORS.reduce((a, b) => (counts[b] > counts[a] ? b : a), ',');
        return counts[best] > 0 ? best : ',';
    }

    /** CSV (RFC 4180): quotes, doubled quotes, line breaks inside cells, CRLF/LF/CR. */
    function parseCsv(text, separator) {
        const rows = [];
        let row = [];
        let cell = '';
        let quoted = false;
        let started = false; // is there content on the current line?

        const endCell = () => { row.push(cell); cell = ''; };
        const endRow = () => {
            endCell();
            if (row.length > 1 || row[0] !== '') rows.push(row); // ignore completely empty lines
            row = [];
            started = false;
        };

        const body = text.charCodeAt(0) === 0xFEFF ? text.slice(1) : text;
        for (let i = 0; i < body.length; i++) {
            const c = body[i];
            if (quoted) {
                if (c === '"') {
                    if (body[i + 1] === '"') { cell += '"'; i++; } else quoted = false;
                } else cell += c;
            } else if (c === '"' && cell === '') {
                quoted = true;
                started = true;
            } else if (c === separator) {
                endCell();
                started = true;
            } else if (c === '\n' || c === '\r') {
                if (c === '\r' && body[i + 1] === '\n') i++;
                endRow();
            } else {
                cell += c;
                started = true;
            }
            if (rows.length > MAX_ROWS + 1) throw new Error(`The file has more than ${MAX_ROWS.toLocaleString('en-US')} rows.`);
        }
        if (started || cell !== '' || row.length) endRow();
        return rows;
    }

    /** Empty names become column_N and repeated ones get a _2, _3 suffix (case-insensitive). */
    function uniqueNames(names) {
        const seen = new Map();
        return names.map((raw, i) => {
            const base = String(raw ?? '').trim() || `column_${i + 1}`;
            const key = base.toLowerCase();
            const n = (seen.get(key) ?? 0) + 1;
            seen.set(key, n);
            return n > 1 ? `${base}_${n}` : base;
        });
    }

    /** @param {{separator?: string, header?: boolean}} opts separator absent = detect */
    function tableFromCsv(text, { separator, header = true } = {}) {
        const sep = separator || detectSeparator(text);
        const all = parseCsv(text, sep);
        if (!all.length) throw new Error('The file has no rows.');

        const width = Math.max(...all.map((r) => r.length));
        const names = header ? all[0] : [];
        const columns = uniqueNames(Array.from({ length: width }, (_, i) => names[i]));
        const rows = (header ? all.slice(1) : all).map((r) => Array.from({ length: width }, (_, i) => r[i] ?? null));
        return { columns, rows, separator: sep };
    }

    const cellText = (v) => {
        if (v === null || v === undefined) return null;
        if (typeof v === 'object') return JSON.stringify(v);
        return String(v);
    };

    /** JSON: an array of objects. The columns are the union of the keys, in order of appearance. */
    function tableFromJson(text) {
        let data;
        try {
            data = JSON.parse(text.charCodeAt(0) === 0xFEFF ? text.slice(1) : text);
        } catch (e) {
            throw new Error(`Invalid JSON: ${e.message}`);
        }
        if (!Array.isArray(data)) throw new Error('Expected a JSON array of objects, for example [{"id": 1, "name": "A"}].');
        if (!data.length) throw new Error('The JSON array is empty.');
        if (data.length > MAX_ROWS) throw new Error(`The file has more than ${MAX_ROWS.toLocaleString('en-US')} rows.`);
        if (data.some((r) => r === null || typeof r !== 'object' || Array.isArray(r))) {
            throw new Error('Every item in the JSON array must be an object.');
        }

        const columns = [];
        const known = new Set();
        for (const r of data) {
            for (const k of Object.keys(r)) {
                if (!known.has(k)) { known.add(k); columns.push(k); }
            }
        }
        const rows = data.map((r) => columns.map((k) => cellText(r[k])));
        return { columns, rows };
    }

    /** Empty becomes null when requested; everything else passes through untouched. */
    const normalizeCell = (v, emptyAsNull) => (v === null || (emptyAsNull && v === '') ? null : v);

    const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;
    const ISO_DATETIME = /^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,7})?)?$/;

    /** Suggested SQL type for a new column, from its values (nulls and empty values do not count). */
    function inferType(values) {
        const v = values.filter((x) => x !== null && x !== '');
        if (!v.length) return 'nvarchar(255)';

        if (v.every((x) => /^(true|false)$/i.test(x))) return 'bit';
        if (v.every((x) => /^-?(0|[1-9]\d*)$/.test(x))) {
            return v.every((x) => x.replace('-', '').length <= 9) ? 'int'
                : v.every((x) => x.replace('-', '').length <= 18) ? 'bigint' : 'decimal(38,0)';
        }
        if (v.every((x) => /^-?(0|[1-9]\d*)\.\d+$/.test(x))) {
            const frac = Math.max(...v.map((x) => x.split('.')[1].length));
            const int = Math.max(...v.map((x) => x.replace('-', '').split('.')[0].length));
            if (frac <= 18 && int + frac <= 38) return `decimal(38,${Math.max(frac, 2)})`;
        }
        if (v.every((x) => ISO_DATE.test(x))) return 'date';
        if (v.every((x) => ISO_DATETIME.test(x))) return 'datetime2';

        const longest = Math.max(...v.map((x) => x.length));
        const size = [50, 100, 255, 1000, 4000].find((n) => longest <= n);
        return size ? `nvarchar(${size})` : 'nvarchar(max)';
    }

    /** Table name suggestion from the file name ("sales 2026.csv" -> "sales_2026"). */
    function tableNameFromFile(filename) {
        const base = String(filename).replace(/^.*[\\/]/, '').replace(/\.[^.]*$/, '');
        return base.replace(/[^\p{L}\p{N}_]+/gu, '_').replace(/^_+|_+$/g, '') || 'imported_data';
    }

    const api = { detectSeparator, parseCsv, tableFromCsv, tableFromJson, uniqueNames, normalizeCell, inferType, tableNameFromFile, MAX_ROWS };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else root.ImportData = api;
}(typeof self !== 'undefined' ? self : this));
