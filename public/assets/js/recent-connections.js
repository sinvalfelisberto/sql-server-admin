(() => {
    'use strict';

    // Remembers the server (host, instance or port) and user of successful logins.
    // The password is never stored. Data lives in this browser's localStorage.
    const KEY = 'sqlwm.recentConnections';
    const MAX = 10;

    const read = () => {
        try {
            const list = JSON.parse(localStorage.getItem(KEY) || '[]');
            return Array.isArray(list)
                ? list.filter((c) => c && typeof c.server === 'string' && typeof c.user === 'string')
                : [];
        } catch { return []; }
    };

    const write = (list) => {
        try { localStorage.setItem(KEY, JSON.stringify(list)); } catch { /* storage unavailable */ }
    };

    const remember = (server, user) => {
        if (!server || !user) return;
        const same = (c) => c.server.toLowerCase() === server.toLowerCase() && c.user === user;
        write([{ server, user }, ...read().filter((c) => !same(c))].slice(0, MAX));
    };

    // Editor page: the login just succeeded, so store what the session is using.
    const app = document.getElementById('app');
    if (app && app.dataset.recentServer) {
        remember(app.dataset.recentServer, app.dataset.recentUser || '');
    }

    // Login page: offer the saved entries.
    const wrap = document.getElementById('recent-wrap');
    const select = document.getElementById('recent');
    const removeBtn = document.getElementById('recent-remove');
    const serverInput = document.querySelector('input[name="server"]');
    const userInput = document.querySelector('input[name="user"]');
    const passInput = document.getElementById('pass');
    if (!wrap || !select || !removeBtn || !serverInput || !userInput) return;

    const selected = () => (select.value === '' ? null : read()[Number(select.value)] ?? null);

    const render = () => {
        const list = read();
        select.innerHTML = '';
        select.append(new Option('Saved connections…', ''));
        list.forEach((c, i) => select.append(new Option(`${c.user}@${c.server}`, String(i))));
        wrap.hidden = list.length === 0;
        removeBtn.hidden = true;
    };

    select.addEventListener('change', () => {
        const c = selected();
        removeBtn.hidden = c === null;
        if (c === null) return;
        serverInput.value = c.server;
        userInput.value = c.user;
        passInput?.focus();
    });

    removeBtn.addEventListener('click', () => {
        const index = Number(select.value);
        if (select.value === '') return;
        const list = read();
        list.splice(index, 1);
        write(list);
        render();
    });

    render();
})();
