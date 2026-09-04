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
            const result = await requestJson(`api/calls.php?${params.toString()}`, {
                signal: controller.signal,
            });
            if (controller !== state.controller) return;

            state.calls = Array.isArray(result.data) ? result.data : [];
            state.meta = result.meta || {};
            renderCalls();
            updateSummary();
            setConnection('is-online', 'Api4Com online');
            setStatus(state.number ? `Filtro aplicado: ${state.number}` : '');
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
            const play = actionButton('▶', 'Ouvir', 'play');
            play.setAttribute('aria-label', `Ouvir ligação de ${call.from || 'origem desconhecida'}`);
            const analyze = actionButton('✦', 'Analisar', 'analyze', true);
            analyze.setAttribute('aria-label', `Analisar ligação de ${call.from || 'origem desconhecida'}`);
            actions.append(play, analyze);
        } else {
            actions.append(node('span', 'no-recording', 'Sem gravação'));
        }
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
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
    }

    function closeDialog(dialog) {
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
    }

    function openPlayer(call) {
        const date = formatDateParts(call.started_at);
        elements.audioTitle.textContent = `${call.from || 'Origem'} → ${call.to || 'Destino'}`;
        elements.audioSubtitle.textContent = [date.date, date.time, formatDuration(call.duration)].filter(Boolean).join(' · ');
        elements.audioPlayer.src = `api/audio.php?id=${encodeURIComponent(call.id)}`;
        showDialog(elements.audioDialog);
        elements.audioPlayer.play().catch(() => {});
    }

    async function openAnalysis(call, force = false) {
        if (state.analyzing) return;
        state.analysisCallId = call.id;
        state.analyzing = true;
        elements.analysisSubtitle.textContent = `${call.from || 'Origem'} → ${call.to || 'Destino'}`;
        elements.reanalyzeButton.hidden = true;
        renderAnalysisLoading();
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
        if (button.dataset.action === 'play') openPlayer(call);
        if (button.dataset.action === 'analyze') openAnalysis(call);
    });

    elements.reanalyzeButton.addEventListener('click', () => {
        const call = findCall(state.analysisCallId);
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

    loadCalls();
})();
