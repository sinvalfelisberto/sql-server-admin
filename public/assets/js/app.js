(() => {
    'use strict';

    // ---------- Utilities ----------
    const $ = (id) => document.getElementById(id);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    const store = {
        get(key, fallback) {
            try {
                const v = localStorage.getItem('sqlwm.' + key);
                return v === null ? fallback : JSON.parse(v);
            } catch { return fallback; }
        },
        set(key, value) {
            try { localStorage.setItem('sqlwm.' + key, JSON.stringify(value)); } catch { /* storage quota full */ }
        },
    };

    const esc = (s) => String(s).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));

    function el(tag, props = {}, ...kids) {
        const n = document.createElement(tag);
        for (const [k, v] of Object.entries(props)) {
            if (v == null || v === false) continue;
            if (k === 'class') n.className = v;
            else if (k === 'text') n.textContent = v;
            else if (k.startsWith('on')) n.addEventListener(k.slice(2), v);
            else n.setAttribute(k, v === true ? '' : v);
        }
        for (const kid of kids.flat()) if (kid != null) n.append(kid);
        return n;
    }

    const ICONS = {
        chev: '<svg class="chev" viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>',
        db: '<svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>',
        folder: '<svg viewBox="0 0 24 24"><path d="M3 6h6l2 2h10v11H3z"/></svg>',
        table: '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M3 15h18M9 4v16"/></svg>',
        view: '<svg viewBox="0 0 24 24"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>',
        proc: '<svg viewBox="0 0 24 24"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg>',
        func: '<svg viewBox="0 0 24 24"><path d="M16 4h-2a3 3 0 0 0-3 3v10a3 3 0 0 1-3 3H6M7 11h8"/></svg>',
        col: '<svg viewBox="0 0 24 24"><path d="M8 3v18M16 3v18"/></svg>',
        key: '<svg viewBox="0 0 24 24"><circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/></svg>',
    };

    function icon(name, cls = '') {
        const s = el('span', { class: 'ico ' + cls });
        s.innerHTML = ICONS[name]; // static SVG defined above
        return s;
    }

    let toastTimer;
    function toast(msg) {
        const t = $('toast');
        t.textContent = msg;
        t.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => t.classList.remove('show'), 2200);
    }

    const base = document.querySelector('meta[name="base-url"]').content;

    async function api(action, data = {}) {
        const res = await fetch(`${base}/api/${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify(data),
        });
        if (res.status === 401) {
            location.href = `${base}/login`;
            throw new Error('Session expired.');
        }
        let body;
        try { body = await res.json(); } catch { throw new Error('Invalid server response (HTTP ' + res.status + ').'); }
        return body;
    }

    function download(name, mime, content) {
        const url = URL.createObjectURL(new Blob([content], { type: mime }));
        const a = el('a', { href: url, download: name });
        document.body.append(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    const ddMMyyyy = (d = new Date()) => [d.getDate(), d.getMonth() + 1].map((n) => String(n).padStart(2, '0')).join('') + d.getFullYear();

    /** Exported file name: schema.table_ddMMyyyy.ext (table = target of the first FROM; without a FROM it becomes "result"). */
    function exportName(sql, ext) {
        const text = (sql ?? '').replace(/--[^\n]*|\/\*[\s\S]*?\*\//g, ' ');
        const part = '(?:\\[[^\\]]+\\]|"[^"]+"|[\\w$#@]+)';
        const m = new RegExp(`\\bFROM\\s+(${part}(?:\\s*\\.\\s*${part}){0,3})`, 'i').exec(text);
        const base = m
            ? (() => {
                const ids = m[1].match(new RegExp(part, 'g')).map((i) => i.replace(/^[\["]|[\]"]$/g, ''));
                const table = ids.pop();
                return `${ids.pop() || 'dbo'}.${table}`;
            })()
            : 'result';
        return `${base.replace(/[\\/:*?"<>|\s]+/g, '_')}_${ddMMyyyy()}.${ext}`;
    }

    // ---------- Editor with syntax highlighting ----------
    const ta = $('editor');
    const hl = $('hl');
    const gutter = $('gutter');

    const KEYWORDS = new Set(`ADD ALL ALTER AND ANY AS ASC AUTHORIZATION BACKUP BEGIN BETWEEN BREAK BY CASCADE CASE CHECK CLOSE CLUSTERED COALESCE COLUMN COMMIT CONSTRAINT CONTINUE CREATE CROSS CURRENT CURSOR DATABASE DEALLOCATE DECLARE DEFAULT DELETE DESC DISTINCT DROP ELSE END EXCEPT EXEC EXECUTE EXISTS FETCH FOR FOREIGN FROM FULL FUNCTION GO GOTO GRANT GROUP HAVING IDENTITY IF IN INDEX INNER INSERT INTERSECT INTO IS JOIN KEY LEFT LIKE MERGE NEXT NOT NULL OF OFF ON OPEN OPTION OR ORDER OUTER OUTPUT OVER PERCENT PIVOT PRIMARY PRINT PROC PROCEDURE RAISERROR READ REFERENCES RETURN RETURNS REVOKE RIGHT ROLLBACK ROWCOUNT SCHEMA SELECT SET TABLE THEN TOP TRAN TRANSACTION TRIGGER TRUNCATE TRY CATCH UNION UNIQUE UNPIVOT UPDATE USE VALUES VIEW WAITFOR WHEN WHERE WHILE WITH`.split(/\s+/));

    const TOKEN = /(--[^\n]*|\/\*[\s\S]*?(?:\*\/|$))|('(?:[^']|'')*(?:'|$))|(\[[^\]\n]*\]?)|(\b\d+(?:\.\d+)?\b)|([A-Za-z_@#][\w@#$]*)|([\s\S])/g;

    function highlight(sql) {
        let out = '';
        for (const m of sql.matchAll(TOKEN)) {
            if (m[1] !== undefined) out += `<span class="tc">${esc(m[1])}</span>`;
            else if (m[2] !== undefined) out += `<span class="ts">${esc(m[2])}</span>`;
            else if (m[3] !== undefined) out += `<span class="ti">${esc(m[3])}</span>`;
            else if (m[4] !== undefined) out += `<span class="tn">${m[4]}</span>`;
            else if (m[5] !== undefined) out += KEYWORDS.has(m[5].toUpperCase()) ? `<span class="tk">${esc(m[5])}</span>` : esc(m[5]);
            else out += esc(m[6]);
        }
        // A trailing line break needs a character to occupy the line.
        return /\n$/.test(sql) ? out + ' ' : out;
    }

    let lastLines = 0;
    function refreshEditor() {
        hl.innerHTML = highlight(ta.value);
        const lines = ta.value.split('\n').length;
        if (lines !== lastLines) {
            gutter.textContent = Array.from({ length: lines }, (_, i) => i + 1).join('\n');
            lastLines = lines;
        }
        syncScroll();
    }

    function syncScroll() {
        hl.scrollTop = ta.scrollTop;
        hl.scrollLeft = ta.scrollLeft;
        gutter.scrollTop = ta.scrollTop;
    }

    function insertAtCursor(text) {
        ta.focus();
        ta.setRangeText(text, ta.selectionStart, ta.selectionEnd, 'end');
        ta.dispatchEvent(new Event('input'));
    }

    // ---------- Query tabs ----------
    let tabs = store.get('tabs', null);
    let activeId = store.get('activeTab', null);
    let nextId = 1;

    const TEMPLATE = '-- SQL Web Manager\n-- Press F5 to run\n\nSELECT @@VERSION AS [SQL Server Version];\n';

    if (!Array.isArray(tabs) || !tabs.length) tabs = [{ id: 1, title: 'Query 1', sql: TEMPLATE }];
    nextId = Math.max(...tabs.map((t) => t.id)) + 1;
    if (!tabs.some((t) => t.id === activeId)) activeId = tabs[0].id;

    const models = new Map(); // tab id -> results state
    const activeTab = () => tabs.find((t) => t.id === activeId);

    let saveTimer;
    function persistTabs() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            store.set('tabs', tabs);
            store.set('activeTab', activeId);
        }, 300);
    }

    function renderTabs() {
        const box = $('tabs');
        box.replaceChildren(...tabs.map((t) => el('div', {
            class: 'tab' + (t.id === activeId ? ' active' : ''),
            role: 'tab',
            onclick: () => switchTab(t.id),
        },
        el('span', { text: '</> ' + t.title }),
        el('span', {
            class: 'x', title: 'Close', text: '×',
            onclick: (e) => { e.stopPropagation(); closeTab(t.id); },
        }))));
    }

    function switchTab(id) {
        activeId = id;
        const tab = activeTab();
        ta.value = tab.sql;
        // Each tab remembers its database; only applied if it still exists in the list.
        if (tab.db && [...$('db-select').options].some((o) => o.value === tab.db)) setDb(tab.db);
        refreshEditor();
        renderTabs();
        renderResults();
        renderTree();
        persistTabs();
        ta.focus();
    }

    function addTab(sql = TEMPLATE, { title, db } = {}) {
        const id = nextId++;
        tabs.push({ id, title: title ?? 'Query ' + id, sql, db: db ?? $('db-select').value });
        switchTab(id);
    }

    function closeTab(id) {
        const idx = tabs.findIndex((t) => t.id === id);
        models.delete(id);
        // Closing the last tab opens a new blank one, as in SSMS.
        if (tabs.length === 1) { tabs.splice(idx, 1); addTab(); return; }
        tabs.splice(idx, 1);
        if (id === activeId) switchTab(tabs[Math.max(0, idx - 1)].id);
        else { renderTabs(); persistTabs(); }
    }

    // ---------- Execution ----------
    let running = false;

    function setRunning(on) {
        running = on;
        const btn = $('btn-run');
        btn.disabled = on;
        btn.lastChild.textContent = on ? ' Running…' : ' Run';
    }

    /** @param {{unlimited?: boolean}} opts unlimited ignores AUTO TOP (only the server cap applies) */
    async function run(sqlOverride, { unlimited = false } = {}) {
        if (running) return;
        const selected = ta.value.substring(ta.selectionStart, ta.selectionEnd).trim();
        const sql = (sqlOverride ?? (selected || ta.value)).trim();
        if (!sql) { toast('Type a query to run.'); return; }

        const tabId = activeId;
        const limit = !unlimited && $('chk-top').checked ? Math.max(1, parseInt($('inp-top').value, 10) || 1000) : 0;
        setRunning(true);

        const size = parseInt($('page-size').value, 10);
        const req = { sql, database: $('db-select').value, limit };
        let res;
        try {
            // "page" requests server-side pagination (only for a single SELECT; otherwise the whole result comes back).
            res = await api('execute', { ...req, page: 1, page_size: size });
        } catch (e) {
            res = { ok: false, error: { message: e.message }, sets: [], messages: [], total_rows: 0, elapsed_ms: 0 };
        }
        setRunning(false);
        addHistory(sql);

        models.set(tabId, {
            res,
            sql,
            req,
            setIdx: 0,
            sort: null,
            filter: '',
            page: 1,
            size,
            view: null,
        });
        if (tabId === activeId) {
            $('res-filter').value = '';
            showPane(!res.sets.length ? 'messages' : 'results');
            renderResults();
        }
    }

    /** Result paginated on the server: loads another page by repeating the query. */
    async function loadPage(m, page) {
        if (running) return;
        setRunning(true);
        try {
            const res = await api('execute', { ...m.req, page, page_size: m.size });
            if (!res.ok || !res.page) { toast(res.error ? res.error.message : 'Could not load the page.'); return; }
            Object.assign(m, { res, sort: null, view: null, page: 1 });
        } catch (e) {
            toast(e.message);
            return;
        } finally {
            setRunning(false);
        }
        renderResults();
    }

    // ---------- Results ----------
    const currentModel = () => models.get(activeId);
    const currentSet = (m) => m && m.res.sets[m.setIdx];

    function showPane(which) {
        const isRes = which === 'results';
        $('results-panel').hidden = !isRes;
        $('messages-panel').hidden = isRes;
        $('tab-results').classList.toggle('active', isRes);
        $('tab-messages').classList.toggle('active', !isRes);
    }

    const isNumeric = (v) => typeof v === 'number' || (typeof v === 'string' && /^-?\d+(\.\d+)?$/.test(v));

    function compareValues(a, b) {
        if (a === null || a === undefined) return b === null || b === undefined ? 0 : 1;
        if (b === null || b === undefined) return -1;
        if (isNumeric(a) && isNumeric(b)) return Number(a) - Number(b);
        return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
    }

    function computeView(m) {
        const set = currentSet(m);
        let rows = set.rows.map((r, i) => ({ r, i }));
        const f = m.filter.trim().toLowerCase();
        if (f) rows = rows.filter(({ r }) => r.some((v) => v !== null && String(v).toLowerCase().includes(f)));
        if (m.sort) {
            const { col, dir } = m.sort;
            rows.sort((x, y) => dir * compareValues(x.r[col], y.r[col]));
        }
        m.view = rows;
        return rows;
    }

    const fmt = (n) => Number(n).toLocaleString('en-US');

    function renderResults() {
        const m = currentModel();
        renderMessages(m);
        renderChips(m);

        const wrap = $('grid-wrap');
        const pager = $('pager');
        const set = currentSet(m);

        if (!set) {
            wrap.replaceChildren(el('div', {
                class: 'empty',
                text: m ? 'No result set returned.' : 'Run a query to see the results.',
            }));
            pager.replaceChildren();
            updateStatus(m);
            return;
        }

        const rows = m.view ?? computeView(m);
        const paged = m.res.page; // page already cut by the server
        const size = paged ? rows.length || 1 : m.size || rows.length || 1;
        const rowBase = paged ? (paged.page - 1) * paged.size : 0;
        const pages = Math.max(1, Math.ceil(rows.length / size));
        m.page = Math.min(m.page, pages);
        const slice = rows.slice((m.page - 1) * size, m.page * size);

        const numCols = set.columns.map((c) => /int|decimal|numeric|float|real|money/i.test(c.type));
        const decimalCols = set.columns.map((c) => DECIMAL_TYPE.test(c.type)); // shown in the SQL standard, as in the export
        const head = el('tr', {}, el('th', { class: 'rn', text: '#' }),
            set.columns.map((c, idx) => {
                const sorted = m.sort && m.sort.col === idx;
                return el('th', {
                    class: (sorted ? 'sorted' : '') + (numCols[idx] ? ' num' : ''),
                    title: c.type,
                    onclick: () => {
                        m.sort = sorted && m.sort.dir === -1 ? null : { col: idx, dir: sorted ? -1 : 1 };
                        m.view = null;
                        renderResults();
                    },
                }, c.name || '(No name)', el('span', { class: 'arrow', text: sorted ? (m.sort.dir === 1 ? '▲' : '▼') : '⇅' }));
            }));

        const frag = document.createDocumentFragment();
        for (const { r, i } of slice) {
            const tr = el('tr', {}, el('td', { class: 'rn', text: rowBase + i + 1 }));
            r.forEach((v, c) => {
                if (v === null) { tr.append(el('td', {}, el('span', { class: 'null', text: 'NULL' }))); return; }
                const shown = String(decimalCols[c] ? formatDecimal(v) : v);
                tr.append(el('td', { class: numCols[c] ? 'num' : '', title: shown, text: shown }));
            });
            frag.append(tr);
        }

        wrap.replaceChildren(el('table', { class: 'grid' }, el('thead', {}, head), el('tbody', {}, frag)));

        const from = rows.length ? (m.page - 1) * size + 1 : 0;
        const to = Math.min(m.page * size, rows.length);
        if (paged) {
            $('res-filter').title = 'Filters only the rows on this page';
            pager.replaceChildren(
                el('span', { text: rows.length
                    ? `Rows ${fmt(rowBase + 1)} to ${fmt(rowBase + rows.length)}${paged.hasMore ? ' (more available)' : ''}`
                        + (m.filter ? ` · filter: ${fmt(rows.length)} of ${fmt(set.rows.length)} on this page` : '')
                    : 'No rows on this page.',
                title: 'The query runs again for each page; sorting and filtering apply only to the current page.' }),
                el('span', { class: 'spacer' }),
                el('button', { text: '‹ Previous', disabled: paged.page <= 1 || running, onclick: () => loadPage(m, paged.page - 1) }),
                el('span', { text: `Page ${fmt(paged.page)}` }),
                el('button', { text: 'Next ›', disabled: !paged.hasMore || running, onclick: () => loadPage(m, paged.page + 1) }),
            );
        } else {
            $('res-filter').title = '';
            pager.replaceChildren(
                el('span', { text: `Showing ${fmt(from)} to ${fmt(to)} of ${fmt(rows.length)} records` + (m.filter ? ` (filtered from ${fmt(set.rows.length)})` : '') }),
                el('span', { class: 'spacer' }),
                el('button', { text: '‹ Previous', disabled: m.page <= 1, onclick: () => { m.page--; renderResults(); } }),
                el('span', { text: `${m.page} / ${pages}` }),
                el('button', { text: 'Next ›', disabled: m.page >= pages, onclick: () => { m.page++; renderResults(); } }),
            );
        }
        updateStatus(m);
    }

    function renderChips(m) {
        const box = $('setchips');
        if (!m || m.res.sets.length < 2) { box.replaceChildren(); return; }
        box.replaceChildren(...m.res.sets.map((s, i) => el('button', {
            class: 'chip' + (i === m.setIdx ? ' active' : ''),
            text: `Result ${i + 1} (${fmt(s.rows.length)})`,
            onclick: () => { m.setIdx = i; m.sort = null; m.view = null; m.page = 1; renderResults(); },
        })));
    }

    function renderMessages(m) {
        const box = $('messages-panel');
        if (!m) { box.replaceChildren(); return; }
        const lines = [];
        const { res } = m;
        res.messages.forEach((t) => lines.push(el('div', { class: 'msg', text: t })));
        res.sets.forEach((s, i) => {
            lines.push(el('div', { class: 'msg', text: res.page
                ? `Page ${fmt(res.page.page)}: ${fmt(s.rows.length)} row(s) (query paginated on the server, ${fmt(res.page.size)} per page).`
                : `(${fmt(s.rows.length)} row(s) returned${res.sets.length > 1 ? ' in result ' + (i + 1) : ''})` }));
            if (s.truncated) {
                lines.push(el('div', { class: 'msg err', text: `Result ${i + 1} limited to the first ${fmt(s.rows.length)} rows.` }));
            }
        });
        if (res.error) {
            const n = res.error.number ? `Msg ${res.error.number}: ` : '';
            lines.push(el('div', { class: 'msg err', text: n + res.error.message }));
        }
        lines.push(el('div', { class: 'msg ' + (res.ok ? 'ok' : 'err'), text: res.ok ? 'Execution completed.' : 'Execution failed.' }));
        lines.push(el('div', { class: 'msg time', text: `Total time: ${fmt(res.elapsed_ms)} ms` }));
        box.replaceChildren(...lines);
    }

    function updateStatus(m) {
        $('sb-db').textContent = $('db-select').value;
        $('sb-rows').textContent = m ? `▦ ${m.res.page ? `page ${fmt(m.res.page.page)} · ` : ''}${fmt(m.res.total_rows)} row(s)` : '';
        $('sb-time').textContent = m ? `◔ ${fmt(m.res.elapsed_ms)} ms` : '';
    }

    // ---------- Export ----------
    const DECIMAL_TYPE = /^(decimal|numeric|money|smallmoney)$/i;

    /** Decimal in the SQL standard: a dot and at least two decimal places ("30000.0000" -> "30000.00", ".0000" -> "0.00"). Mirrors PHP's format_decimal(). */
    function formatDecimal(value) {
        const m = /^(-?)(\d*)\.(\d+)$/.exec(String(value).trim());
        if (!m) return value;
        const frac = m[3].replace(/0+$/, '').padEnd(2, '0');
        const int = m[2].replace(/^0+/, '') || '0';
        return (int === '0' && /^0*$/.test(frac) ? '' : m[1]) + int + '.' + frac;
    }

    function exportRows() {
        const m = currentModel();
        const set = currentSet(m);
        if (!set) { toast('There are no results to export.'); return null; }
        const types = set.columns.map((c) => c.type);
        const decimal = types.map((t) => DECIMAL_TYPE.test(t));
        const rows = (m.view ?? computeView(m)).map(({ r }) => (decimal.some(Boolean)
            ? r.map((v, i) => (decimal[i] && typeof v === 'string' ? formatDecimal(v) : v))
            : r));
        return { columns: set.columns.map((c) => c.name), types, rows };
    }

    /** Result paginated on the server? Then the export re-runs the query and brings everything, not just the page. */
    const pagedModel = () => { const m = currentModel(); return m && m.res.page ? m : null; };

    let exportFrame;
    /** Streamed download: a form posted to a hidden iframe; the browser saves it directly, without building the file in memory. */
    function serverDownload(m, format) {
        if (!exportFrame) {
            exportFrame = el('iframe', { name: 'export-frame', hidden: true, title: 'Export' });
            exportFrame.addEventListener('load', () => {
                // Only fires when the server answered with a page (an error) instead of a file.
                let text = '';
                try { text = exportFrame.contentDocument.body.textContent.trim(); } catch { /* no access */ }
                try { toast(JSON.parse(text).error.message); } catch { /* normal download */ }
            });
            document.body.append(exportFrame);
        }
        const fields = {
            csrf, sql: m.req.sql, database: m.req.database, format, separator: csvSeparator(),
            filename: exportName(m.req.sql, format).replace(/\.[^.]+$/, ''),
        };
        const form = el('form', { method: 'post', action: `${base}/api/export`, target: 'export-frame', hidden: true },
            Object.entries(fields).map(([name, value]) => el('input', { type: 'hidden', name, value })));
        document.body.append(form);
        form.submit();
        form.remove();
        toast('Exporting the complete result: the download starts when the query finishes.');
    }

    async function serverXlsx(m) {
        toast('Building the Excel file with the complete result…');
        try {
            const data = await api('export', { sql: m.req.sql, database: m.req.database, format: 'raw', limit: 1048576 });
            if (data.ok === false) { toast(data.error.message); return; }
            if (data.rows.length > 1048575) { toast('Excel supports at most 1,048,575 rows; use CSV.'); return; }
            download(exportName(m.req.sql, 'xlsx'), XlsxWriter.MIME, XlsxWriter.build(data.columns, data.types, data.rows));
        } catch (e) {
            toast(`Export failed: ${e.message}`);
        }
    }

    /** CSV separator chosen on screen: ";", ",", "|" or "tab" (a tab character). */
    const csvSeparator = () => $('csv-sep').value;

    function toCsv({ columns, rows }) {
        const sep = csvSeparator() === 'tab' ? '\t' : csvSeparator();
        const cell = (v) => {
            if (v === null) return '';
            const s = String(v);
            return s.includes(sep) || /["\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
        };
        return '\uFEFF' + [columns.map(cell), ...rows.map((r) => r.map(cell))].map((l) => l.join(sep)).join('\r\n');
    }

    function toJson({ columns, rows }) {
        const seen = {};
        const keys = columns.map((c) => {
            seen[c] = (seen[c] || 0) + 1;
            return seen[c] > 1 ? `${c}_${seen[c]}` : c;
        });
        return JSON.stringify(rows.map((r) => Object.fromEntries(keys.map((k, i) => [k, r[i]]))), null, 2);
    }

    /** Synchronous copy via a textarea: works inside the user gesture, without permission and over http. */
    function legacyCopy(text) {
        const tmp = el('textarea', { style: 'position:fixed;opacity:0', readonly: true });
        tmp.value = text;
        document.body.append(tmp);
        tmp.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch { /* not supported */ }
        tmp.remove();
        return ok;
    }

    /** Copies to the clipboard; returns whether it worked. */
    async function copyText(text) {
        if (legacyCopy(text)) return true;
        try {
            // writeText can hang (permission not answered): wait at most 1 s.
            await Promise.race([
                navigator.clipboard.writeText(text),
                new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), 1000)),
            ]);
            return true;
        } catch {
            return false;
        }
    }

    // ---------- History and favorites ----------
    function addHistory(sql) {
        const list = store.get('history', []).filter((h) => h.sql !== sql);
        list.unshift({ sql, db: $('db-select').value, ts: Date.now() });
        store.set('history', list.slice(0, 50));
    }

    function openMenu(menu, build) {
        const wasHidden = menu.hidden;
        document.querySelectorAll('.menu').forEach((m) => { m.hidden = true; });
        if (wasHidden) { build(); menu.hidden = false; }
    }

    const oneLine = (s) => s.replace(/\s+/g, ' ').trim();

    function buildHistory() {
        const menu = $('menu-history');
        const list = store.get('history', []);
        if (!list.length) { menu.replaceChildren(el('div', { class: 'none', text: 'No queries run yet.' })); return; }
        menu.replaceChildren(
            ...list.map((h) => el('button', {
                class: 'item',
                title: h.sql,
                onclick: () => { menu.hidden = true; loadSql(h.sql); },
            }, el('span', { class: 'txt', text: oneLine(h.sql) }), el('span', { class: 'meta', text: new Date(h.ts).toLocaleString('en-US') }))),
            el('div', { class: 'sep' }),
            el('button', { class: 'item', text: 'Clear history', onclick: () => { store.set('history', []); menu.hidden = true; } }),
        );
    }

    function buildFavorites() {
        const menu = $('menu-fav');
        const favs = store.get('favorites', []);
        const items = favs.map((f, i) => el('button', {
            class: 'item',
            title: f.sql,
            onclick: () => { menu.hidden = true; loadSql(f.sql); },
        },
        el('span', { class: 'txt', text: f.name }),
        el('span', {
            class: 'del', title: 'Remove', text: '×',
            onclick: (e) => {
                e.stopPropagation();
                store.set('favorites', favs.filter((_, j) => j !== i));
                buildFavorites();
            },
        })));
        menu.replaceChildren(
            el('button', {
                class: 'item',
                text: '★ Add current query to favorites',
                onclick: () => {
                    const sql = ta.value.trim();
                    if (!sql) { toast('The editor is empty.'); return; }
                    const name = prompt('Favorite name:', oneLine(sql).slice(0, 40));
                    if (!name) return;
                    store.set('favorites', [...favs, { name, sql }]);
                    menu.hidden = true;
                    toast('Favorite saved.');
                },
            }),
            el('div', { class: 'sep' }),
            items.length ? items : el('div', { class: 'none', text: 'No favorites.' }),
        );
    }

    function loadSql(sql) {
        ta.value = sql;
        ta.dispatchEvent(new Event('input'));
        ta.focus();
    }

    // ---------- Object Explorer ----------
    const GROUPS = [
        { label: 'Tables', types: ['U'], icon: 'table', cls: '' },
        { label: 'Views', types: ['V'], icon: 'view', cls: 'view' },
        { label: 'Procedures', types: ['P'], icon: 'proc', cls: 'proc' },
        { label: 'Functions', types: ['FN', 'IF', 'TF'], icon: 'func', cls: 'func' },
    ];
    const TREE_LIMIT = 500;
    const PAD = { db: 8, group: 30, object: 52, child: 80 };

    let databases = [];              // [{ name, system }]
    const dbObjects = new Map();     // database -> list of objects | { error }
    const loadingDbs = new Set();
    const openDbs = new Set();
    const openGroups = new Set();    // `${db}|${group}`
    const expanded = new Set();      // `${db}|${schema}.${object}`
    const columnCache = new Map();

    const quoteId = (s) => '[' + s.replace(/\]/g, ']]') + ']';
    const colRef = (n) => (/^[A-Za-z_]\w*$/.test(n) ? n : quoteId(n));
    const info = (text, pad) => el('div', { class: 'node info', style: `padding-left:${pad}px`, text });

    function chevron() {
        const span = el('span');
        span.innerHTML = ICONS.chev;
        return span.firstChild;
    }

    /** Sets the context database (toolbar, status and queries) without touching the tree. */
    function setDb(db) {
        const sel = $('db-select');
        const tab = activeTab();
        if (tab && tab.db !== db) { tab.db = db; persistTabs(); }
        if (sel.value === db) return;
        sel.value = db;
        store.set('db', db);
        $('sb-db').textContent = db;
    }

    /** Selects the database, expands it and loads its objects on demand. */
    function selectDb(db) {
        setDb(db);
        openDbs.add(db);
        loadObjects(db);
        renderTree();
    }

    /** Loads the database's objects once; the caller is responsible for redrawing the tree. */
    async function loadObjects(db) {
        if (dbObjects.has(db) || loadingDbs.has(db)) return;
        loadingDbs.add(db);
        try {
            const res = await api('objects', { database: db });
            dbObjects.set(db, res.ok ? res.objects : { error: res.error.message });
        } catch (e) {
            dbObjects.set(db, { error: e.message });
        }
        loadingDbs.delete(db);
        renderTree();
    }

    function renderTree() {
        const f = $('obj-filter').value.trim().toLowerCase();
        const current = $('db-select').value;
        const matchesFilter = (o) => !f || `${o.schema}.${o.name}`.toLowerCase().includes(f);
        const out = [];

        for (const d of databases) {
            const loaded = dbObjects.get(d.name);
            const items = Array.isArray(loaded) ? loaded.filter(matchesFilter) : [];
            const nameMatches = !f || d.name.toLowerCase().includes(f);
            if (f && !nameMatches && !items.length) continue;

            const open = openDbs.has(d.name) || (f && items.length > 0);
            out.push(el('div', {
                class: 'node db' + (open ? ' open' : '') + (d.name === current ? ' selected' : ''),
                style: `padding-left:${PAD.db}px`,
                title: d.name,
                oncontextmenu: (e) => showCtx(e, databaseMenu(d.name)),
                onclick: () => {
                    if (openDbs.has(d.name) && d.name === current) { openDbs.delete(d.name); renderTree(); }
                    else selectDb(d.name);
                },
            }, chevron(), icon('db', 'dbicon'), el('span', { class: 'lbl', text: d.name }),
            d.system ? el('span', { class: 'badge', text: 'SYS' }) : null));

            if (open) out.push(...databaseChildren(d.name, loaded, items, f));
        }

        if (!out.length) out.push(info(databases.length ? 'No objects found.' : 'Loading databases…', PAD.db));
        $('tree').replaceChildren(...out);
    }

    function databaseChildren(db, loaded, items, f) {
        if (loaded === undefined) {
            loadObjects(db); // idempotent: covers databases opened after "Refresh"
            return [info('Loading objects…', PAD.group)];
        }
        if (!Array.isArray(loaded)) return [info('Error: ' + loaded.error, PAD.group)];

        const out = [];
        for (const g of GROUPS) {
            const groupItems = items.filter((o) => g.types.includes(o.type));
            if (f && !groupItems.length) continue;

            const key = `${db}|${g.label}`;
            const open = f ? true : openGroups.has(key);
            out.push(el('div', {
                class: 'node' + (open ? ' open' : ''),
                style: `padding-left:${PAD.group}px`,
                oncontextmenu: (e) => showCtx(e, [{ label: 'Refresh', run: () => refreshDatabase(db) }]),
                onclick: () => { openGroups.has(key) ? openGroups.delete(key) : openGroups.add(key); renderTree(); },
            }, chevron(), icon('folder', 'folder'), el('span', { class: 'lbl', text: g.label }),
            el('span', { class: 'count', text: `(${fmt(groupItems.length)})` })));

            if (!open) continue;
            groupItems.slice(0, TREE_LIMIT).forEach((o) => out.push(...objectNode(db, o, g)));
            if (groupItems.length > TREE_LIMIT) {
                out.push(info(`… ${fmt(groupItems.length - TREE_LIMIT)} more (refine the filter)`, PAD.object));
            }
        }
        return out;
    }

    function objectNode(db, o, g) {
        const key = `${db}|${o.schema}.${o.name}`;
        const open = expanded.has(key);
        const full = `${quoteId(o.schema)}.${quoteId(o.name)}`;
        const isTable = o.type === 'U' || o.type === 'V';

        const node = el('div', {
            class: 'node' + (open ? ' open' : ''),
            style: `padding-left:${PAD.object}px`,
            title: isTable ? 'Double-click: SELECT TOP · Right-click: more options' : 'Double-click: insert into editor · Right-click: more options',
            oncontextmenu: (e) => showCtx(e, objectMenu(db, o)),
            onclick: () => { open ? expanded.delete(key) : expanded.add(key); renderTree(); },
            ondblclick: () => {
                setDb(db);
                if (isTable) {
                    const top = $('chk-top').checked ? Math.max(1, parseInt($('inp-top').value, 10) || 1000) : 1000;
                    selectTopSql(db, o, top).then((sql) => { loadSql(sql); run(); });
                } else {
                    insertAtCursor(o.type === 'P' ? `EXEC ${full} ` : full);
                }
            },
        }, chevron(), icon(g.icon, g.cls), el('span', { class: 'lbl', text: `${o.schema}.${o.name}` }));

        const result = [node];
        if (!open) return result;

        const cached = columnCache.get(key);
        if (!cached) {
            result.push(info('Loading…', PAD.child));
            fetchColumns(key, db, o);
            return result;
        }
        cached.forEach((c) => result.push(el('div', {
            class: 'node col',
            style: `padding-left:${PAD.child}px`,
            title: 'Click to insert into the editor',
            onclick: () => insertAtCursor(colRef(c.name)),
        }, icon(c.pk ? 'key' : 'col', c.pk ? 'pk' : ''), el('span', { class: 'lbl', text: c.name }),
        el('span', { class: 'type', text: `${c.type}${c.nullable ? '' : ' not null'}` }))));
        if (!cached.length) result.push(info('No columns/parameters.', PAD.child));
        return result;
    }

    async function fetchColumns(key, db, o) {
        try {
            const res = await api('columns', { database: db, schema: o.schema, name: o.name, type: o.type });
            columnCache.set(key, res.ok ? res.columns : []);
        } catch {
            columnCache.set(key, []);
        }
        renderTree();
    }

    /** SELECT TOP n listing every column (as in SSMS) instead of *. With no known columns, it uses *. */
    async function selectTopSql(db, o, top) {
        const key = `${db}|${o.schema}.${o.name}`;
        let cols = columnCache.get(key);
        if (!cols) {
            try {
                const res = await api('columns', { database: db, schema: o.schema, name: o.name, type: o.type });
                cols = res.ok ? res.columns : [];
                if (res.ok) columnCache.set(key, cols);
            } catch {
                cols = [];
            }
        }
        const list = cols.length ? cols.map((c) => `    ${quoteId(c.name)}`).join(',\n') : '    *';
        return `SELECT TOP ${top}\n${list}\nFROM ${qualified(o.schema, o.name)}`;
    }

    // ---------- Context menu and Object Explorer actions ----------
    const LARGE_TABLE_ROWS = 100000;
    const qualified = (schema, name) => `${quoteId(schema)}.${quoteId(name)}`;
    const isTableLike = (o) => o.type === 'U' || o.type === 'V';

    const ctx = el('div', { class: 'menu ctx', role: 'menu', hidden: true });
    document.body.append(ctx);
    const hideCtx = () => { ctx.hidden = true; };

    /** Shows the menu next to the cursor; items are { label, run } or '-' (separator). */
    function showCtx(event, items) {
        event.preventDefault();
        event.stopPropagation();
        document.querySelectorAll('.menu').forEach((m) => { m.hidden = true; });
        ctx.replaceChildren(...items.map((item) => (item === '-'
            ? el('div', { class: 'sep', role: 'separator' })
            : el('button', { class: 'item', role: 'menuitem', text: item.label, onclick: () => { hideCtx(); item.run(); } }))));
        ctx.hidden = false;
        const box = ctx.getBoundingClientRect();
        ctx.style.left = `${Math.max(0, Math.min(event.clientX, window.innerWidth - box.width - 4))}px`;
        ctx.style.top = `${Math.max(0, Math.min(event.clientY, window.innerHeight - box.height - 4))}px`;
        ctx.querySelector('button')?.focus();
    }

    /** Reusable modal dialog. Resolves with the value of the clicked action, or null if closed. */
    function showDialog({ title, body, actions = [{ label: 'Close', value: null }], wide = false }) {
        const dlg = $('dialog');
        $('dialog-title').textContent = title;
        $('dialog-body').replaceChildren(...[body].flat());
        dlg.classList.toggle('wide', wide);
        return new Promise((resolve) => {
            let result = null;
            $('dialog-actions').replaceChildren(...actions.map((a) => el('button', {
                class: 'btn ' + (a.primary ? 'primary' : 'bordered'),
                text: a.label,
                onclick: () => { result = a.value; dlg.close(); },
            })));
            dlg.addEventListener('close', () => resolve(result), { once: true });
            dlg.showModal();
        });
    }

    /** Opens a new tab on the given database, optionally running it right away. */
    function openQuery(db, sql, { title, execute = false, unlimited = false } = {}) {
        addTab(sql, { title, db });
        if (execute) run(undefined, { unlimited });
    }

    const selectTop = async (db, o) => openQuery(db, await selectTopSql(db, o, 100), { execute: true });
    const newQuery = (db) => openQuery(db, `USE ${quoteId(db)};\nGO\n\n`);

    /** SELECT * without TOP: asks for confirmation before running if the table is large. */
    async function selectAll(db, o) {
        let proceed = true;
        if (o.type === 'U') {
            try {
                const res = await api('table-info', { database: db, schema: o.schema, name: o.name });
                if (res.ok && res.rows !== null && res.rows > LARGE_TABLE_ROWS) {
                    proceed = await showDialog({
                        title: 'Large table',
                        body: el('p', { text: `${o.schema}.${o.name} has about ${fmt(res.rows)} rows. Fetching everything can overload the browser and the server (the server still caps the result at the configured limit).` }),
                        actions: [{ label: 'Cancel', value: false }, { label: 'Run anyway', primary: true, value: true }],
                    }) === true;
                }
            } catch { /* no estimate: proceed without a warning */ }
        }
        openQuery(db, `SELECT * FROM ${qualified(o.schema, o.name)}`, { execute: proceed, unlimited: true });
        if (!proceed) toast('Query generated but not run.');
    }

    async function showDdl(db, o) {
        toast('Generating DDL…');
        try {
            const res = await api('ddl', { database: db, schema: o.schema, name: o.name, type: o.type });
            if (!res.ok) throw new Error(res.error.message);
            openQuery(db, res.ddl, { title: `DDL ${o.name}` });
        } catch (e) {
            toast('Error: ' + e.message);
        }
    }

    async function showDatabaseDdl(db) {
        toast('Generating DDL…');
        try {
            const res = await api('db-ddl', { database: db });
            if (!res.ok) throw new Error(res.error.message);
            openQuery(db, res.ddl, { title: `DDL ${db}` });
        } catch (e) {
            toast('Error: ' + e.message);
        }
    }

    const fmtSize = (kb) => {
        if (kb < 1024) return `${fmt(kb)} KB`;
        if (kb < 1048576) return `${(kb / 1024).toLocaleString('en-US', { maximumFractionDigits: 1 })} MB`;
        return `${(kb / 1048576).toLocaleString('en-US', { maximumFractionDigits: 2 })} GB`;
    };

    function dataTable(headers, rows) {
        if (!rows.length) return el('p', { class: 'muted', text: 'No records.' });
        return el('table', { class: 'info-table' },
            el('thead', {}, el('tr', {}, headers.map((h) => el('th', { text: h })))),
            el('tbody', {}, rows.map((r) => el('tr', {}, r.map((c) => el('td', { text: c }))))));
    }

    function infoBody(d) {
        const summary = [
            ['Rows (approximate)', d.rows === null ? '—' : fmt(d.rows)],
            ['Reserved space', fmtSize(d.reservedKb)],
            ['Data', fmtSize(d.dataKb)],
            ['Indexes', fmtSize(d.indexKb)],
            ['Unused', fmtSize(d.unusedKb)],
        ];
        const restriction = (i) => [i.primaryKey && 'PK', i.uniqueConstraint && 'UNIQUE', i.unique && !i.primaryKey && !i.uniqueConstraint && 'UNIQUE INDEX']
            .filter(Boolean).join(', ') || '—';

        return [
            el('dl', { class: 'info-grid' }, summary.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { text: v })])),
            el('h4', { text: `Indexes (${d.indexes.length})` }),
            dataTable(['Name', 'Type', 'Keys', 'Included', 'Constraint'], d.indexes.map((i) => [
                i.name, i.type, i.keys.map((k) => k.name + (k.desc ? ' DESC' : '')).join(', '), i.included.join(', ') || '—', restriction(i),
            ])),
            el('h4', { text: `Foreign keys (${d.foreignKeys.length})` }),
            dataTable(['Name', 'Relationship', 'Columns', 'Related table', 'ON DELETE / UPDATE'], d.foreignKeys.map((fk) => {
                const out = fk.direction === 'out';
                return [
                    fk.name,
                    out ? 'references' : 'referenced by',
                    `${(out ? fk.columns : fk.refColumns).join(', ')} → ${(out ? fk.refColumns : fk.columns).join(', ')}`,
                    out ? fk.refTable : fk.parentTable,
                    `${fk.onDelete} / ${fk.onUpdate}`,
                ];
            })),
        ];
    }

    async function showInfo(db, o) {
        try {
            const res = await api('table-info', { database: db, schema: o.schema, name: o.name });
            if (!res.ok) throw new Error(res.error.message);
            showDialog({ title: `Information: ${o.schema}.${o.name}`, body: infoBody(res), wide: true });
        } catch (e) {
            toast('Error: ' + e.message);
        }
    }

    async function copyName(text) {
        toast((await copyText(text)) ? `Copied: ${text}` : 'Could not copy to the clipboard.');
    }

    /** Reloads only the object's columns/parameters. */
    function refreshObject(db, o) {
        const key = `${db}|${o.schema}.${o.name}`;
        columnCache.delete(key);
        expanded.add(key);
        renderTree();
        toast(`${o.schema}.${o.name} refreshed.`);
    }

    /** Reloads all child objects of the database. */
    function refreshDatabase(db) {
        dbObjects.delete(db);
        [...columnCache.keys()].filter((k) => k.startsWith(db + '|')).forEach((k) => columnCache.delete(k));
        openDbs.add(db);
        renderTree(); // shows "Loading…" and triggers the reload
        toast(`${db} refreshed.`);
    }

    async function refreshAll() {
        dbObjects.clear();
        columnCache.clear();
        await loadDatabases();
        toast('Object Explorer refreshed.');
    }

    function objectMenu(db, o) {
        return [
            ...(isTableLike(o) ? [
                { label: 'SELECT TOP 100', run: () => selectTop(db, o) },
                { label: 'SELECT * (no TOP)', run: () => selectAll(db, o) },
                '-',
            ] : []),
            { label: 'View DDL', run: () => showDdl(db, o) },
            ...(isTableLike(o) ? [{ label: 'Table information', run: () => showInfo(db, o) }] : []),
            '-',
            { label: 'Copy qualified name', run: () => copyName(qualified(o.schema, o.name)) },
            { label: 'Copy plain name', run: () => copyName(o.name) },
            '-',
            { label: 'Refresh', run: () => refreshObject(db, o) },
        ];
    }


    // ---------- Data import (CSV/JSON) ----------
    const IMPORT_TYPES = ['nvarchar(50)', 'nvarchar(100)', 'nvarchar(255)', 'nvarchar(1000)', 'nvarchar(4000)', 'nvarchar(max)',
        'int', 'bigint', 'smallint', 'tinyint', 'bit', 'float', 'real', 'decimal(38,0)', 'decimal(38,2)', 'decimal(38,4)',
        'decimal(38,6)', 'decimal(38,10)', 'date', 'datetime2', 'uniqueidentifier'];
    const IMPORT_SKIP = '';
    const normKey = (s) => s.toLowerCase().replace(/[^\p{L}\p{N}]/gu, '');

    /** Imports a CSV or JSON file into a table of the database: existing (with column mapping) or new. */
    async function showImportDialog(db) {
        let tables;
        try {
            const res = await api('objects', { database: db });
            if (!res.ok) throw new Error(res.error.message);
            tables = res.objects.filter((o) => o.type === 'U');
        } catch (e) {
            toast('Error: ' + e.message);
            return;
        }

        const state = { fileName: '', text: '', data: null, targets: [], resumeFrom: 0, cancelled: false, busy: false, created: false };

        // --- controls
        const fileInput = el('input', { type: 'file', accept: '.csv,.tsv,.txt,.json,text/csv,application/json' });
        const sepSelect = el('select', { 'aria-label': 'Delimiter' },
            [['', 'Auto-detect'], [';', '; (semicolon)'], [',', ', (comma)'], ['\t', 'Tab'], ['|', '| (pipe)']]
                .map(([v, t]) => el('option', { value: v, text: t })));
        const headerChk = el('input', { type: 'checkbox', checked: true });
        const csvOpts = el('div', { class: 'imp-row', hidden: true },
            el('label', {}, 'Delimiter ', sepSelect), el('label', { class: 'check' }, headerChk, ' First row contains column names'));
        const fileInfo = el('div', { class: 'muted imp-info' });

        const modeExisting = el('input', { type: 'radio', name: 'imp-mode', value: 'existing', checked: true });
        const modeNew = el('input', { type: 'radio', name: 'imp-mode', value: 'new' });
        const tableSelect = el('select', { 'aria-label': 'Destination table' },
            tables.length ? tables.map((t) => el('option', { value: `${t.schema}.${t.name}`, text: `${t.schema}.${t.name}` }))
                : [el('option', { value: '', text: '(no tables in this database)' })]);
        const schemaInput = el('input', { value: 'dbo', 'aria-label': 'Schema', size: 10 });
        const nameInput = el('input', { placeholder: 'table name', 'aria-label': 'New table name', size: 24 });
        const emptyChk = el('input', { type: 'checkbox', checked: true });

        const mapBox = el('div', { class: 'imp-map' });
        const note = el('div', { class: 'imp-note' });
        const progress = el('progress', { max: 1, value: 0, hidden: true });
        const status = el('div', { class: 'imp-status', role: 'status' });
        const config = el('fieldset', { class: 'imp-config' },
            el('h4', { text: 'Destination' }),
            el('div', { class: 'imp-row' },
                el('label', { class: 'check' }, modeExisting, ' Existing table '), tableSelect),
            el('div', { class: 'imp-row' },
                el('label', { class: 'check' }, modeNew, ' New table '), schemaInput, '.', nameInput),
            el('label', { class: 'check' }, emptyChk, ' Treat empty values as NULL'),
            el('h4', { text: 'Column mapping' }), mapBox, note);

        const importBtn = el('button', { class: 'btn primary', text: 'Import', disabled: true });
        const closeBtn = el('button', { class: 'btn bordered', text: 'Close' });

        // --- derived state
        const isNew = () => modeNew.checked;
        const setNote = (msg, kind = '') => { note.className = 'imp-note ' + kind; note.textContent = msg; };

        /** One <select> per file column (existing table) or an editable name + type (new table). */
        function renderMapping() {
            const d = state.data;
            if (!d) { mapBox.replaceChildren(); validate(); return; }
            const sample = (i) => [...new Set(d.rows.map((r) => r[i]).filter((v) => v !== null && v !== '').slice(0, 50))]
                .slice(0, 3).join(' | ').slice(0, 60);
            const rowsEls = d.columns.map((name, i) => {
                const target = isNew()
                    ? [el('input', { class: 'imp-name', value: name, 'aria-label': `Column name for ${name}` }),
                        el('select', { class: 'imp-type', 'aria-label': `Type for ${name}` },
                            [...new Set([ImportData.inferType(d.rows.map((r) => ImportData.normalizeCell(r[i], emptyChk.checked))), ...IMPORT_TYPES])]
                                .map((t) => el('option', { value: t, text: t })))]
                    : [el('select', { class: 'imp-target', 'aria-label': `Target column for ${name}` },
                        el('option', { value: IMPORT_SKIP, text: '(skip)' }),
                        state.targets.map((c) => el('option', { value: c.name, text: `${c.name} (${c.type})` })))];
                return el('tr', {}, el('td', { text: name }), el('td', { class: 'muted', text: sample(i) }), el('td', { class: 'imp-tcell' }, target));
            });
            mapBox.replaceChildren(el('table', { class: 'info-table' },
                el('thead', {}, el('tr', {}, ['File column', 'Sample values', isNew() ? 'New column (name, type)' : 'Target column'].map((h) => el('th', { text: h })))),
                el('tbody', {}, rowsEls)));
            if (!isNew()) autoMap();
            for (const c of mapBox.querySelectorAll('select,input')) c.addEventListener('input', validate);
            validate();
        }

        function autoMap() {
            const byName = new Map(state.targets.map((c) => [c.name.toLowerCase(), c.name]));
            const byNorm = new Map(state.targets.map((c) => [normKey(c.name), c.name]));
            const used = new Set();
            mapBox.querySelectorAll('tr').forEach((tr, i) => {
                const sel = tr.querySelector('.imp-target');
                if (!sel) return;
                const col = state.data.columns[i - 1]; // row 0 is the header
                const match = byName.get(col.toLowerCase()) ?? byNorm.get(normKey(col));
                if (match && !used.has(match)) { sel.value = match; used.add(match); }
            });
        }

        /** Reads the mapping from the screen. Returns { error } or { idx, names, create }. */
        function plan() {
            if (!state.data) return { error: 'Choose a CSV or JSON file.' };
            const trs = [...mapBox.querySelectorAll('tbody tr')];
            if (isNew()) {
                const schema = schemaInput.value.trim();
                const table = nameInput.value.trim();
                if (!schema || !table) return { error: 'Enter a schema and a table name for the new table.' };
                const names = trs.map((tr) => tr.querySelector('.imp-name').value.trim());
                const types = trs.map((tr) => tr.querySelector('.imp-type').value);
                if (names.some((n) => !n)) return { error: 'Every new column needs a name.' };
                if (new Set(names.map((n) => n.toLowerCase())).size !== names.length) return { error: 'New column names must be unique.' };
                return { schema, table, idx: names.map((_, i) => i), names, create: names.map((name, i) => ({ name, type: types[i] })) };
            }
            const [schema, ...rest] = tableSelect.value.split('.');
            if (!tableSelect.value) return { error: 'Choose a destination table.' };
            const picks = trs.map((tr) => tr.querySelector('.imp-target').value);
            const idx = picks.flatMap((p, i) => (p === IMPORT_SKIP ? [] : [i]));
            const names = idx.map((i) => picks[i]);
            if (!idx.length) return { error: 'Map at least one column.' };
            if (new Set(names).size !== names.length) return { error: 'Two file columns are mapped to the same target column.' };
            return { schema, table: rest.join('.'), idx, names, create: null };
        }

        function validate() {
            if (state.busy) return;
            const p = plan();
            const hint = [];
            if (!p.error && !isNew()) {
                const missing = state.targets.filter((c) => !c.nullable && !p.names.includes(c.name)).map((c) => c.name);
                if (missing.length) hint.push(`NOT NULL columns not mapped (the import fails unless they have a default): ${missing.join(', ')}.`);
                if (state.skipped.length) hint.push(`Identity, computed and rowversion columns are not listed: ${state.skipped.join(', ')}.`);
            }
            setNote(p.error || hint.join(' '), p.error ? '' : hint.length ? 'warn' : '');
            importBtn.disabled = Boolean(p.error);
            importBtn.textContent = state.resumeFrom ? `Resume from row ${fmt(state.resumeFrom + 1)}` : 'Import';
        }

        async function loadTargets() {
            state.targets = [];
            state.skipped = [];
            if (isNew() || !tableSelect.value) return;
            const [schema, ...rest] = tableSelect.value.split('.');
            try {
                const res = await api('columns', { database: db, schema, name: rest.join('.'), type: 'U' });
                if (!res.ok) throw new Error(res.error.message);
                const writable = (c) => !c.identity && !c.computed && !/^(timestamp|rowversion)$/i.test(c.type);
                state.targets = res.columns.filter(writable);
                state.skipped = res.columns.filter((c) => !writable(c)).map((c) => c.name);
            } catch (e) {
                setNote(`Could not read the table columns: ${e.message}`, 'err');
            }
        }

        async function refreshAll() {
            await loadTargets();
            renderMapping();
        }

        function parseFile() {
            state.data = null;
            fileInfo.textContent = '';
            try {
                const isJson = /\.json$/i.test(state.fileName) || (!/\.(csv|tsv|txt)$/i.test(state.fileName) && /^\s*\[/.test(state.text));
                csvOpts.hidden = isJson;
                state.data = isJson
                    ? ImportData.tableFromJson(state.text)
                    : ImportData.tableFromCsv(state.text, { separator: sepSelect.value || undefined, header: headerChk.checked });
                const sep = state.data.separator;
                fileInfo.textContent = `${state.fileName}: ${fmt(state.data.rows.length)} row(s), ${fmt(state.data.columns.length)} column(s)`
                    + (sep ? ` · delimiter: ${sep === '\t' ? 'Tab' : sep}` : '');
                if (!nameInput.value) nameInput.value = ImportData.tableNameFromFile(state.fileName);
            } catch (e) {
                fileInfo.textContent = e.message;
            }
            state.resumeFrom = 0;
            renderMapping();
        }

        // --- events
        fileInput.addEventListener('change', async () => {
            const f = fileInput.files[0];
            if (!f) return;
            state.fileName = f.name;
            state.text = await f.text();
            nameInput.value = '';
            parseFile();
        });
        sepSelect.addEventListener('change', parseFile);
        headerChk.addEventListener('change', parseFile);
        emptyChk.addEventListener('change', renderMapping);
        tableSelect.addEventListener('change', refreshAll);
        modeExisting.addEventListener('change', refreshAll);
        modeNew.addEventListener('change', refreshAll);
        schemaInput.addEventListener('input', validate);
        nameInput.addEventListener('input', validate);
        nameInput.addEventListener('focus', () => { modeNew.checked = true; refreshAll(); });
        tableSelect.addEventListener('focus', () => { if (isNew()) { modeExisting.checked = true; refreshAll(); } });
        closeBtn.addEventListener('click', () => $('dialog').close());

        importBtn.addEventListener('click', async () => {
            const p = plan();
            if (p.error || state.busy) return;
            state.busy = true;
            state.cancelled = false;
            config.disabled = true;
            fileInput.disabled = csvOpts.querySelectorAll('select,input').forEach((c) => { c.disabled = true; }) ?? true;
            importBtn.disabled = true;
            progress.hidden = false;
            status.className = 'imp-status';

            const startedAt = Date.now();
            const rows = state.data.rows;
            const total = rows.length;
            const batch = Math.max(1, Math.min(1000, Math.floor(20000 / p.idx.length)));
            let done = state.resumeFrom;
            let failure = null;

            progress.max = total;
            progress.value = done;
            while (done < total && !state.cancelled) {
                const slice = rows.slice(done, done + batch)
                    .map((r) => p.idx.map((i) => ImportData.normalizeCell(r[i], emptyChk.checked)));
                const create = !state.created && state.resumeFrom === 0 && done === 0 ? p.create : null;
                let res;
                try {
                    res = await api('import', { database: db, schema: p.schema, table: p.table, columns: p.names, rows: slice, create });
                } catch (e) {
                    res = { ok: false, error: { message: e.message } };
                }
                if (!res.ok) { failure = { from: done + 1, to: done + slice.length, message: res.error.message }; break; }
                if (create) state.created = true;
                done += slice.length;
                progress.value = done;
                status.textContent = `Imported ${fmt(done)} of ${fmt(total)} row(s)…`;
            }

            state.busy = false;
            state.resumeFrom = done;
            if (state.created) refreshDatabase(db);
            const where = `${quoteId(p.schema)}.${quoteId(p.table)}`;
            if (failure) {
                status.className = 'imp-status err';
                status.textContent = `Rows ${fmt(failure.from)}–${fmt(failure.to)} failed: ${failure.message} Nothing from that batch was saved; `
                    + `${fmt(done)} row(s) were already imported into ${where}.`;
                importBtn.disabled = false;
                importBtn.textContent = done ? `Resume from row ${fmt(done + 1)}` : 'Import';
                if (!done) config.disabled = false;
            } else if (state.cancelled) {
                status.textContent = `Stopped. ${fmt(done)} of ${fmt(total)} row(s) were imported into ${where}.`;
            } else {
                status.className = 'imp-status ok';
                status.textContent = `Imported ${fmt(done)} row(s) into ${where}.`;
                importBtn.textContent = 'Done';
                $('dialog').close();
                await closed; // the same <dialog> serves both modals: wait for it to close before reopening
                const choice = await showDialog({
                    title: 'Import completed',
                    body: [
                        el('p', { class: 'imp-success', text: `✔ ${fmt(done)} row(s) imported successfully.` }),
                        el('dl', { class: 'info-grid' },
                            el('dt', { text: 'Destination' }), el('dd', { text: `${db}.${p.schema}.${p.table}` }),
                            el('dt', { text: 'Table' }), el('dd', { text: state.created ? 'Created by this import' : 'Existing table (rows appended)' }),
                            el('dt', { text: 'File' }), el('dd', { text: state.fileName }),
                            el('dt', { text: 'Columns' }), el('dd', { text: fmt(p.idx.length) }),
                            el('dt', { text: 'Time' }), el('dd', { text: `${((Date.now() - startedAt) / 1000).toFixed(1)} s` })),
                    ],
                    actions: [{ label: 'Close', value: null }, { label: 'View data (SELECT TOP 100)', primary: true, value: 'view' }],
                });
                if (choice === 'view') selectTop(db, { schema: p.schema, name: p.table, type: 'U' });
            }
        });

        const closed = showDialog({
            title: `Import data into ${db}`,
            wide: true,
            actions: [],
            body: [
                el('p', { class: 'muted', text: 'Import rows from a CSV or JSON file (a JSON array of objects). Values are sent as text and converted by SQL Server, so prefer ISO dates (2026-10-09). Rows are appended; existing data is not changed.' }),
                el('div', { class: 'imp-row' }, el('label', {}, 'File ', fileInput)),
                csvOpts, fileInfo, config,
                progress, status,
            ],
        });
        $('dialog-actions').append(closeBtn, importBtn);
        closed.then(() => { state.cancelled = true; });
        refreshAll();
    }

    const databaseMenu = (db) => [
        { label: 'New query', run: () => newQuery(db) },
        { label: 'View DDL (database script)', run: () => showDatabaseDdl(db) },
        { label: 'Import data…', run: () => showImportDialog(db) },
        '-',
        { label: 'Refresh', run: () => refreshDatabase(db) },
    ];

    function bindExplorer() {
        $('btn-refresh-all').addEventListener('click', refreshAll);

        const dlg = $('dialog');
        $('dialog-close').addEventListener('click', () => dlg.close());
        dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });

        document.addEventListener('contextmenu', hideCtx);
        window.addEventListener('resize', hideCtx);
        $('tree').addEventListener('scroll', hideCtx);
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideCtx(); });
        ctx.addEventListener('keydown', (e) => {
            if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
            e.preventDefault();
            const items = [...ctx.querySelectorAll('button')];
            const next = items.indexOf(document.activeElement) + (e.key === 'ArrowDown' ? 1 : -1);
            items[(next + items.length) % items.length].focus();
        });
    }

    // ---------- Initialization ----------
    async function loadDatabases() {
        const sel = $('db-select');
        const res = await api('databases');
        if (!res.ok) {
            toast('Error: ' + res.error.message);
            return;
        }
        databases = res.databases.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
        sel.replaceChildren(...databases.map((d) => el('option', { value: d.name, text: d.name })));
        const names = databases.map((d) => d.name);
        const preferred = [activeTab().db, store.get('db', null)].find((n) => names.includes(n));
        sel.value = preferred ?? res.current;
        $('sb-conn').title = 'SQL Server ' + res.version;
        selectDb(sel.value);
    }

    function setupSideSplitter() {
        const side = $('sidebar');
        const handle = $('side-splitter');
        const DEFAULT = 320;
        const MIN = 200;
        const max = () => Math.max(MIN, Math.min(720, Math.floor(window.innerWidth * 0.6)));
        const apply = (w) => {
            const width = Math.max(MIN, Math.min(max(), Math.round(w)));
            side.style.width = `${width}px`;
            handle.setAttribute('aria-valuenow', String(width));
            return width;
        };
        const save = (w) => store.set('sidebarWidth', w);

        apply(store.get('sidebarWidth', DEFAULT));

        handle.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            const startX = e.clientX;
            const startW = side.offsetWidth;
            handle.setPointerCapture(e.pointerId);
            handle.classList.add('dragging');
            document.body.classList.add('resizing');

            const move = (ev) => apply(startW + ev.clientX - startX);
            const up = () => {
                handle.removeEventListener('pointermove', move);
                handle.removeEventListener('pointerup', up);
                handle.removeEventListener('pointercancel', up);
                handle.classList.remove('dragging');
                document.body.classList.remove('resizing');
                save(side.offsetWidth);
            };
            handle.addEventListener('pointermove', move);
            handle.addEventListener('pointerup', up);
            handle.addEventListener('pointercancel', up);
        });

        // Keyboard: arrows adjust by 16 px (Shift = 64 px); Home resets.
        handle.addEventListener('keydown', (e) => {
            const step = e.shiftKey ? 64 : 16;
            if (e.key === 'ArrowLeft') save(apply(side.offsetWidth - step));
            else if (e.key === 'ArrowRight') save(apply(side.offsetWidth + step));
            else if (e.key === 'Home') save(apply(DEFAULT));
            else return;
            e.preventDefault();
        });

        handle.addEventListener('dblclick', () => save(apply(DEFAULT)));
        window.addEventListener('resize', () => apply(side.offsetWidth));
    }

    function setupSplitter() {
        const pane = $('editor-pane');
        $('splitter').addEventListener('mousedown', (e) => {
            e.preventDefault();
            const startY = e.clientY;
            const startH = pane.offsetHeight;
            const max = $('main').clientHeight - 200;
            const move = (ev) => { pane.style.height = Math.max(80, Math.min(startH + ev.clientY - startY, max)) + 'px'; };
            const up = () => {
                document.removeEventListener('mousemove', move);
                document.removeEventListener('mouseup', up);
            };
            document.addEventListener('mousemove', move);
            document.addEventListener('mouseup', up);
        });
    }

    function bind() {
        ta.addEventListener('input', () => {
            activeTab().sql = ta.value;
            refreshEditor();
            persistTabs();
        });
        ta.addEventListener('scroll', syncScroll);
        ta.addEventListener('keydown', (e) => {
            if (e.key === 'Tab' && !e.shiftKey) {
                e.preventDefault();
                insertAtCursor('  ');
            }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                run();
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'F5') { e.preventDefault(); run(); }
        });

        $('btn-run').addEventListener('click', () => run());
        $('btn-clear').addEventListener('click', () => { loadSql(''); });
        $('btn-newtab').addEventListener('click', () => addTab());
        $('btn-sidebar').addEventListener('click', () => $('app').classList.toggle('no-sidebar'));

        $('btn-history').addEventListener('click', (e) => { e.stopPropagation(); openMenu($('menu-history'), buildHistory); });
        $('btn-fav').addEventListener('click', (e) => { e.stopPropagation(); openMenu($('menu-fav'), buildFavorites); });
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.menu')) document.querySelectorAll('.menu').forEach((m) => { m.hidden = true; });
        });

        $('btn-save').addEventListener('click', () => {
            if (!ta.value.trim()) { toast('The editor is empty.'); return; }
            download(`${activeTab().title.replace(/\s+/g, '_')}.sql`, 'text/plain;charset=utf-8', ta.value);
        });

        $('csv-sep').value = store.get('csvSep', $('csv-sep').dataset.default);
        $('csv-sep').addEventListener('change', (e) => store.set('csvSep', e.target.value));
        $('chk-top').checked = store.get('autoTop', true);
        $('inp-top').value = store.get('autoTopN', 1000);
        $('chk-top').addEventListener('change', (e) => store.set('autoTop', e.target.checked));
        $('inp-top').addEventListener('change', (e) => store.set('autoTopN', e.target.value));

        $('db-select').addEventListener('change', (e) => selectDb(e.target.value));
        $('obj-filter').addEventListener('input', renderTree);

        $('btn-theme').addEventListener('click', () => {
            const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = next;
            store.set('theme', next);
        });
        $('btn-logout').addEventListener('click', async () => {
            try { await api('logout'); } catch { /* session already ended */ }
            location.href = `${base}/login`;
        });

        $('tab-results').addEventListener('click', () => showPane('results'));
        $('tab-messages').addEventListener('click', () => showPane('messages'));

        $('page-size').addEventListener('change', (e) => {
            const m = currentModel();
            if (!m) return;
            m.size = parseInt(e.target.value, 10);
            m.page = 1;
            if (m.res.page) loadPage(m, 1); // the server rebuilds the page with the new size
            else renderResults();
        });
        let filterTimer;
        $('res-filter').addEventListener('input', (e) => {
            clearTimeout(filterTimer);
            filterTimer = setTimeout(() => {
                const m = currentModel();
                if (m) { m.filter = e.target.value; m.view = null; m.page = 1; renderResults(); }
            }, 150);
        });

        $('btn-copy').addEventListener('click', async () => {
            const d = exportRows();
            if (!d) return;
            const line = (r) => r.map((v) => (v === null ? '' : String(v).replace(/[\t\r\n]+/g, ' '))).join('\t');
            const ok = await copyText([line(d.columns), ...d.rows.map(line)].join('\n'));
            toast(ok ? `${fmt(d.rows.length)} row(s) copied${currentModel().res.page ? ' (this page only)' : ''}.` : 'Could not copy to the clipboard.');
        });
        $('btn-csv').addEventListener('click', () => { const pm = pagedModel(); if (pm) { serverDownload(pm, 'csv'); return; } const d = exportRows(); if (d) download(exportName(currentModel().sql, 'csv'), 'text/csv;charset=utf-8', toCsv(d)); });
        $('btn-xls').addEventListener('click', () => {
            const pm = pagedModel();
            if (pm) { serverXlsx(pm); return; }
            const d = exportRows();
            if (d && d.rows.length > 1048575) { toast('Excel supports at most 1,048,575 rows; use CSV.'); return; }
            if (d) download(exportName(currentModel().sql, 'xlsx'), XlsxWriter.MIME, XlsxWriter.build(d.columns, d.types, d.rows));
        });
        $('btn-json').addEventListener('click', () => { const pm = pagedModel(); if (pm) { serverDownload(pm, 'json'); return; } const d = exportRows(); if (d) download(exportName(currentModel().sql, 'json'), 'application/json', toJson(d)); });
    }

    bind();
    bindExplorer();
    setupSplitter();
    setupSideSplitter();
    ta.value = activeTab().sql;
    refreshEditor();
    renderTabs();
    renderResults();
    loadDatabases();
})();
