(() => {
    'use strict';

    // ---------- Utilidades ----------
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
            try { localStorage.setItem('sqlwm.' + key, JSON.stringify(value)); } catch { /* cota cheia */ }
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
        s.innerHTML = ICONS[name]; // SVG estático definido acima
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
            throw new Error('Sessão expirada.');
        }
        let body;
        try { body = await res.json(); } catch { throw new Error('Resposta inválida do servidor (HTTP ' + res.status + ').'); }
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

    const stamp = () => new Date().toISOString().slice(0, 19).replace(/[-:]/g, '').replace('T', '-');

    // ---------- Editor com realce de sintaxe ----------
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
        // Uma quebra final precisa de um caractere para ocupar a linha.
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

    // ---------- Abas de consulta ----------
    let tabs = store.get('tabs', null);
    let activeId = store.get('activeTab', null);
    let nextId = 1;

    const TEMPLATE = '-- SQL Web Manager\n-- Pressione F5 para executar\n\nSELECT @@VERSION AS [Versão do SQL Server];\n';

    if (!Array.isArray(tabs) || !tabs.length) tabs = [{ id: 1, title: 'Query 1', sql: TEMPLATE }];
    nextId = Math.max(...tabs.map((t) => t.id)) + 1;
    if (!tabs.some((t) => t.id === activeId)) activeId = tabs[0].id;

    const models = new Map(); // id da aba -> estado dos resultados
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
            class: 'x', title: 'Fechar', text: '×',
            onclick: (e) => { e.stopPropagation(); closeTab(t.id); },
        }))));
    }

    function switchTab(id) {
        activeId = id;
        const tab = activeTab();
        ta.value = tab.sql;
        // Cada aba lembra o seu banco; só aplica se ele ainda existe na lista.
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
        // Fechar a última aba abre uma nova em branco, como no SSMS.
        if (tabs.length === 1) { tabs.splice(idx, 1); addTab(); return; }
        tabs.splice(idx, 1);
        if (id === activeId) switchTab(tabs[Math.max(0, idx - 1)].id);
        else { renderTabs(); persistTabs(); }
    }

    // ---------- Execução ----------
    let running = false;

    function setRunning(on) {
        running = on;
        const btn = $('btn-run');
        btn.disabled = on;
        btn.lastChild.textContent = on ? ' Executando…' : ' Executar';
    }

    /** @param {{unlimited?: boolean}} opts unlimited ignora o AUTO TOP (vale só o teto do servidor) */
    async function run(sqlOverride, { unlimited = false } = {}) {
        if (running) return;
        const selected = ta.value.substring(ta.selectionStart, ta.selectionEnd).trim();
        const sql = (sqlOverride ?? (selected || ta.value)).trim();
        if (!sql) { toast('Digite uma consulta para executar.'); return; }

        const tabId = activeId;
        const limit = !unlimited && $('chk-top').checked ? Math.max(1, parseInt($('inp-top').value, 10) || 1000) : 0;
        setRunning(true);

        let res;
        try {
            res = await api('execute', { sql, database: $('db-select').value, limit });
        } catch (e) {
            res = { ok: false, error: { message: e.message }, sets: [], messages: [], total_rows: 0, elapsed_ms: 0 };
        }
        setRunning(false);
        addHistory(sql);

        models.set(tabId, {
            res,
            setIdx: 0,
            sort: null,
            filter: '',
            page: 1,
            size: parseInt($('page-size').value, 10),
            view: null,
        });
        if (tabId === activeId) {
            $('res-filter').value = '';
            showPane(!res.ok && !res.sets.length ? 'messages' : 'results');
            renderResults();
        }
    }

    // ---------- Resultados ----------
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

    const fmt = (n) => Number(n).toLocaleString('pt-BR');

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
                text: m ? 'Nenhum conjunto de resultados retornado.' : 'Execute uma consulta para ver os resultados.',
            }));
            pager.replaceChildren();
            updateStatus(m);
            return;
        }

        const rows = m.view ?? computeView(m);
        const size = m.size || rows.length || 1;
        const pages = Math.max(1, Math.ceil(rows.length / size));
        m.page = Math.min(m.page, pages);
        const slice = rows.slice((m.page - 1) * size, m.page * size);

        const numCols = set.columns.map((c) => /int|decimal|numeric|float|real|money/i.test(c.type));
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
                }, c.name || '(Sem nome)', el('span', { class: 'arrow', text: sorted ? (m.sort.dir === 1 ? '▲' : '▼') : '⇅' }));
            }));

        const frag = document.createDocumentFragment();
        for (const { r, i } of slice) {
            const tr = el('tr', {}, el('td', { class: 'rn', text: i + 1 }));
            r.forEach((v, c) => {
                if (v === null) tr.append(el('td', {}, el('span', { class: 'null', text: 'NULL' })));
                else tr.append(el('td', { class: numCols[c] ? 'num' : '', title: String(v), text: String(v) }));
            });
            frag.append(tr);
        }

        wrap.replaceChildren(el('table', { class: 'grid' }, el('thead', {}, head), el('tbody', {}, frag)));

        const from = rows.length ? (m.page - 1) * size + 1 : 0;
        const to = Math.min(m.page * size, rows.length);
        pager.replaceChildren(
            el('span', { text: `Mostrando ${fmt(from)} a ${fmt(to)} de ${fmt(rows.length)} registros` + (m.filter ? ` (filtrado de ${fmt(set.rows.length)})` : '') }),
            el('span', { class: 'spacer' }),
            el('button', { text: '‹ Anterior', disabled: m.page <= 1, onclick: () => { m.page--; renderResults(); } }),
            el('span', { text: `${m.page} / ${pages}` }),
            el('button', { text: 'Próxima ›', disabled: m.page >= pages, onclick: () => { m.page++; renderResults(); } }),
        );
        updateStatus(m);
    }

    function renderChips(m) {
        const box = $('setchips');
        if (!m || m.res.sets.length < 2) { box.replaceChildren(); return; }
        box.replaceChildren(...m.res.sets.map((s, i) => el('button', {
            class: 'chip' + (i === m.setIdx ? ' active' : ''),
            text: `Resultado ${i + 1} (${fmt(s.rows.length)})`,
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
            lines.push(el('div', { class: 'msg', text: `(${fmt(s.rows.length)} linha(s) retornada(s)${res.sets.length > 1 ? ' no resultado ' + (i + 1) : ''})` }));
            if (s.truncated) {
                lines.push(el('div', { class: 'msg err', text: `Resultado ${i + 1} limitado às primeiras ${fmt(s.rows.length)} linhas.` }));
            }
        });
        if (res.error) {
            const n = res.error.number ? `Msg ${res.error.number}: ` : '';
            lines.push(el('div', { class: 'msg err', text: n + res.error.message }));
        }
        lines.push(el('div', { class: 'msg ' + (res.ok ? 'ok' : 'err'), text: res.ok ? 'Execução concluída.' : 'Execução com erro.' }));
        lines.push(el('div', { class: 'msg time', text: `Tempo total: ${fmt(res.elapsed_ms)} ms` }));
        box.replaceChildren(...lines);
    }

    function updateStatus(m) {
        $('sb-db').textContent = $('db-select').value;
        $('sb-rows').textContent = m ? `▦ ${fmt(m.res.total_rows)} linha(s)` : '';
        $('sb-time').textContent = m ? `◔ ${fmt(m.res.elapsed_ms)} ms` : '';
    }

    // ---------- Exportação ----------
    function exportRows() {
        const m = currentModel();
        const set = currentSet(m);
        if (!set) { toast('Não há resultados para exportar.'); return null; }
        const rows = (m.view ?? computeView(m)).map(({ r }) => r);
        return { columns: set.columns.map((c) => c.name), types: set.columns.map((c) => c.type), rows };
    }

    function toCsv({ columns, rows }) {
        const cell = (v) => {
            if (v === null) return '';
            const s = String(v);
            return /[";\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
        };
        return '﻿' + [columns.map(cell), ...rows.map((r) => r.map(cell))].map((l) => l.join(';')).join('\r\n');
    }

    function toJson({ columns, rows }) {
        const seen = {};
        const keys = columns.map((c) => {
            seen[c] = (seen[c] || 0) + 1;
            return seen[c] > 1 ? `${c}_${seen[c]}` : c;
        });
        return JSON.stringify(rows.map((r) => Object.fromEntries(keys.map((k, i) => [k, r[i]]))), null, 2);
    }

    /** Cópia síncrona via textarea: funciona dentro do gesto do usuário, sem permissão e em http. */
    function legacyCopy(text) {
        const tmp = el('textarea', { style: 'position:fixed;opacity:0', readonly: true });
        tmp.value = text;
        document.body.append(tmp);
        tmp.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch { /* sem suporte */ }
        tmp.remove();
        return ok;
    }

    /** Copia para a área de transferência; devolve se conseguiu. */
    async function copyText(text) {
        if (legacyCopy(text)) return true;
        try {
            // writeText pode ficar pendente (permissão não respondida): não espera mais que 1s.
            await Promise.race([
                navigator.clipboard.writeText(text),
                new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), 1000)),
            ]);
            return true;
        } catch {
            return false;
        }
    }

    // ---------- Histórico e favoritos ----------
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
        if (!list.length) { menu.replaceChildren(el('div', { class: 'none', text: 'Nenhuma consulta executada ainda.' })); return; }
        menu.replaceChildren(
            ...list.map((h) => el('button', {
                class: 'item',
                title: h.sql,
                onclick: () => { menu.hidden = true; loadSql(h.sql); },
            }, el('span', { class: 'txt', text: oneLine(h.sql) }), el('span', { class: 'meta', text: new Date(h.ts).toLocaleString('pt-BR') }))),
            el('div', { class: 'sep' }),
            el('button', { class: 'item', text: 'Limpar histórico', onclick: () => { store.set('history', []); menu.hidden = true; } }),
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
            class: 'del', title: 'Remover', text: '×',
            onclick: (e) => {
                e.stopPropagation();
                store.set('favorites', favs.filter((_, j) => j !== i));
                buildFavorites();
            },
        })));
        menu.replaceChildren(
            el('button', {
                class: 'item',
                text: '★ Adicionar consulta atual aos favoritos',
                onclick: () => {
                    const sql = ta.value.trim();
                    if (!sql) { toast('O editor está vazio.'); return; }
                    const name = prompt('Nome do favorito:', oneLine(sql).slice(0, 40));
                    if (!name) return;
                    store.set('favorites', [...favs, { name, sql }]);
                    menu.hidden = true;
                    toast('Favorito salvo.');
                },
            }),
            el('div', { class: 'sep' }),
            items.length ? items : el('div', { class: 'none', text: 'Nenhum favorito.' }),
        );
    }

    function loadSql(sql) {
        ta.value = sql;
        ta.dispatchEvent(new Event('input'));
        ta.focus();
    }

    // ---------- Object Explorer ----------
    const GROUPS = [
        { label: 'Tabelas', types: ['U'], icon: 'table', cls: '' },
        { label: 'Views', types: ['V'], icon: 'view', cls: 'view' },
        { label: 'Procedures', types: ['P'], icon: 'proc', cls: 'proc' },
        { label: 'Functions', types: ['FN', 'IF', 'TF'], icon: 'func', cls: 'func' },
    ];
    const TREE_LIMIT = 500;
    const PAD = { db: 8, group: 30, object: 52, child: 80 };

    let databases = [];              // [{ name, system }]
    const dbObjects = new Map();     // banco -> lista de objetos | { error }
    const loadingDbs = new Set();
    const openDbs = new Set();
    const openGroups = new Set();    // `${db}|${grupo}`
    const expanded = new Set();      // `${db}|${schema}.${objeto}`
    const columnCache = new Map();

    const quoteId = (s) => '[' + s.replace(/\]/g, ']]') + ']';
    const colRef = (n) => (/^[A-Za-z_]\w*$/.test(n) ? n : quoteId(n));
    const info = (text, pad) => el('div', { class: 'node info', style: `padding-left:${pad}px`, text });

    function chevron() {
        const span = el('span');
        span.innerHTML = ICONS.chev;
        return span.firstChild;
    }

    /** Define o banco de contexto (toolbar, status e consultas) sem mexer na árvore. */
    function setDb(db) {
        const sel = $('db-select');
        const tab = activeTab();
        if (tab && tab.db !== db) { tab.db = db; persistTabs(); }
        if (sel.value === db) return;
        sel.value = db;
        store.set('db', db);
        $('sb-db').textContent = db;
    }

    /** Seleciona o banco, expande-o e carrega seus objetos sob demanda. */
    function selectDb(db) {
        setDb(db);
        openDbs.add(db);
        loadObjects(db);
        renderTree();
    }

    /** Carrega os objetos do banco uma vez; quem chama é responsável por redesenhar a árvore. */
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

        if (!out.length) out.push(info(databases.length ? 'Nenhum objeto encontrado.' : 'Carregando bancos…', PAD.db));
        $('tree').replaceChildren(...out);
    }

    function databaseChildren(db, loaded, items, f) {
        if (loaded === undefined) {
            loadObjects(db); // idempotente: cobre bancos abertos após "Atualizar"
            return [info('Carregando objetos…', PAD.group)];
        }
        if (!Array.isArray(loaded)) return [info('Erro: ' + loaded.error, PAD.group)];

        const out = [];
        for (const g of GROUPS) {
            const groupItems = items.filter((o) => g.types.includes(o.type));
            if (f && !groupItems.length) continue;

            const key = `${db}|${g.label}`;
            const open = f ? true : openGroups.has(key);
            out.push(el('div', {
                class: 'node' + (open ? ' open' : ''),
                style: `padding-left:${PAD.group}px`,
                oncontextmenu: (e) => showCtx(e, [{ label: 'Atualizar', run: () => refreshDatabase(db) }]),
                onclick: () => { openGroups.has(key) ? openGroups.delete(key) : openGroups.add(key); renderTree(); },
            }, chevron(), icon('folder', 'folder'), el('span', { class: 'lbl', text: g.label }),
            el('span', { class: 'count', text: `(${fmt(groupItems.length)})` })));

            if (!open) continue;
            groupItems.slice(0, TREE_LIMIT).forEach((o) => out.push(...objectNode(db, o, g)));
            if (groupItems.length > TREE_LIMIT) {
                out.push(info(`… mais ${fmt(groupItems.length - TREE_LIMIT)} (refine o filtro)`, PAD.object));
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
            title: isTable ? 'Duplo clique: SELECT TOP · Botão direito: mais opções' : 'Duplo clique: inserir no editor · Botão direito: mais opções',
            oncontextmenu: (e) => showCtx(e, objectMenu(db, o)),
            onclick: () => { open ? expanded.delete(key) : expanded.add(key); renderTree(); },
            ondblclick: () => {
                setDb(db);
                if (isTable) {
                    const top = $('chk-top').checked ? Math.max(1, parseInt($('inp-top').value, 10) || 1000) : 1000;
                    loadSql(`SELECT TOP (${top}) * FROM ${full}`);
                    run();
                } else {
                    insertAtCursor(o.type === 'P' ? `EXEC ${full} ` : full);
                }
            },
        }, chevron(), icon(g.icon, g.cls), el('span', { class: 'lbl', text: `${o.schema}.${o.name}` }));

        const result = [node];
        if (!open) return result;

        const cached = columnCache.get(key);
        if (!cached) {
            result.push(info('Carregando…', PAD.child));
            fetchColumns(key, db, o);
            return result;
        }
        cached.forEach((c) => result.push(el('div', {
            class: 'node col',
            style: `padding-left:${PAD.child}px`,
            title: 'Clique para inserir no editor',
            onclick: () => insertAtCursor(colRef(c.name)),
        }, icon(c.pk ? 'key' : 'col', c.pk ? 'pk' : ''), el('span', { class: 'lbl', text: c.name }),
        el('span', { class: 'type', text: `${c.type}${c.nullable ? '' : ' not null'}` }))));
        if (!cached.length) result.push(info('Sem colunas/parâmetros.', PAD.child));
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

    // ---------- Menu de contexto e ações do Object Explorer ----------
    const LARGE_TABLE_ROWS = 100000;
    const qualified = (schema, name) => `${quoteId(schema)}.${quoteId(name)}`;
    const isTableLike = (o) => o.type === 'U' || o.type === 'V';

    const ctx = el('div', { class: 'menu ctx', role: 'menu', hidden: true });
    document.body.append(ctx);
    const hideCtx = () => { ctx.hidden = true; };

    /** Mostra o menu junto ao cursor; itens são { label, run } ou '-' (separador). */
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

    /** Diálogo modal reutilizável. Resolve com o value da ação clicada, ou null se fechado. */
    function showDialog({ title, body, actions = [{ label: 'Fechar', value: null }], wide = false }) {
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

    /** Abre uma nova aba no banco indicado, opcionalmente já executando. */
    function openQuery(db, sql, { title, execute = false, unlimited = false } = {}) {
        addTab(sql, { title, db });
        if (execute) run(undefined, { unlimited });
    }

    const selectTop = (db, o) => openQuery(db, `SELECT TOP 100 * FROM ${qualified(o.schema, o.name)}`, { execute: true });
    const newQuery = (db) => openQuery(db, `USE ${quoteId(db)};\nGO\n\n`);

    /** SELECT * sem TOP: pede confirmação antes de executar se a tabela for grande. */
    async function selectAll(db, o) {
        let proceed = true;
        if (o.type === 'U') {
            try {
                const res = await api('table-info', { database: db, schema: o.schema, name: o.name });
                if (res.ok && res.rows !== null && res.rows > LARGE_TABLE_ROWS) {
                    proceed = await showDialog({
                        title: 'Tabela volumosa',
                        body: el('p', { text: `${o.schema}.${o.name} tem cerca de ${fmt(res.rows)} linhas. Trazer tudo pode sobrecarregar o navegador e o servidor (o servidor ainda limita o retorno ao teto configurado).` }),
                        actions: [{ label: 'Cancelar', value: false }, { label: 'Executar mesmo assim', primary: true, value: true }],
                    }) === true;
                }
            } catch { /* sem estimativa: segue sem aviso */ }
        }
        openQuery(db, `SELECT * FROM ${qualified(o.schema, o.name)}`, { execute: proceed, unlimited: true });
        if (!proceed) toast('Consulta gerada, mas não executada.');
    }

    async function showDdl(db, o) {
        toast('Gerando DDL…');
        try {
            const res = await api('ddl', { database: db, schema: o.schema, name: o.name, type: o.type });
            if (!res.ok) throw new Error(res.error.message);
            openQuery(db, res.ddl, { title: `DDL ${o.name}` });
        } catch (e) {
            toast('Erro: ' + e.message);
        }
    }

    async function showDatabaseDdl(db) {
        toast('Gerando DDL…');
        try {
            const res = await api('db-ddl', { database: db });
            if (!res.ok) throw new Error(res.error.message);
            openQuery(db, res.ddl, { title: `DDL ${db}` });
        } catch (e) {
            toast('Erro: ' + e.message);
        }
    }

    const fmtSize = (kb) => {
        if (kb < 1024) return `${fmt(kb)} KB`;
        if (kb < 1048576) return `${(kb / 1024).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;
        return `${(kb / 1048576).toLocaleString('pt-BR', { maximumFractionDigits: 2 })} GB`;
    };

    function dataTable(headers, rows) {
        if (!rows.length) return el('p', { class: 'muted', text: 'Nenhum registro.' });
        return el('table', { class: 'info-table' },
            el('thead', {}, el('tr', {}, headers.map((h) => el('th', { text: h })))),
            el('tbody', {}, rows.map((r) => el('tr', {}, r.map((c) => el('td', { text: c }))))));
    }

    function infoBody(d) {
        const summary = [
            ['Linhas (aproximado)', d.rows === null ? '—' : fmt(d.rows)],
            ['Espaço reservado', fmtSize(d.reservedKb)],
            ['Dados', fmtSize(d.dataKb)],
            ['Índices', fmtSize(d.indexKb)],
            ['Não utilizado', fmtSize(d.unusedKb)],
        ];
        const restriction = (i) => [i.primaryKey && 'PK', i.uniqueConstraint && 'UNIQUE', i.unique && !i.primaryKey && !i.uniqueConstraint && 'ÚNICO']
            .filter(Boolean).join(', ') || '—';

        return [
            el('dl', { class: 'info-grid' }, summary.flatMap(([k, v]) => [el('dt', { text: k }), el('dd', { text: v })])),
            el('h4', { text: `Índices (${d.indexes.length})` }),
            dataTable(['Nome', 'Tipo', 'Chaves', 'Incluídas', 'Restrição'], d.indexes.map((i) => [
                i.name, i.type, i.keys.map((k) => k.name + (k.desc ? ' DESC' : '')).join(', '), i.included.join(', ') || '—', restriction(i),
            ])),
            el('h4', { text: `Chaves estrangeiras (${d.foreignKeys.length})` }),
            dataTable(['Nome', 'Relação', 'Colunas', 'Tabela relacionada', 'ON DELETE / UPDATE'], d.foreignKeys.map((fk) => {
                const out = fk.direction === 'out';
                return [
                    fk.name,
                    out ? 'referencia' : 'referenciada por',
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
            showDialog({ title: `Informações: ${o.schema}.${o.name}`, body: infoBody(res), wide: true });
        } catch (e) {
            toast('Erro: ' + e.message);
        }
    }

    async function copyName(text) {
        toast((await copyText(text)) ? `Copiado: ${text}` : 'Não foi possível copiar para a área de transferência.');
    }

    /** Recarrega só as colunas/parâmetros do objeto. */
    function refreshObject(db, o) {
        const key = `${db}|${o.schema}.${o.name}`;
        columnCache.delete(key);
        expanded.add(key);
        renderTree();
        toast(`${o.schema}.${o.name} atualizado.`);
    }

    /** Recarrega todos os objetos filhos do banco. */
    function refreshDatabase(db) {
        dbObjects.delete(db);
        [...columnCache.keys()].filter((k) => k.startsWith(db + '|')).forEach((k) => columnCache.delete(k));
        openDbs.add(db);
        renderTree(); // mostra "Carregando…" e dispara o recarregamento
        toast(`${db} atualizado.`);
    }

    async function refreshAll() {
        dbObjects.clear();
        columnCache.clear();
        await loadDatabases();
        toast('Object Explorer atualizado.');
    }

    function objectMenu(db, o) {
        return [
            ...(isTableLike(o) ? [
                { label: 'SELECT TOP 100', run: () => selectTop(db, o) },
                { label: 'SELECT * (sem TOP)', run: () => selectAll(db, o) },
                '-',
            ] : []),
            { label: 'Ver DDL', run: () => showDdl(db, o) },
            ...(isTableLike(o) ? [{ label: 'Informações da tabela', run: () => showInfo(db, o) }] : []),
            '-',
            { label: 'Copiar nome qualificado', run: () => copyName(qualified(o.schema, o.name)) },
            { label: 'Copiar nome simples', run: () => copyName(o.name) },
            '-',
            { label: 'Atualizar', run: () => refreshObject(db, o) },
        ];
    }

    const databaseMenu = (db) => [
        { label: 'Nova consulta', run: () => newQuery(db) },
        { label: 'Ver DDL (script do banco)', run: () => showDatabaseDdl(db) },
        '-',
        { label: 'Atualizar', run: () => refreshDatabase(db) },
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

    // ---------- Inicialização ----------
    async function loadDatabases() {
        const sel = $('db-select');
        const res = await api('databases');
        if (!res.ok) {
            toast('Erro: ' + res.error.message);
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

        // Teclado: setas ajustam de 16 em 16 px (Shift = 64 px); Home restaura.
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
            if (!ta.value.trim()) { toast('O editor está vazio.'); return; }
            download(`${activeTab().title.replace(/\s+/g, '_')}.sql`, 'text/plain;charset=utf-8', ta.value);
        });

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
            try { await api('logout'); } catch { /* sessão já encerrada */ }
            location.href = `${base}/login`;
        });

        $('tab-results').addEventListener('click', () => showPane('results'));
        $('tab-messages').addEventListener('click', () => showPane('messages'));

        $('page-size').addEventListener('change', (e) => {
            const m = currentModel();
            if (m) { m.size = parseInt(e.target.value, 10); m.page = 1; renderResults(); }
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
            toast(ok ? `${fmt(d.rows.length)} linha(s) copiada(s).` : 'Não foi possível copiar para a área de transferência.');
        });
        $('btn-csv').addEventListener('click', () => { const d = exportRows(); if (d) download(`resultado-${stamp()}.csv`, 'text/csv;charset=utf-8', toCsv(d)); });
        $('btn-xls').addEventListener('click', () => {
            const d = exportRows();
            if (d) download(`resultado-${stamp()}.xlsx`, XlsxWriter.MIME, XlsxWriter.build(d.columns, d.types, d.rows));
        });
        $('btn-json').addEventListener('click', () => { const d = exportRows(); if (d) download(`resultado-${stamp()}.json`, 'application/json', toJson(d)); });
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
