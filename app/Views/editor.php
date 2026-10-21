<?php
/** @var array $data */
['e' => $e, 'version' => $version, 'connection' => $connection] = $data;
?>
<div id="app">

    <header class="toolbar">
        <button class="icon-btn" id="btn-sidebar" title="Alternar Object Explorer" aria-label="Alternar Object Explorer">
            <svg viewBox="0 0 24 24"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
        </button>

        <select id="db-select" title="Banco de dados" aria-label="Banco de dados"></select>

        <button class="btn primary" id="btn-run" title="Executar (F5 ou Ctrl+Enter)">
            <svg viewBox="0 0 24 24" class="fill"><path d="M6 4l14 8-14 8z"/></svg> Executar
        </button>
        <button class="icon-btn" id="btn-clear" title="Limpar editor" aria-label="Limpar editor">
            <svg viewBox="0 0 24 24"><path d="M3 17l6-12 8 4-6 12H6zM14 21h7"/></svg>
        </button>

        <div class="dropdown">
            <button class="btn ghost" id="btn-history">
                <svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5M12 7v5l3 2"/></svg> Histórico ▾
            </button>
            <div class="menu" id="menu-history" hidden></div>
        </div>
        <div class="dropdown">
            <button class="btn ghost" id="btn-fav">
                <svg viewBox="0 0 24 24" class="fill"><path d="M12 2l3 7 7 .6-5.3 4.7 1.7 7.2L12 17.8 5.6 21.5l1.7-7.2L2 9.6 9 9z"/></svg> Favoritos ▾
            </button>
            <div class="menu" id="menu-fav" hidden></div>
        </div>
        <button class="icon-btn" id="btn-save" title="Salvar consulta como .sql" aria-label="Salvar consulta">
            <svg viewBox="0 0 24 24" class="fill"><path d="M5 3h11l3 3v15H5zM8 3v5h7V3zM8 14h8v7H8z"/></svg>
        </button>

        <label class="auto-top">
            <input type="checkbox" id="chk-top"> AUTO TOP
            <input type="number" id="inp-top" min="1" value="1000" aria-label="Quantidade de linhas">
        </label>

        <span class="spacer"></span>
        <span class="user-info" title="Usuário @ Servidor">
            <svg viewBox="0 0 24 24" class="fill"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6z"/></svg>
            <?= $e($connection->user) ?>@<?= $e($connection->display) ?>
        </span>
        <button class="icon-btn" id="btn-theme" title="Alternar tema" aria-label="Alternar tema">
            <svg viewBox="0 0 24 24" class="fill"><path d="M21 13A9 9 0 1 1 11 3a7 7 0 0 0 10 10z"/></svg>
        </button>
        <button class="icon-btn" id="btn-logout" title="Sair" aria-label="Sair">
            <svg viewBox="0 0 24 24"><path d="M10 4H5v16h5M16 8l4 4-4 4M20 12H9"/></svg>
        </button>
    </header>

    <div class="workspace">
        <aside id="sidebar">
            <div class="side-title">
                <span>OBJECT EXPLORER</span>
                <span class="muted" id="explorer-server"><?= $e($connection->display) ?></span>
                <button class="icon-btn small" id="btn-refresh-all" title="Atualizar tudo" aria-label="Atualizar Object Explorer">
                    <svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-3-6.7M21 4v5h-5"/></svg>
                </button>
            </div>
            <div class="side-filter">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>
                <input id="obj-filter" placeholder="Filtrar objetos..." autocomplete="off">
            </div>
            <div id="tree" role="tree"></div>
        </aside>
        <div id="side-splitter" role="separator" aria-orientation="vertical" aria-label="Redimensionar Object Explorer"
             tabindex="0" title="Arraste para redimensionar (duplo clique restaura)"></div>

        <main id="main">
            <div class="tabs-bar">
                <div id="tabs"></div>
                <button class="icon-btn" id="btn-newtab" title="Nova consulta" aria-label="Nova consulta">+</button>
            </div>

            <section id="editor-pane">
                <div id="gutter" aria-hidden="true"></div>
                <div class="code">
                    <pre id="hl" aria-hidden="true"></pre>
                    <textarea id="editor" spellcheck="false" wrap="off" autocapitalize="off" autocomplete="off" aria-label="Editor SQL"></textarea>
                </div>
            </section>

            <div id="splitter" title="Arraste para redimensionar"></div>

            <section id="results">
                <div class="res-tabs">
                    <button class="res-tab active" id="tab-results">Resultados</button>
                    <button class="res-tab" id="tab-messages">Mensagens</button>
                    <span class="chips" id="setchips"></span>
                </div>

                <div id="results-panel">
                    <div class="grid-toolbar">
                        <label>Exibir
                            <select id="page-size">
                                <option>10</option><option>25</option><option>50</option>
                                <option selected>100</option><option>500</option><option>1000</option>
                                <option value="0">Todos</option>
                            </select>
                        </label>
                        <button class="btn export" id="btn-copy">Copiar</button>
                        <button class="btn export csv" id="btn-csv">CSV</button>
                        <button class="btn export xls" id="btn-xls">Excel</button>
                        <button class="btn export json" id="btn-json">JSON</button>
                        <span class="spacer"></span>
                        <label>Filtrar: <input id="res-filter" autocomplete="off"></label>
                    </div>
                    <div id="grid-wrap"><div class="empty">Execute uma consulta para ver os resultados.</div></div>
                    <div id="pager"></div>
                </div>

                <div id="messages-panel" hidden></div>
            </section>
        </main>
    </div>

    <footer class="statusbar">
        <span id="sb-conn"><i class="dot"></i> Conectado</span>
        <span id="sb-db"></span>
        <span class="spacer"></span>
        <span id="sb-rows"></span>
        <span id="sb-time"></span>
        <span>v<?= $e($version) ?></span>
    </footer>
</div>
<dialog id="dialog" aria-labelledby="dialog-title">
    <div class="dlg-head">
        <span id="dialog-title"></span>
        <button class="icon-btn" id="dialog-close" aria-label="Fechar">×</button>
    </div>
    <div class="dlg-body" id="dialog-body"></div>
    <div class="dlg-foot" id="dialog-actions"></div>
</dialog>
<div id="toast" role="status" aria-live="polite"></div>
