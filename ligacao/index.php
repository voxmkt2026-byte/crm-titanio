<?php

declare(strict_types=1);

?><!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Vox Insights — Histórico de Ligações</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body>
    <div class="app-shell">
        <header class="topbar">
            <a class="brand" href="./" aria-label="Vox Insights — início">
                <span class="brand-mark" aria-hidden="true">
                    <span></span><span></span><span></span><span></span>
                </span>
                <span>
                    <strong>Vox Insights</strong>
                    <small>Powered by Api4Com</small>
                </span>
            </a>
            <div class="topbar-actions">
                <span class="connection-pill" id="connectionPill">
                    <span class="connection-dot" aria-hidden="true"></span>
                    <span id="connectionText">Conectando</span>
                </span>
                <button class="icon-button" id="refreshButton" type="button" aria-label="Atualizar histórico" title="Atualizar histórico">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.34-5.66M20 4v6h-6"/></svg>
                </button>
            </div>
        </header>

        <main>
            <section class="hero" aria-labelledby="pageTitle">
                <div>
                    <p class="eyebrow">Central de atendimento</p>
                    <h1 id="pageTitle">Histórico de ligações</h1>
                    <p class="hero-copy">Consulte chamadas, ouça gravações e transforme conversas em insights acionáveis.</p>
                </div>
                <div class="hero-stat" aria-label="Total de ligações encontradas">
                    <span>Total encontrado</span>
                    <strong id="totalCalls">—</strong>
                    <small id="lastUpdate">Aguardando atualização</small>
                </div>
            </section>

            <section class="toolbar" aria-label="Filtros do histórico">
                <form id="searchForm" class="search-form" role="search">
                    <label class="sr-only" for="numberSearch">Buscar por número</label>
                    <span class="search-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    </span>
                    <input
                        id="numberSearch"
                        name="number"
                        type="search"
                        inputmode="tel"
                        autocomplete="off"
                        maxlength="40"
                        placeholder="Buscar por número de origem ou destino"
                    >
                    <button id="clearSearch" class="clear-search" type="button" hidden aria-label="Limpar busca">×</button>
                    <button class="search-button" type="submit">Buscar</button>
                </form>
            </section>

            <p id="status" class="status-message" role="status" aria-live="polite"></p>

            <section class="calls-panel" aria-labelledby="historyTitle">
                <div class="panel-heading">
                    <div>
                        <h2 id="historyTitle">Chamadas recentes</h2>
                        <p id="resultCaption">Carregando dados da Api4Com…</p>
                    </div>
                    <span class="page-indicator" id="pageIndicator">Página —</span>
                </div>

                <div class="calls-header" aria-hidden="true">
                    <span>Data e hora</span>
                    <span>Origem</span>
                    <span>Destino</span>
                    <span>Duração</span>
                    <span>Status</span>
                    <span class="align-right">Ações</span>
                </div>

                <div id="calls" class="calls-list" aria-label="Histórico de ligações" aria-busy="true"></div>

                <nav class="pagination" aria-label="Paginação do histórico">
                    <button id="previousPage" class="pagination-button" type="button" disabled>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                        Anterior
                    </button>
                    <span id="paginationSummary">Página 1</span>
                    <button id="nextPage" class="pagination-button" type="button" disabled>
                        Próxima
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </nav>
            </section>
        </main>
    </div>

    <dialog id="audioDialog" class="app-dialog audio-dialog" aria-labelledby="audioTitle">
        <div class="dialog-header">
            <div>
                <p class="dialog-kicker">Gravação da chamada</p>
                <h2 id="audioTitle">Ouvir ligação</h2>
                <p id="audioSubtitle"></p>
            </div>
            <button class="dialog-close" type="button" data-close="audioDialog" aria-label="Fechar player">×</button>
        </div>
        <div class="audio-visual" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
        </div>
        <audio id="audioPlayer" controls preload="metadata">Seu navegador não suporta reprodução de áudio.</audio>
        <p class="dialog-note">O áudio é transmitido com segurança pelo servidor.</p>
    </dialog>

    <dialog id="analysisDialog" class="app-dialog analysis-dialog" aria-labelledby="analysisTitle">
        <div class="dialog-header">
            <div>
                <p class="dialog-kicker">Inteligência da conversa</p>
                <h2 id="analysisTitle">Análise da ligação</h2>
                <p id="analysisSubtitle"></p>
            </div>
            <button class="dialog-close" type="button" data-close="analysisDialog" aria-label="Fechar análise">×</button>
        </div>
        <div id="analysisContent" class="analysis-content" aria-live="polite"></div>
        <div class="dialog-footer">
            <button id="reanalyzeButton" class="secondary-button" type="button" hidden>
                Analisar novamente <span>pode gerar novo custo</span>
            </button>
            <button class="primary-button" type="button" data-close="analysisDialog">Concluir</button>
        </div>
    </dialog>

    <template id="loadingTemplate">
        <div class="call-row skeleton-row" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span><span></span>
        </div>
    </template>

    <script src="assets/app.js?v=1" defer></script>
</body>
</html>

