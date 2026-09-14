(() => {
    'use strict';

    const state = {
        page: 1,
        number: '',
        loading: false,
        calls: [],
        meta: {},
        controller: null,
        analysisCallId: null,
        analyzing: false,
        account: '1', accounts: [], selectedCall: null, selection: 0, saving: false,
        trashMode: false, mutating: false, diagnosing: false, trashCall: null,
    };

    const elements = {
        searchForm: document.getElementById('searchForm'),
        numberSearch: document.getElementById('numberSearch'),
        clearSearch: document.getElementById('clearSearch'),
        refreshButton: document.getElementById('refreshButton'),
        calls: document.getElementById('calls'),
        status: document.getElementById('status'),
        totalCalls: document.getElementById('totalCalls'),
        lastUpdate: document.getElementById('lastUpdate'),
        resultCaption: document.getElementById('resultCaption'),
        pageIndicator: document.getElementById('pageIndicator'),
        previousPage: document.getElementById('previousPage'),
        nextPage: document.getElementById('nextPage'),
        paginationSummary: document.getElementById('paginationSummary'),
        connectionPill: document.getElementById('connectionPill'),
        connectionText: document.getElementById('connectionText'),
        audioDialog: document.getElementById('audioDialog'),
        audioTitle: document.getElementById('audioTitle'),
        audioSubtitle: document.getElementById('audioSubtitle'),
        audioPlayer: document.getElementById('audioPlayer'),
        analysisDialog: document.getElementById('analysisDialog'),
        analysisSubtitle: document.getElementById('analysisSubtitle'),
        analysisContent: document.getElementById('analysisContent'),
        reanalyzeButton: document.getElementById('reanalyzeButton'),
        loadingTemplate: document.getElementById('loadingTemplate'),
    };

    function node(tag, className = '', text = '') {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== '') element.textContent = String(text);
        return element;
    }

    function setConnection(kind, text) {
        elements.connectionPill.classList.remove('is-online', 'is-error');
        if (kind) elements.connectionPill.classList.add(kind);
        elements.connectionText.textContent = text;
    }

    function setStatus(message = '', isError = false) {
        elements.status.textContent = message;
        elements.status.classList.toggle('is-error', isError);
    }

    function setLoading(loading) {
        state.loading = loading;
        elements.calls.setAttribute('aria-busy', String(loading));
        elements.refreshButton.disabled = loading;
        elements.numberSearch.disabled = loading;
        elements.searchForm.querySelector('.search-button').disabled = loading;
        renderPagination();
    }

    async function requestJson(url, options = {}) {
        if (!url.includes('accounts.php') && !url.includes('settings.php') && !/[?&]account=/.test(url)) url += (url.includes('?') ? '&' : '?') + 'account=' + encodeURIComponent(state.account);
        const response = await fetch(url, {
            headers: { Accept: 'application/json', ...(options.headers || {}) },
            ...options,
        });

        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('O servidor retornou uma resposta inválida.');
        }

        if (!response.ok || !payload || payload.ok !== true) {
            const apiError = new Error(payload?.error?.message || 'Não foi possível concluir a solicitação.');
            apiError.code = payload?.error?.code || 'REQUEST_FAILED';
            throw apiError;
        }

        return payload.data;
    }

    async function loadCalls({ keepContent = false } = {}) {
        if (state.controller) state.controller.abort();
        const controller = new AbortController();
        state.controller = controller;
        setLoading(true);
        setStatus('');
        setConnection('', 'Atualizando');

        if (!keepContent) renderLoading();

        const params = new URLSearchParams({ page: String(state.page) });
        if (state.number) params.set('number', state.number);

        try {
            const endpoint = state.trashMode ? 'api/trash.php?' : 'api/calls.php?';
            const result = await requestJson(endpoint + params.toString(), {
                signal: controller.signal,
            });
            if (controller !== state.controller) return;

            state.calls = Array.isArray(result.data) ? result.data : [];
            state.meta = result.meta || {};
            const returnedPage = Number(state.meta.currentPage);
            if (Number.isInteger(returnedPage) && returnedPage > 0) state.page = returnedPage;
            document.getElementById('trashCount').textContent = String(state.trashMode ? (result.count || 0) : (state.meta.trashCount || 0));
            renderCalls();
            updateSummary();
            setConnection(state.trashMode ? '' : 'is-online', state.trashMode ? 'Lixeira local' : 'Api4Com online');
            setStatus(state.trashMode ? 'Lixeira local: você pode restaurar estes registros.' : (state.meta.hiddenOnPage ? state.meta.hiddenOnPage + ' registro(s) desta página estão na lixeira. O total é o da operadora.' : (state.number ? 'Filtro aplicado: ' + state.number : '')));
        } catch (error) {
            if (error.name === 'AbortError') return;
            if (controller !== state.controller) return;
            setConnection('is-error', 'Falha na conexão');
            setStatus(error.message, true);
            if (!keepContent || state.calls.length === 0) renderError(error.message);
        } finally {
            if (controller === state.controller) {
                setLoading(false);
            }
        }
    }

    function renderLoading() {
        const fragment = document.createDocumentFragment();
        for (let index = 0; index < 6; index += 1) {
            fragment.append(elements.loadingTemplate.content.cloneNode(true));
        }
        elements.calls.replaceChildren(fragment);
        elements.resultCaption.textContent = 'Carregando dados da Api4Com…';
    }

    function renderError(message) {
        const wrapper = node('div', 'error-state');
        const content = node('div');
        content.append(
            node('div', 'empty-state-icon', '!'),
            node('h3', '', 'Não foi possível carregar o histórico'),
            node('p', '', message)
        );
        const retry = node('button', 'search-button retry-button', 'Tentar novamente');
        retry.type = 'button';
        retry.addEventListener('click', () => loadCalls());
        content.append(retry);
        wrapper.append(content);
        elements.calls.replaceChildren(wrapper);
        elements.resultCaption.textContent = 'Falha ao consultar a Api4Com';
    }

    function renderCalls() {
        if (state.calls.length === 0) {
            const wrapper = node('div', 'empty-state');
            const content = node('div');
            content.append(
                node('div', 'empty-state-icon', '⌕'),
                node('h3', '', state.number ? 'Nenhuma ligação encontrada' : 'Ainda não há ligações'),
                node('p', '', state.number
                    ? 'Tente buscar usando somente os dígitos ou revise o número informado.'
                    : 'Quando houver chamadas na Api4Com, elas aparecerão aqui.')
            );
            wrapper.append(content);
            elements.calls.replaceChildren(wrapper);
            return;
        }

        const fragment = document.createDocumentFragment();
        state.calls.forEach((call) => fragment.append(createCallRow(call)));
        elements.calls.replaceChildren(fragment);
    }

    function createCallRow(call) {
        const row = node('article', 'call-row');
        row.dataset.callId = String(call.id || '');
        row.dataset.direction = isInbound(call) ? 'inbound' : 'outbound';
        const accountName = state.accounts.find(a => String(a.id) === state.account)?.name || 'Api4Com';
        const type = isInbound(call) ? 'Recebida' : 'Realizada';
        const heading = node('div', 'history-call-kind', (isInbound(call) ? '↙ ' : '↗ ') + type + ' · ' + accountName);
        row.append(heading);

        const date = formatDateParts(call.started_at);
        row.append(createCell('Data e hora', date.date, date.time));

        const from = createCell('Origem', call.from || 'Não informado', callerName(call));
        from.classList.add('phone-cell');
        const icon = node('span', 'direction-icon', isInbound(call) ? '↙' : '↗');
        icon.setAttribute('aria-hidden', 'true');
        from.querySelector('.cell-primary').prepend(icon);
        row.append(from);

        const destination = createCell('Destino', call.to || 'Não informado', call.bina ? `Bina: ${call.bina}` : 'Número chamado');
        destination.classList.add('phone-cell');
        row.append(destination);

        row.append(createCell('Duração', formatDuration(call.duration), formatMoney(call.call_price)));

        const statusCell = node('div', 'cell');
        statusCell.append(node('span', 'cell-label', 'Status'));
        const badge = node('span', `status-badge ${statusClass(call.hangup_cause)}`, statusLabel(call.hangup_cause));
        statusCell.append(badge);
        row.append(statusCell);

        const actions = node('div', 'row-actions');
        if (call.has_recording) {
            const play = actionButton('▶', 'Ver ligação', 'play');
            play.setAttribute('aria-label', `Ouvir ligação de ${call.from || 'origem desconhecida'}`);
            const analyze = actionButton('✦', 'Analisar', 'analyze', true);
            analyze.setAttribute('aria-label', `Analisar ligação de ${call.from || 'origem desconhecida'}`);
            actions.append(play, analyze);
        } else {
            actions.append(actionButton('↗', 'Ver detalhes', 'play'), node('span', 'no-recording', 'Sem gravação'));
        }
        actions.append(state.trashMode ? actionButton('↶', 'Restaurar', 'restore') : actionButton('×', 'Excluir', 'trash'));
        row.append(actions);

        return row;
    }

    function createCell(label, primary, secondary = '') {
        const cell = node('div', 'cell');
        cell.append(node('span', 'cell-label', label), node('span', 'cell-primary', primary || '—'));
        if (secondary) cell.append(node('span', 'cell-secondary', secondary));
        return cell;
    }

    function actionButton(icon, label, action, ai = false) {
        const button = node('button', `action-button${ai ? ' is-ai' : ''}`);
        button.type = 'button';
        button.dataset.action = action;
        const symbol = node('span', '', icon);
        symbol.setAttribute('aria-hidden', 'true');
        button.append(symbol, node('span', '', label));
        return button;
    }

    function callerName(call) {
        const name = [call.first_name, call.last_name].filter(Boolean).join(' ').trim();
        return name || call.email || (isInbound(call) ? 'Ligação recebida' : 'Ramal de origem');
    }

    function isInbound(call) {
        return String(call.call_type || '').toLowerCase().includes('in');
    }

    function statusLabel(value) {
        const labels = {
            NORMAL_CLEARING: 'Concluída',
            SUCCESS: 'Concluída',
            ANSWER: 'Atendida',
            USER_BUSY: 'Ocupado',
            NO_ANSWER: 'Não atendida',
            NO_USER_RESPONSE: 'Sem resposta',
            ORIGINATOR_CANCEL: 'Cancelada',
            CALL_REJECTED: 'Rejeitada',
            UNALLOCATED_NUMBER: 'Número inválido',
            NORMAL_TEMPORARY_FAILURE: 'Falha temporária',
        };
        const key = String(value || '').toUpperCase();
        if (labels[key]) return labels[key];
        if (!key) return 'Não informado';
        return key.toLowerCase().replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase());
    }

    function statusClass(value) {
        const key = String(value || '').toUpperCase();
        if (['NORMAL_CLEARING', 'SUCCESS', 'ANSWER'].includes(key)) return '';
        if (['USER_BUSY', 'NO_ANSWER', 'NO_USER_RESPONSE', 'ORIGINATOR_CANCEL'].includes(key)) return 'is-warning';
        return key ? 'is-error' : 'is-warning';
    }

    function updateSummary() {
        const total = Number(state.meta.totalItemCount || 0);
        const current = Number(state.meta.currentPage || state.page || 1);
        const pages = Number(state.meta.totalPageCount || 0);
        elements.totalCalls.textContent = new Intl.NumberFormat('pt-BR').format(total);
        elements.lastUpdate.textContent = `Atualizado às ${new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(new Date())}`;
        elements.resultCaption.textContent = total === 1 ? '1 ligação encontrada' : `${new Intl.NumberFormat('pt-BR').format(total)} ligações encontradas`;
        elements.pageIndicator.textContent = pages > 0 ? `Página ${current} de ${pages}` : 'Sem páginas';
        elements.paginationSummary.textContent = pages > 0 ? `Página ${current} de ${pages}` : 'Nenhum resultado';
        renderPagination();
    }

    function renderPagination() {
        const current = Number(state.meta.currentPage || state.page || 1);
        const pages = Number(state.meta.totalPageCount || 0);
        elements.previousPage.disabled = state.loading || current <= 1;
        elements.nextPage.disabled = state.loading || pages === 0 || current >= pages;
    }

    function formatDateParts(value) {
        const date = new Date(value);
        if (!value || Number.isNaN(date.getTime())) return { date: 'Data não informada', time: '' };
        return {
            date: new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(date),
            time: new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' }).format(date),
        };
    }

    function formatDuration(value) {
        const total = Math.max(0, Number(value) || 0);
        const minutes = Math.floor(total / 60);
        const seconds = Math.floor(total % 60);
        if (minutes >= 60) {
            const hours = Math.floor(minutes / 60);
            return `${hours}h ${String(minutes % 60).padStart(2, '0')}min`;
        }
        return `${minutes}:${String(seconds).padStart(2, '0')}`;
    }

    function formatMoney(value) {
        if (value === null || value === undefined || value === '') return 'Custo pendente';
        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
    }

    function showDialog(dialog) {
        if (dialog.tagName !== 'DIALOG') { dialog.hidden = false; return; }
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
    }

    function closeDialog(dialog) {
        if (dialog.id === 'settingsDialog' && (state.saving || state.diagnosing)) return;
        if (dialog.id === 'trashDialog' && state.mutating) return;
        if (dialog.id === 'transferDialog' && window.VoxPhone?.getState()?.transferPending) return;
        if (dialog.tagName !== 'DIALOG') { dialog.hidden = true; return; }
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
    }

    function openPlayer(call) {
        if (state.analyzing) { setStatus('Aguarde a análise atual antes de selecionar outra conversa.'); return; }
        state.selectedCall = call; state.analysisCallId = call.id;
        const selection = ++state.selection;
        document.getElementById('centralDialog').classList.remove('show-history');
        document.getElementById('mobileHistory').setAttribute('aria-expanded', 'false');
        document.getElementById('conversationEmpty').hidden = true;
        document.getElementById('conversationAnalyze').disabled = !call.has_recording;
        document.getElementById('conversationAnalyze').textContent = '✦ Gerar análise';
        document.getElementById('conversationSummary').textContent = 'Gere a análise para ver o resumo e os próximos passos.';
        document.getElementById('conversationTranscript').textContent = 'A transcrição estará disponível após a análise.';
        elements.analysisContent.replaceChildren(); elements.reanalyzeButton.hidden = true;
        elements.analysisDialog.hidden = false; activateConversationTab('summary');
        const date = formatDateParts(call.started_at);
        elements.audioTitle.textContent = `${call.from || 'Origem'} → ${call.to || 'Destino'}`;
        elements.audioSubtitle.textContent = [date.date, date.time, formatDuration(call.duration)].filter(Boolean).join(' · ');
        elements.audioPlayer.pause(); elements.audioPlayer.removeAttribute('src');
        if (call.has_recording) elements.audioPlayer.src = 'api/audio.php?id=' + encodeURIComponent(call.id) + '&account=' + state.account;
        elements.audioPlayer.hidden = !call.has_recording;
        document.getElementById('audioNotice').textContent = call.has_recording ? 'Você pode ouvir a gravação antes de gerar a análise.' : 'A Api4Com não disponibilizou gravação para esta chamada.';
        showDialog(elements.audioDialog);
        elements.audioPlayer.load();
        requestJson('api/analysis-cache.php?id=' + encodeURIComponent(call.id)).then(result => {
            if (selection !== state.selection || state.analyzing) return;
            if (result.analysis) { renderAnalysis(result.analysis); elements.reanalyzeButton.hidden = false; }
        }).catch(() => {
            if (selection === state.selection) document.getElementById('conversationSummary').textContent = 'Não foi possível carregar a análise salva. Selecione a ligação novamente para tentar.';
        });
        markSelectedCall();
        renderCallDetails(call);
    }

    async function openAnalysis(call, force = false) {
        if (state.analyzing) return;
        if (state.selectedCall?.id !== call.id) openPlayer(call);
        state.selection++;
        state.analysisCallId = call.id;
        state.analyzing = true;
        document.getElementById('conversationAnalyze').disabled = true;
        document.getElementById('conversationAnalyze').textContent = 'Analisando…';
        elements.analysisSubtitle.textContent = `${call.from || 'Origem'} → ${call.to || 'Destino'}`;
        elements.reanalyzeButton.hidden = true;
        renderAnalysisLoading();
        activateConversationTab('analysis');
        if (!elements.analysisDialog.open) showDialog(elements.analysisDialog);

        try {
            const analysis = await requestJson('api/analyze.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ call_id: call.id, force }),
            });
            renderAnalysis(analysis);
            elements.reanalyzeButton.hidden = false;
        } catch (error) {
            renderAnalysisError(error);
            elements.reanalyzeButton.hidden = error.code === 'AI_NOT_CONFIGURED';
        } finally {
            state.analyzing = false;
            document.getElementById('conversationAnalyze').disabled = false;
            document.getElementById('conversationAnalyze').textContent = '✦ Consultar análise';
        }
    }

    function renderAnalysisLoading() {
        const wrapper = node('div', 'analysis-loading');
        const content = node('div');
        content.append(
            node('div', 'ai-orb', '✦'),
            node('h3', '', 'Analisando a conversa'),
            node('p', '', 'A gravação está sendo transcrita e organizada. Isso pode levar alguns instantes.')
        );
        wrapper.append(content);
        elements.analysisContent.replaceChildren(wrapper);
    }

    function renderAnalysisError(error) {
        const wrapper = node('div', 'error-state');
        const content = node('div');
        content.append(
            node('div', 'empty-state-icon', '!'),
            node('h3', '', error.code === 'AI_NOT_CONFIGURED' ? 'IA ainda não configurada' : 'Análise não concluída'),
            node('p', '', error.message)
        );
        if (error.code === 'AI_NOT_CONFIGURED') {
            content.append(node('p', 'cell-secondary', 'Preencha a chave do provedor de IA no arquivo .env para habilitar este recurso.'));
        }
        wrapper.append(content);
        elements.analysisContent.replaceChildren(wrapper);
    }

    function renderAnalysis(analysis) {
        const summaryPanel = document.getElementById('conversationSummary');
        summaryPanel.replaceChildren(node('h3', '', 'Resumo da conversa'), node('p', '', analysis.summary || 'Sem resumo.'));
        summaryPanel.append(listCard('Próximos passos', analysis.next_steps));
        document.getElementById('conversationTranscript').textContent = analysis.transcript || 'Transcrição indisponível.';
        const grid = node('div', 'analysis-grid');

        const summary = analysisCard('Resumo executivo', true);
        summary.append(node('p', '', analysis.summary || 'Sem resumo.'));
        grid.append(summary);

        const diagnosis = analysisCard('Diagnóstico comercial', true);
        const badges = node('div', 'analysis-badges');
        badges.append(
            analysisBadge(`Lead ${humanizeAnalysisValue(analysis.lead_temperature)}`, `lead-${analysis.lead_temperature || 'indefinido'}`),
            analysisBadge(`Etapa: ${humanizeAnalysisValue(analysis.sales_stage)}`),
            analysisBadge(`Sentimento: ${humanizeAnalysisValue(analysis.sentiment)}`, `sentiment ${analysis.sentiment || 'misto'}`)
        );
        diagnosis.append(badges);
        grid.append(diagnosis);

        grid.append(scoreCard(analysis.scores));
        grid.append(listCard('Pontos fortes do vendedor', analysis.strengths));
        grid.append(listCard('Necessidades do cliente', analysis.customer_needs));
        grid.append(listCard('Sinais de compra', analysis.buying_signals));
        grid.append(listCard('Pontos importantes', analysis.key_points));

        grid.append(structuredItemsCard('Oportunidades de melhoria', analysis.improvements, (item) => {
            const block = node('article', 'structured-item');
            block.append(node('h4', '', item.point || 'Ponto de melhoria'));
            block.append(detailLine('Por que importa', item.why_it_matters));
            block.append(detailLine('Ação recomendada', item.recommended_action));
            block.append(quoteBlock(item.example_phrase, 'Exemplo de abordagem'));
            return block;
        }));

        const approach = analysisCard('Abordagem recomendada', true);
        approach.append(node('p', '', analysis.recommended_approach || 'Nenhuma abordagem adicional sugerida.'));
        grid.append(approach);

        grid.append(structuredItemsCard('Objeções e quebra de objeções', analysis.objections, (item) => {
            const block = node('article', 'structured-item objection-item');
            block.append(node('h4', '', item.objection || 'Objeção identificada'));
            block.append(detailLine('Evidência na ligação', item.evidence));
            block.append(detailLine('Estratégia', item.response_strategy));
            block.append(quoteBlock(item.suggested_response, 'Resposta sugerida'));
            return block;
        }, 'Nenhuma objeção identificada.'));

        grid.append(listCard('Perguntas para aprofundar', analysis.discovery_questions, true));
        grid.append(salesScriptCard(analysis.sales_script));
        grid.append(followUpCard(analysis.follow_up_plan));
        grid.append(listCard('Próximos passos', analysis.next_steps));
        grid.append(listCard('Riscos comerciais', analysis.risks));
        grid.append(listCard('Alertas de conformidade', analysis.compliance_alerts, true, 'warning-card'));

        const topics = analysisCard('Assuntos', true);
        const tags = node('div', 'tag-list');
        const topicItems = Array.isArray(analysis.topics) ? analysis.topics : [];
        if (topicItems.length === 0) tags.append(node('span', 'cell-secondary', 'Nenhum assunto destacado.'));
        topicItems.forEach((item) => tags.append(node('span', 'tag', item)));
        topics.append(tags);
        grid.append(topics);

        const transcript = node('details', 'transcript-details');
        transcript.append(node('summary', '', 'Ver transcrição completa'));
        transcript.append(node('p', 'transcript-text', analysis.transcript || 'Transcrição indisponível.'));
        grid.append(transcript);

        if (analysis.analyzed_at) {
            grid.append(node('p', 'analysis-meta', `Analisado em ${formatDateParts(analysis.analyzed_at).date} · Modelo ${analysis.provider_model || 'configurado'}`));
        }

        elements.analysisContent.replaceChildren(grid);
    }

    function analysisBadge(text, extraClass = '') {
        return node('span', `analysis-pill ${extraClass}`.trim(), text);
    }

    function humanizeAnalysisValue(value) {
        const labels = {
            frio: 'frio',
            morno: 'morno',
            quente: 'quente',
            indefinido: 'indefinido',
            contato_inicial: 'contato inicial',
            qualificacao: 'qualificação',
            proposta: 'proposta',
            follow_up: 'follow-up',
            fechamento: 'fechamento',
            sem_avanco: 'sem avanço',
            positivo: 'positivo',
            neutro: 'neutro',
            negativo: 'negativo',
            misto: 'misto'
        };
        return labels[value] || 'indefinido';
    }

    function scoreCard(scores) {
        const card = analysisCard('Pontuação da abordagem', true);
        const labels = {
            overall: 'Desempenho geral',
            opening: 'Abertura',
            discovery: 'Descoberta',
            argumentation: 'Argumentação',
            value_proposition: 'Proposta de valor',
            objection_handling: 'Quebra de objeções',
            closing: 'Fechamento',
            follow_up: 'Follow-up'
        };
        const scoreGrid = node('div', 'score-grid');
        Object.entries(labels).forEach(([key, label]) => {
            const score = Math.max(0, Math.min(10, Number(scores?.[key]) || 0));
            const item = node('div', 'score-item');
            const heading = node('div', 'score-heading');
            heading.append(node('span', '', label), node('strong', '', `${score}/10`));
            const track = node('div', 'score-track');
            const fill = node('span', `score-fill${score < 5 ? ' is-low' : score < 7 ? ' is-medium' : ''}`);
            fill.style.width = `${score * 10}%`;
            track.append(fill);
            item.append(heading, track);
            scoreGrid.append(item);
        });
        card.append(scoreGrid);
        return card;
    }

    function structuredItemsCard(title, values, renderer, emptyMessage = 'Nenhum item identificado.') {
        const card = analysisCard(title, true);
        const list = node('div', 'structured-list');
        const items = Array.isArray(values) ? values : [];
        if (items.length === 0) {
            list.append(node('p', 'cell-secondary', emptyMessage));
        } else {
            items.forEach((item) => list.append(renderer(item || {})));
        }
        card.append(list);
        return card;
    }

    function detailLine(label, value) {
        const line = node('p', 'detail-line');
        line.append(node('strong', '', `${label}: `), document.createTextNode(value || 'Não informado.'));
        return line;
    }

    function quoteBlock(value, label) {
        const wrapper = node('div', 'suggested-copy');
        wrapper.append(node('span', '', label), node('p', '', value || 'Não informado.'));
        return wrapper;
    }

    function salesScriptCard(script) {
        const card = analysisCard('Script sugerido para a próxima conversa', true);
        const sections = [
            ['Abertura', script?.opening],
            ['Proposta de valor', script?.value_pitch],
            ['Quebra de objeções', script?.objection_handling],
            ['Fechamento', script?.closing]
        ];
        const scriptGrid = node('div', 'script-grid');
        sections.forEach(([title, content]) => {
            const section = node('section', 'script-section');
            section.append(node('h4', '', title), node('p', '', content || 'Não informado.'));
            scriptGrid.append(section);
        });
        card.append(scriptGrid);
        return card;
    }

    function followUpCard(plan) {
        const card = analysisCard('Plano de follow-up', true);
        const meta = node('div', 'follow-up-meta');
        meta.append(
            analysisBadge(`Quando: ${plan?.timing || 'não informado'}`),
            analysisBadge(`Canal: ${plan?.channel || 'não informado'}`)
        );
        card.append(meta, detailLine('Objetivo', plan?.objective), quoteBlock(plan?.message, 'Mensagem pronta'));
        return card;
    }

    function analysisCard(title, wide = false) {
        const card = node('section', `analysis-card${wide ? ' is-wide' : ''}`);
        card.append(node('h3', '', title));
        return card;
    }

    function listCard(title, values, wide = false, extraClass = '') {
        const card = analysisCard(title, wide);
        if (extraClass) card.classList.add(extraClass);
        const list = node('ul');
        const items = Array.isArray(values) ? values : [];
        if (items.length === 0) {
            list.append(node('li', '', 'Nenhum item identificado.'));
        } else {
            items.forEach((item) => list.append(node('li', '', item)));
        }
        card.append(list);
        return card;
    }

    function findCall(id) {
        return state.calls.find((call) => String(call.id) === String(id));
    }

    elements.searchForm.addEventListener('submit', (event) => {
        event.preventDefault();
        state.number = elements.numberSearch.value.trim();
        state.page = 1;
        elements.clearSearch.hidden = state.number === '';
        loadCalls();
    });

    elements.numberSearch.addEventListener('input', () => {
        elements.clearSearch.hidden = elements.numberSearch.value === '';
    });

    elements.clearSearch.addEventListener('click', () => {
        elements.numberSearch.value = '';
        elements.clearSearch.hidden = true;
        state.number = '';
        state.page = 1;
        elements.numberSearch.focus();
        loadCalls();
    });

    elements.refreshButton.addEventListener('click', () => loadCalls({ keepContent: state.calls.length > 0 }));

    elements.previousPage.addEventListener('click', () => {
        if (state.page > 1 && !state.loading) {
            state.page -= 1;
            loadCalls();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });

    elements.nextPage.addEventListener('click', () => {
        const pages = Number(state.meta.totalPageCount || 0);
        if (state.page < pages && !state.loading) {
            state.page += 1;
            loadCalls();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });

    elements.calls.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action]');
        if (!button) return;
        const row = button.closest('[data-call-id]');
        const call = row ? findCall(row.dataset.callId) : null;
        if (!call) return;
        if (button.dataset.action === 'trash') { openTrash(call); return; }
        if (button.dataset.action === 'restore') { void restoreCall(call); return; }
        if (button.dataset.action === 'play') openPlayer(call);
        if (button.dataset.action === 'analyze') openAnalysis(call);
    });

    elements.reanalyzeButton.addEventListener('click', () => {
        const call = state.selectedCall;
        if (call) openAnalysis(call, true);
    });

    document.querySelectorAll('[data-close]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = document.getElementById(button.dataset.close);
            if (dialog) closeDialog(dialog);
        });
    });

    [elements.audioDialog, elements.analysisDialog].forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) closeDialog(dialog);
        });
    });

    elements.audioDialog.addEventListener('close', () => {
        elements.audioPlayer.pause();
        elements.audioPlayer.removeAttribute('src');
        elements.audioPlayer.load();
    });

    window.addEventListener('vox:call-ended', () => {
        window.setTimeout(() => loadCalls({ keepContent: true }), 2500);
    });

    const central = document.getElementById('centralDialog');
    const settings = document.getElementById('settingsDialog');
    const settingsForm = document.getElementById('accountSettings');
    function activateConversationTab(name) {
        document.querySelectorAll('[data-conversation-tab]').forEach(tab => {
            const active = tab.dataset.conversationTab === name;
            tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1;
            tab.id = 'conversation-tab-' + tab.dataset.conversationTab;
        });
        document.querySelectorAll('[data-conversation-pane]').forEach(pane => {
            pane.hidden = pane.dataset.conversationPane !== name;
            pane.setAttribute('role', 'tabpanel');
            pane.setAttribute('aria-labelledby', 'conversation-tab-' + pane.dataset.conversationPane);
            document.querySelector('[data-conversation-tab="' + pane.dataset.conversationPane + '"]').setAttribute('aria-controls', pane.id);
        });
    }
    function markSelectedCall() {
        elements.calls.querySelectorAll('[data-call-id]').forEach(row => row.classList.toggle('is-selected', String(row.dataset.callId) === String(state.selectedCall?.id)));
    }
    function updateAccounts(accounts) {
        state.accounts = Array.isArray(accounts) ? accounts : [];
        document.querySelectorAll('[data-line]').forEach(button => {
            const item = state.accounts.find(a => String(a.id) === button.dataset.line);
            button.disabled = !item?.configured || item?.enabled === false;
            if (!item) return;
            button.querySelector('[data-line-name]').textContent = item.name;
            button.dataset.primary = String(item.primary === true);
            button.querySelector('[data-line-status]').textContent = item.enabled === false ? 'Conta desativada' : (item.configured ? (item.primary ? 'Linha principal · Abrir central' : 'Abrir central de ligações') : 'Configure o token para ativar');
            if (/^#[0-9a-f]{6}$/i.test(item.color)) button.style.setProperty('--line', item.color);
        });
    }
    function resetConversation() {
        state.selection++; state.selectedCall = null; state.analysisCallId = null;
        elements.audioPlayer.pause(); elements.audioPlayer.removeAttribute('src'); elements.audioPlayer.load();
        elements.audioDialog.hidden = true; elements.analysisDialog.hidden = true;
        document.getElementById('conversationEmpty').hidden = false;
    }
    async function openAccount(id) {
        if (state.saving || state.mutating || state.diagnosing) return;
        const account = state.accounts.find(a => String(a.id) === String(id));
        if (!account?.configured || account.enabled === false) return;
        if (String(id) === state.account && (window.VoxPhone?.busy() || state.analyzing)) {
            central.showModal(); document.body.style.overflow = 'hidden';
            document.querySelector('.dialer-drawer').open = true; return;
        }
        if (state.analyzing) { document.getElementById('landingNotice').textContent = 'Aguarde a análise em andamento antes de trocar a linha.'; return; }
        if (!window.VoxPhone || !window.VoxPhone.setAccount(String(id))) {
            document.getElementById('landingNotice').textContent = 'Finalize a chamada ou a ativação do telefone antes de trocar a linha.'; return;
        }
        state.account = String(id); state.page = 1; state.number = ''; state.calls = []; state.meta = {}; state.trashMode = false;
        document.getElementById('showTrash').setAttribute('aria-pressed', 'false');
        document.getElementById('historyScope').textContent = 'Registros da operadora';
        document.querySelector('.dialer-drawer').open = true;
        elements.numberSearch.value = ''; elements.clearSearch.hidden = true;
        resetConversation();
        document.getElementById('centralTitle').textContent = account.name;
        document.getElementById('extensionLabel').textContent = 'Ramal ' + account.extension;
        document.getElementById('liveProvider').textContent = 'Api4Com · ' + account.name + ' · Ramal ' + account.extension;
        if (/^#[0-9a-f]{6}$/i.test(account.color)) central.style.setProperty('--line', account.color);
        central.classList.remove('show-history');
        document.getElementById('mobileHistory').setAttribute('aria-expanded','false');
        document.getElementById('landingNotice').textContent = '';
        central.showModal(); document.body.style.overflow = 'hidden';
        loadCalls();
    }
    document.querySelectorAll('[data-line]').forEach(button => button.addEventListener('click', () => openAccount(button.dataset.line)));
    document.getElementById('mobileHistory').addEventListener('click', event => {
        event.currentTarget.setAttribute('aria-expanded', String(central.classList.toggle('show-history')));
    });
    document.getElementById('conversationAnalyze').addEventListener('click', () => { if (state.selectedCall) openAnalysis(state.selectedCall); });
    document.querySelectorAll('[data-conversation-tab]').forEach(tab => {
        tab.addEventListener('click', () => activateConversationTab(tab.dataset.conversationTab));
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
            event.preventDefault();
            const tabs = [...document.querySelectorAll('[data-conversation-tab]')], i = tabs.indexOf(tab);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? 2 : (i + (event.key === 'ArrowRight' ? 1 : 2)) % 3;
            activateConversationTab(tabs[next].dataset.conversationTab); tabs[next].focus();
        });
    });
    central.addEventListener('close', () => {
        elements.audioPlayer.pause(); document.body.style.overflow = '';
        if (window.VoxPhone?.busy()) document.getElementById('landingNotice').textContent = 'A ligação continua ativa. Abra a mesma linha para acessar os controles.';
    });
    elements.audioPlayer.addEventListener('error', () => { if (elements.audioPlayer.getAttribute('src')) document.getElementById('audioNotice').textContent = 'O áudio não carregou. Confira a conexão e selecione a ligação novamente.'; });
    function fillSettings() {
        const item = state.accounts.find(a => String(a.id) === settingsForm.elements.account.value);
        settingsForm.elements.name.value = item?.name || 'Linha ' + settingsForm.elements.account.value;
        settingsForm.elements.color.value = item?.color || '#0b9f77';
        settingsForm.elements.extension.value = item?.extension || '1000';
        settingsForm.elements.base_url.value = item?.base_url || 'https://api.api4com.com/api/v1';
        settingsForm.elements.token.value = '';
        settingsForm.elements.enabled.checked = item?.enabled !== false;
        settingsForm.elements.primary.checked = item?.primary === true;
        document.getElementById('connectionTestResult').textContent = 'Teste as credenciais já salvas e o ramal, sem realizar ligações.';
        document.getElementById('settingsNotice').textContent = '';
    }
    document.getElementById('settingsButton').addEventListener('click', () => { fillSettings(); settings.showModal(); });
    settingsForm.elements.account.addEventListener('change', fillSettings);
    settings.addEventListener('close', () => { settingsForm.elements.token.value = ''; });
    settings.addEventListener('cancel', event => { if (state.saving || state.diagnosing) event.preventDefault(); });
    settingsForm.addEventListener('submit', async event => {
        event.preventDefault();
        const notice = document.getElementById('settingsNotice');
        if (state.analyzing || window.VoxPhone?.busy()) { notice.textContent = 'Finalize a chamada ou a análise antes de alterar a configuração.'; return; }
        if (state.saving || state.diagnosing || !window.VoxPhone?.setConfigurationPending(true)) return;
        state.saving = true;
        const button = settingsForm.querySelector('button[type=submit]'); button.disabled = true;
        try {
            const data = Object.fromEntries(new FormData(settingsForm).entries());
            data.enabled = settingsForm.elements.enabled.checked; data.primary = settingsForm.elements.primary.checked;
            const result = await requestJson('api/settings.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
            updateAccounts(result.accounts);
            window.VoxPhone?.setConfigurationPending(false);
            window.VoxPhone?.setAccount(state.account, true);
            settingsForm.elements.token.value = ''; notice.textContent = 'Configuração salva. A credencial não é exibida por segurança.';
        } catch (error) { notice.textContent = error.message; }
        finally { state.saving = false; window.VoxPhone?.setConfigurationPending(false); button.disabled = false; }
    });
    document.getElementById('backspaceNumber').addEventListener('click', () => {
        const input = document.getElementById('webphoneNumber'); if (!input.disabled) input.value = input.value.slice(0,-1);
    });
    document.getElementById('clearNumber').addEventListener('click', () => {
        const input = document.getElementById('webphoneNumber'); if (!input.disabled) { input.value = ''; input.focus(); }
    });
    let toastTimer = null;
    function toast(message, error = false) {
        const box = document.getElementById('centralToast');
        window.clearTimeout(toastTimer);
        box.textContent = message; box.classList.toggle('is-error', error); box.hidden = false;
        toastTimer = window.setTimeout(() => { box.hidden = true; }, error ? 9000 : 6000);
    }
    function targetNumber(call) {
        return String((isInbound(call) ? call.from : call.to) || call.bina || '').trim();
    }
    async function copyNumber(number) {
        if (!number) { toast('Nenhum número disponível para copiar.', true); return; }
        try { await navigator.clipboard.writeText(number); toast('Número copiado.'); }
        catch { toast('Não foi possível copiar automaticamente. Selecione o número exibido.', true); }
    }
    function renderCallDetails(call) {
        const panel = document.getElementById('callDetails');
        panel.replaceChildren(); panel.hidden = true;
        document.getElementById('showCallDetails').setAttribute('aria-expanded', 'false');
        const account = state.accounts.find(a => String(a.id) === state.account);
        const fields = [
            ['Identificador', call.id], ['Contato', call.contact_name || 'Não identificado pela operadora'],
            ['Número', targetNumber(call)], ['Atendente', callerName(call)],
            ['Tipo', isInbound(call) ? 'Recebida' : 'Realizada'], ['Situação', statusLabel(call.hangup_cause)],
            ['Duração', formatDuration(call.duration)], ['Ramal', call.extension || (isInbound(call) ? call.to : call.from)],
            ['Conta / provedor', (account?.name || state.account) + ' · Api4Com'],
        ];
        fields.forEach(([label,value]) => { panel.append(node('dt','',label),node('dd','',value || 'Não informado')); });
    }
    document.getElementById('showCallDetails').addEventListener('click', event => {
        const panel = document.getElementById('callDetails'); panel.hidden = !panel.hidden;
        event.currentTarget.setAttribute('aria-expanded', String(!panel.hidden));
    });
    document.getElementById('copySelected').addEventListener('click', () => { if (state.selectedCall) void copyNumber(targetNumber(state.selectedCall)); });
    document.getElementById('copyDialNumber').addEventListener('click', () => { void copyNumber(document.getElementById('webphoneNumber').value); });
    document.getElementById('redialSelected').addEventListener('click', () => {
        if (!state.selectedCall) return;
        const number = targetNumber(state.selectedCall);
        if (!window.VoxPhone?.prepareNumber(number)) { toast('Finalize a chamada atual ou confira o número antes de preparar outra ligação.',true); return; }
        const drawer = document.querySelector('.dialer-drawer'); drawer.open = true; drawer.scrollIntoView({block:'start',behavior:'smooth'});
        toast('Número preenchido. Ative o telefone, se necessário, e clique em Ligar.');
    });

    const trashDialog = document.getElementById('trashDialog');
    function canEditHistory() {
        if (state.analyzing || state.mutating || window.VoxPhone?.busy()) {
            toast('Finalize a chamada, análise ou alteração em andamento antes de editar o histórico.',true); return false;
        }
        return true;
    }
    function openTrash(call) {
        if (!canEditHistory()) return;
        state.trashCall = call;
        document.getElementById('trashDescription').textContent = 'Ligação ' + (targetNumber(call) || call.id) + ' · ' + formatDateParts(call.started_at).date;
        document.getElementById('trashReason').value = ''; document.getElementById('trashNotice').textContent = '';
        trashDialog.showModal();
    }
    trashDialog.addEventListener('cancel', event => { if (state.mutating) event.preventDefault(); });
    document.getElementById('trashForm').addEventListener('submit', async event => {
        event.preventDefault();
        if (!state.trashCall || !canEditHistory()) return;
        state.mutating = true;
        const id = state.trashCall.id, button = document.getElementById('confirmTrash');
        button.disabled = true; button.textContent = 'Movendo…';
        try {
            await requestJson('api/trash.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'trash',call_id:id,confirmed:true,reason:document.getElementById('trashReason').value.trim()})});
            if (state.selectedCall?.id === id) resetConversation();
            trashDialog.close(); await loadCalls();
            toast('Registro movido para a lixeira local. Gravação e análise preservadas.');
        } catch (error) { document.getElementById('trashNotice').textContent = error.message; }
        finally { state.mutating = false; button.disabled = false; button.textContent = 'Mover para a lixeira'; }
    });
    async function restoreCall(call) {
        if (!canEditHistory()) return;
        state.mutating = true;
        try {
            await requestJson('api/trash.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'restore',call_id:call.id,confirmed:true})});
            if (state.selectedCall?.id === call.id) resetConversation();
            await loadCalls(); toast('Registro restaurado ao histórico.');
        } catch (error) { toast(error.message,true); }
        finally { state.mutating = false; }
    }
    document.getElementById('showTrash').addEventListener('click', () => {
        if (state.analyzing || state.mutating) { toast('Aguarde a operação em andamento.',true); return; }
        state.trashMode = !state.trashMode; state.page = 1; state.number = '';
        elements.numberSearch.value = ''; elements.clearSearch.hidden = true; resetConversation();
        document.getElementById('showTrash').setAttribute('aria-pressed', String(state.trashMode));
        document.getElementById('historyScope').textContent = state.trashMode ? 'Somente exclusões locais' : 'Registros da operadora';
        loadCalls();
    });

    const transferDialog = document.getElementById('transferDialog');
    function updatePhoneState(detail) {
        const data = detail || window.VoxPhone?.getState?.();
        if (!data) return;
        const hold = document.getElementById('holdButton'), transfer = document.getElementById('transferButton');
        hold.disabled = !data.canHold; hold.textContent = data.holdPending ? 'Confirmando espera…' : (data.held ? '▶ Retomar' : 'Ⅱ Em espera');
        hold.setAttribute('aria-pressed', String(!!data.held));
        transfer.disabled = !data.canTransfer; transfer.textContent = data.transferPending ? 'Transferindo…' : '↗ Transferir';
        document.getElementById('liveNumber').textContent = data.phone || 'Pronto para uma nova conversa';
        document.getElementById('liveCallMessage').textContent = data.message || (data.held ? 'Chamada em espera' : (data.state === 'active' ? 'Chamada conectada' : ''));
        document.querySelector('.dialer-drawer').dataset.callState = data.held ? 'held' : data.state;
        if (transferDialog.open && !data.canTransfer && !data.transferPending) transferDialog.close();
    }
    window.addEventListener('vox:phone-state', event => updatePhoneState(event.detail));
    updatePhoneState();
    document.getElementById('holdButton').addEventListener('click', async () => {
        const result = await window.VoxPhone.toggleHold(); toast(result.message, !result.success);
    });
    document.getElementById('transferButton').addEventListener('click', async () => {
        if (!window.VoxPhone?.getState()?.canTransfer) { toast('A transferência está disponível durante uma chamada conectada.',true); return; }
        document.getElementById('transferExtension').value = ''; document.getElementById('transferPreview').textContent = '—';
        document.getElementById('transferNotice').textContent = ''; document.getElementById('extensionHint').textContent = 'Buscando ramais desta conta…';
        document.getElementById('extensionSuggestions').replaceChildren(); transferDialog.showModal();
        const selectedAccount = state.account;
        try {
            const result = await requestJson('api/extensions.php');
            if (!transferDialog.open || selectedAccount !== state.account) return;
            (result.extensions || []).forEach(item => document.getElementById('extensionSuggestions').append(new Option(item.name || item.number, item.number)));
            document.getElementById('extensionHint').textContent = result.extensions?.length ? 'Digite ou selecione um ramal desta conta.' : 'Nenhum ramal listado. Você pode digitar o destino.';
        } catch { if (transferDialog.open) document.getElementById('extensionHint').textContent = 'Não foi possível listar os ramais. Digite um ramal conhecido desta conta.'; }
    });
    document.getElementById('transferExtension').addEventListener('input', event => {
        document.getElementById('transferPreview').textContent = event.target.value.trim() || '—';
    });
    transferDialog.addEventListener('cancel', event => { if (window.VoxPhone?.getState()?.transferPending) event.preventDefault(); });
    document.getElementById('transferForm').addEventListener('submit', async event => {
        event.preventDefault(); const button = document.getElementById('confirmTransfer');
        if (button.disabled) return;
        button.disabled = true; document.getElementById('transferNotice').textContent = 'Solicitando transferência. Aguardando confirmação da telefonia…';
        try {
            const result = await window.VoxPhone.transfer(document.getElementById('transferExtension').value.trim());
            if (result.success) { transferDialog.close(); toast(result.message); }
            else { document.getElementById('transferNotice').textContent = result.message; toast(result.message,true); }
        } catch { document.getElementById('transferNotice').textContent = 'Não foi possível confirmar a transferência.'; }
        finally { button.disabled = false; }
    });
    document.getElementById('testConnection').addEventListener('click', async () => {
        if (state.diagnosing || state.saving || window.VoxPhone?.busy()) return;
        state.diagnosing = true;
        const button = document.getElementById('testConnection'), output = document.getElementById('connectionTestResult');
        const id = settingsForm.elements.account.value;
        button.disabled = true; settingsForm.elements.account.disabled = true;
        settingsForm.querySelector('button[type=submit]').disabled = true;
        output.textContent = 'Testando conexão e ramal…';
        try {
            const result = await requestJson('api/connection-test.php?account=' + encodeURIComponent(id), {method:'POST',headers:{'Content-Type':'application/json'},body:'{}'});
            output.textContent = result.message + (result.extension ? ' Ramal ' + result.extension + '.' : '');
            output.dataset.status = result.connected ? 'success' : 'error';
        } catch (error) { output.textContent = error.message; output.dataset.status = 'error'; }
        finally { state.diagnosing = false; button.disabled = false; settingsForm.elements.account.disabled = false; settingsForm.querySelector('button[type=submit]').disabled = false; }
    });

    requestJson('api/accounts.php').then(result => updateAccounts(result.accounts)).catch(error => {
        document.getElementById('landingNotice').textContent = error.message;
        document.querySelectorAll('[data-line-status]').forEach(item => { item.textContent = 'Configuração indisponível'; });
    });
})();
