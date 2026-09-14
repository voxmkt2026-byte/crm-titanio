<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
<title>Vox Insights — Central de ligações</title>
<link rel="stylesheet" href="assets/app.css?v=20260914"><link rel="stylesheet" href="assets/central.css?v=20260914-compact">
</head>
<body>
<div class="app-shell">
<header class="topbar"><a class="brand" href="./"><span class="central-brand-icon">◉</span><span><strong>Vox Insights</strong><small>Telefonia e inteligência comercial</small></span></a><div class="topbar-actions"><span class="connection-pill" id="connectionPill"><span class="connection-dot"></span><span id="connectionText">Selecione uma linha</span></span><button id="settingsButton" type="button" class="secondary-button">⚙ Configurar contas</button></div></header>
<main>
<section class="hero"><div><p class="eyebrow">SEU ESPAÇO DE CONVERSAS</p><h1 id="pageTitle">Cada ligação.<br>Mais possibilidades.</h1><p class="hero-copy">Ligue, ouça e acompanhe suas conversas em um só lugar.</p></div><div class="hero-stat"><span>Total da linha selecionada</span><strong id="totalCalls">—</strong><small id="lastUpdate">Aguardando seleção</small></div></section>
<div class="line-launchers">
<button type="button" class="line-card" data-line="1" disabled><span class="line-icon">☎</span><span><small>CONTA API4COM · 01</small><strong data-line-name>Linha 1</strong><span data-line-status>Verificando configuração…</span></span><span class="line-arrow">↗</span></button>
<button type="button" class="line-card" data-line="2" disabled style="--line:#7c3aed"><span class="line-icon">☎</span><span><small>CONTA API4COM · 02</small><strong data-line-name>Linha 2</strong><span data-line-status>Verificando configuração…</span></span><span class="line-arrow">↗</span></button>
</div>
<p class="central-hint">Duas contas independentes. Seu histórico e suas análises organizados por linha.</p>
<div class="feature-cards"><section><span>◷</span><h2>Contexto sempre à mão</h2><p>Histórico, gravações e relatórios na mesma central.</p></section><section><span>✧</span><h2>Inteligência para agir</h2><p>Resumos, oportunidades e próximos passos das conversas.</p></section><section><span>♧</span><h2>Você no controle</h2><p>Escolha a linha e ative o telefone quando precisar ligar.</p></section></div>
<p id="landingNotice" role="status"></p>
</main></div>

<dialog id="centralDialog" class="central-dialog" aria-labelledby="centralTitle">
<header class="central-header"><span class="central-brand-icon">☎</span><div><small>CENTRAL DE CONVERSAS</small><h2 id="centralTitle">Linha 1</h2></div><div class="central-header-actions"><button id="mobileHistory" type="button" aria-expanded="false">☰ Histórico</button><button id="refreshButton" type="button" title="Atualizar histórico" aria-label="Atualizar histórico">↻</button><button type="button" data-close="centralDialog" aria-label="Fechar central">×</button></div></header>
<div class="central-grid">
<aside class="central-sidebar">
<div class="sidebar-heading"><h2 id="historyTitle">Histórico</h2><span id="pageIndicator">Página —</span></div><p id="resultCaption">Selecione sua linha.</p>
<div class="history-tools"><button id="showTrash" type="button" aria-pressed="false">Lixeira local <span id="trashCount">0</span></button><span id="historyScope">Registros da operadora</span></div>
<form id="searchForm" class="search-form" role="search"><label class="sr-only" for="numberSearch">Buscar por número</label><input id="numberSearch" name="number" type="search" inputmode="tel" maxlength="40" placeholder="Buscar telefone"><button id="clearSearch" class="clear-search" type="button" hidden aria-label="Limpar busca">×</button><button class="search-button" type="submit" aria-label="Buscar">⌕</button></form>
<p id="status" class="status-message" role="status" aria-live="polite"></p>
<div id="calls" class="calls-list" aria-label="Histórico de ligações" aria-busy="false"></div>
<nav class="pagination" aria-label="Paginação do histórico"><button id="previousPage" type="button" disabled>←</button><span id="paginationSummary">Página 1</span><button id="nextPage" type="button" disabled>→</button></nav>
</aside>
<div class="central-main">
<details class="dialer-drawer" open><summary><span>☎ Telefone da linha <small id="extensionLabel">Ramal configurado</small></span><span>Discador e controles</span></summary>
            <section id="webphonePanel" class="webphone-panel" data-phone-state="disabled" aria-labelledby="webphoneTitle">
                <div class="phone-overview">
                    <p class="eyebrow">Telefone Api4Com</p>
                    <h2 id="webphoneTitle">Ligações pelo navegador</h2>
                    <div class="live-call-identity"><span id="liveProvider">Api4Com</span><strong id="liveNumber">Pronto para uma nova conversa</strong><small id="liveCallMessage" role="status" aria-live="polite">Ative o telefone para começar.</small></div>
                    <p>Use o ramal configurado desta linha para realizar chamadas de saída. Ative o telefone e permita o acesso ao microfone.</p>
                    <div class="phone-status">
                        <span class="phone-status-dot" aria-hidden="true"></span>
                        <strong id="webphoneStatus" role="status" aria-live="polite">Telefone desativado</strong>
                        <span id="callTimer">00:00</span>
                    </div>
                    <button id="activatePhoneButton" class="activate-phone-button" type="button">Ativar telefone</button>
                </div>

                <div class="phone-controls">
                    <label for="webphoneNumber">Número com DDD</label>
                    <input
                        id="webphoneNumber"
                        class="phone-display"
                        name="webphoneNumber"
                        type="tel"
                        inputmode="tel"
                        autocomplete="off"
                        maxlength="24"
                        placeholder="(11) 99999-9999"
                        aria-describedby="webphoneError"
                    >
                    <p id="webphoneError" class="phone-error" role="alert" aria-live="assertive"></p>

                    <div id="dialpad" class="dialpad" aria-label="Teclado de discagem">
                        <button type="button" data-digit="1" aria-label="Um" disabled>1</button>
                        <button type="button" data-digit="2" aria-label="Dois" disabled>2</button>
                        <button type="button" data-digit="3" aria-label="Três" disabled>3</button>
                        <button type="button" data-digit="4" aria-label="Quatro" disabled>4</button>
                        <button type="button" data-digit="5" aria-label="Cinco" disabled>5</button>
                        <button type="button" data-digit="6" aria-label="Seis" disabled>6</button>
                        <button type="button" data-digit="7" aria-label="Sete" disabled>7</button>
                        <button type="button" data-digit="8" aria-label="Oito" disabled>8</button>
                        <button type="button" data-digit="9" aria-label="Nove" disabled>9</button>
                        <button type="button" data-digit="*" aria-label="Asterisco" disabled>*</button>
                        <button type="button" data-digit="0" aria-label="Zero" disabled>0</button>
                        <button type="button" data-digit="#" aria-label="Cerquilha" disabled>#</button>
                    </div>

                    <div class="phone-actions">
                        <button id="callButton" class="call-button" type="button" disabled>Ligar</button>
                        <button id="hangupButton" class="hangup-button" type="button" disabled>Desligar</button>
                        <button id="muteButton" class="mute-button" type="button" aria-pressed="false" title="Silenciar ou reativar o microfone" disabled>Silenciar</button>
                    </div>
                    <div class="advanced-call-actions">
                        <button id="holdButton" type="button" aria-pressed="false" title="Colocar em espera ou retomar a chamada" disabled><span aria-hidden="true">Ⅱ</span> Em espera</button>
                        <button id="transferButton" type="button" title="Transferir a chamada para outro ramal" disabled><span aria-hidden="true">↗</span> Transferir</button>
                        <button id="copyDialNumber" type="button"><span aria-hidden="true">▣</span> Copiar número</button>
                    </div>
                </div>
            </section>


<div class="dialer-tools"><button id="backspaceNumber" type="button">⌫ Apagar dígito</button><button id="clearNumber" type="button">Limpar número</button></div>
</details>
<div id="conversationEmpty" class="conversation-empty"><span>✧</span><h2>Uma conversa, todo o contexto.</h2><p>Selecione uma ligação no histórico para ouvir o áudio e explorar os insights.</p></div>
<section id="audioDialog" class="conversation-audio" aria-labelledby="audioTitle" hidden>
<div class="conversation-heading"><div><p class="eyebrow">GRAVAÇÃO DA CONVERSA</p><h2 id="audioTitle">Ouvir ligação</h2><p id="audioSubtitle"></p></div><span class="private-label">● Via servidor local</span></div>
<audio id="audioPlayer" controls preload="none">Seu navegador não suporta reprodução de áudio.</audio><p id="audioNotice" class="dialog-note">Você pode ouvir a gravação antes de gerar a análise.</p>
<button id="conversationAnalyze" class="primary-button" type="button">✦ Gerar análise</button>
<div class="conversation-tools"><button id="redialSelected" type="button">↗ Ligar novamente</button><button id="copySelected" type="button">Copiar número</button><button id="showCallDetails" type="button" aria-expanded="false">Detalhes da chamada</button></div>
<dl id="callDetails" class="call-details" hidden></dl>
</section>
<section id="analysisDialog" class="conversation-analysis" aria-labelledby="analysisTitle" hidden>
<h2 id="analysisTitle" class="sr-only">Análise da ligação</h2><p id="analysisSubtitle" class="sr-only"></p>
<div class="conversation-tabs" role="tablist" aria-label="Informações da ligação"><button type="button" role="tab" aria-selected="true" data-conversation-tab="summary">Resumo</button><button type="button" role="tab" aria-selected="false" tabindex="-1" data-conversation-tab="analysis">Análise completa</button><button type="button" role="tab" aria-selected="false" tabindex="-1" data-conversation-tab="transcript">Transcrição</button></div>
<div id="conversationSummary" class="conversation-prose" data-conversation-pane="summary"></div>
<div id="analysisContent" class="analysis-content" aria-live="polite" data-conversation-pane="analysis" hidden></div>
<div id="conversationTranscript" class="conversation-prose" data-conversation-pane="transcript" hidden></div>
<button id="reanalyzeButton" class="secondary-button" type="button" hidden>Analisar novamente <span>pode gerar novo custo</span></button>
</section>
</div></div><div id="centralToast" class="premium-toast" role="status" aria-live="polite" hidden></div></dialog>

<dialog id="settingsDialog" class="settings-dialog" aria-labelledby="settingsTitle">
<header class="central-header"><div><small>CONFIGURAÇÃO LOCAL</small><h2 id="settingsTitle">Suas contas Api4Com</h2></div><button type="button" data-close="settingsDialog" aria-label="Fechar configurações">×</button></header>
<form id="accountSettings">
<p>Os tokens ficam no servidor. Deixe o campo vazio para manter a credencial atual.</p>
<label>Conta<select name="account"><option value="1">Linha 1</option><option value="2">Linha 2</option></select></label>
<div class="account-options"><label><input name="enabled" type="checkbox" checked> Conta ativa</label><label><input name="primary" type="checkbox"> Conta principal</label></div>
<p class="settings-warning">Provedor: Api4Com. A outra conta pode ser selecionada como alternativa antes de ligar. Não repetimos chamadas automaticamente.</p>
<div class="settings-row"><label>Nome da linha<input name="name" maxlength="60" required></label><label>Cor<input name="color" type="color" value="#0b9f77" required></label></div>
<label>URL da API<input name="base_url" type="url" value="https://api.api4com.com/api/v1" required></label>
<label>Token da conta<input name="token" type="password" autocomplete="new-password" placeholder="Vazio mantém o token salvo"></label>
<label>Ramal para o discador<input name="extension" inputmode="numeric" maxlength="10" value="1000" required></label>
<p class="settings-warning">A segunda conta precisa de seu próprio token e ramal. A configuração de IA existente no .env permanece inalterada.</p>
<p id="settingsNotice" role="status" aria-live="polite"></p>
<button type="submit" class="primary-button">Salvar configuração</button>
<section class="connection-test"><button id="testConnection" type="button">Testar conexão salva</button><p id="connectionTestResult" role="status" aria-live="polite">O teste verifica as credenciais já salvas e o ramal, sem realizar ligações.</p></section>
</form></dialog>
<dialog id="transferDialog" class="settings-dialog operation-dialog" aria-labelledby="transferTitle">
<header class="central-header"><div><small>CONTINUIDADE DO ATENDIMENTO</small><h2 id="transferTitle">Transferir chamada</h2></div><button type="button" data-close="transferDialog" aria-label="Fechar transferência">×</button></header>
<form id="transferForm"><p>A chamada será encaminhada ao ramal escolhido na mesma conta. Confirme o destino antes de continuar.</p><label>Ramal de destino<input id="transferExtension" name="extension" list="extensionSuggestions" inputmode="numeric" pattern="[0-9]{1,10}" maxlength="10" required autocomplete="off" placeholder="Ex.: 1001"></label><datalist id="extensionSuggestions"></datalist><p id="extensionHint">Você pode digitar o ramal ou escolher uma sugestão.</p><div class="transfer-preview">Destino: <strong id="transferPreview">—</strong></div><p id="transferNotice" role="status" aria-live="polite"></p><div class="operation-buttons"><button type="button" data-close="transferDialog">Cancelar</button><button id="confirmTransfer" type="submit" class="primary-button">Confirmar transferência</button></div></form>
</dialog>
<dialog id="trashDialog" class="settings-dialog operation-dialog" aria-labelledby="trashTitle">
<header class="central-header"><div><small>EXCLUSÃO REVERSÍVEL</small><h2 id="trashTitle">Mover para a lixeira?</h2></div><button type="button" data-close="trashDialog" aria-label="Cancelar exclusão">×</button></header>
<form id="trashForm"><p id="trashDescription"></p><p>Somente este registro será ocultado do histórico local desta conta. A gravação, a análise e os dados na Api4Com serão preservados. Você poderá restaurá-lo pela lixeira.</p><label>Motivo (opcional)<textarea id="trashReason" maxlength="500" rows="3" placeholder="Ex.: número incorreto conferido com o cliente"></textarea></label><p id="trashNotice" role="status" aria-live="polite"></p><div class="operation-buttons"><button type="button" data-close="trashDialog">Cancelar</button><button id="confirmTrash" type="submit" class="danger-button">Mover para a lixeira</button></div></form>
</dialog>
<template id="loadingTemplate"><div class="call-row skeleton-row" aria-hidden="true"><span></span><span></span><span></span></div></template>
<script src="assets/vendor/libwebphone.js?v=5" defer></script><script type="module" src="assets/webphone.js?v=20260914-premium"></script><script src="assets/app.js?v=20260914-premium" defer></script>
</body></html>
