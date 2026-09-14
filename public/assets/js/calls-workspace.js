(function () {
    'use strict';
    const dialog = document.getElementById('cw-dialog');
    if (!dialog || dialog.dataset.initialized) return;
    dialog.dataset.initialized = '1';
    const api = dialog.dataset.api;
    const history = dialog.querySelector('[data-cw-history]');
    const detail = dialog.querySelector('[data-cw-detail]');
    const picker = dialog.querySelector('[data-cw-switch]');
    const search = dialog.querySelector('[data-cw-search]');
    const notice = dialog.querySelector('[data-cw-notice]');
    let accounts = {}, account = 'api4com', leadId = 0, number = '', selected = null;
    let rows = [], sequence = 0, request = null, opener = null, previousOverflow = '', leadSequence = 0, leadTimer;
    const analyzing = new Set();
    function el(tag, cls, text) { const item = document.createElement(tag); if (cls) item.className = cls; if (text !== undefined) item.textContent = text; return item; }
    function notify(message, error) { notice.textContent = message; notice.classList.toggle('is-error', !!error); notice.hidden = false; }
    function stopAudio() { detail.querySelectorAll('audio').forEach(audio => { audio.pause(); audio.removeAttribute('src'); audio.load(); }); }
    function loading(container, message) { container.replaceChildren(el('div', 'cw-loading', message)); }
    function empty(container, title, message) { const box = el('div', 'cw-empty'); box.append(el('span', 'cw-empty-icon', '☎'), el('h3', '', title), el('p', '', message)); container.replaceChildren(box); }
    function time(seconds) { seconds = Math.max(0, Number(seconds) || 0); return String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0'); }
    function date(value) { const parsed = new Date(String(value || '').replace(' ', 'T')); return isNaN(parsed.getTime()) ? 'Data indisponível' : parsed.toLocaleString('pt-BR', {day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'}); }
    async function json(url, options) {
        const response = await fetch(url, Object.assign({credentials:'same-origin', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}}, options));
        let payload; try { payload = JSON.parse((await response.text()).trim()); }
        catch (_) { throw new Error(response.status === 419 ? 'Sua sessão expirou. Recarregue a página.' : 'A resposta não pôde ser lida. Verifique sua sessão e tente novamente.'); }
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Não foi possível concluir esta operação.');
        return payload;
    }
    function updateAccounts(value) {
        if (!value || typeof value !== 'object') return;
        accounts = value;
        document.querySelectorAll('[data-cw-account]').forEach(button => {
            const line = accounts[button.dataset.cwAccount];
            if (!line) return;
            button.disabled = !line.configured;
            button.querySelector('[data-cw-line-name]').textContent = line.name;
            button.querySelector('[data-cw-line-status]').textContent = line.configured ? 'Abrir central de ligações' : 'Não configurada';
            if (/^#[a-f0-9]{6}$/i.test(line.color)) button.style.setProperty('--cw-line', line.color);
        });
        Array.from(picker.options).forEach(option => { const line = accounts[option.value]; if (line) { option.textContent = line.name; option.disabled = !line.configured; } });
        if (/^#[a-f0-9]{6}$/i.test(accounts[account]?.color || '')) dialog.style.setProperty('--cw-accent', accounts[account].color);
        picker.value = account;
    }
    function renderHistory() {
        history.replaceChildren();
        dialog.querySelector('[data-cw-count]').textContent = String(rows.length);
        dialog.querySelector('[data-cw-context]').textContent = leadId ? 'Chamadas associadas a este lead' : (number ? 'Histórico do telefone pesquisado' : 'Chamadas recentes desta linha');
        if (!rows.length) { empty(history, 'Nenhuma chamada', 'Sincronize esta linha ou altere o filtro para encontrar uma conversa.'); return; }
        rows.forEach(call => {
            const button = el('button', 'cw-history-item');
            button.type = 'button'; button.dataset.cwSelect = String(call.id);
            button.setAttribute('aria-current', String(Number(call.id) === Number(selected)));
            button.append(el('strong', '', call.lead_name || 'Sem lead associado'), el('span', 'cw-history-number', call.normalized_phone || call.contact_phone || 'Número indisponível'));
            const footer = el('span', 'cw-history-foot');
            const status = call.analysis_status === 'completed' ? (call.overall_score === null ? 'Analisada' : call.overall_score + '/10') : (call.analysis_status === 'failed' ? 'Falha na análise' : 'Pendente');
            footer.append(el('span', '', date(call.started_at) + ' · ' + time(call.duration)), el('span', 'cw-history-chip' + (call.analysis_status === 'failed' ? ' is-failed' : (call.analysis_status !== 'completed' ? ' is-pending' : '')), call.outcome === 'invalid_number' ? 'Incorreto' : status));
            button.append(footer); history.append(button);
        });
    }
    function renderDetail(html, id) {
        stopAudio(); selected = id ? Number(id) : null;
        if (html) detail.innerHTML = html;
        else empty(detail, 'Uma conversa, todo o contexto', 'Selecione uma ligação para ouvir a gravação e ver seus insights.');
        detail.scrollTop = 0;
        const button = detail.querySelector('.cw-analysis-form button');
        if (button && analyzing.has(selected)) { button.disabled = true; button.textContent = 'Análise em andamento…'; }
        renderHistory();
    }
    async function load(id, keepList) {
        selected = id ? Number(id) : null;
        const current = ++sequence;
        if (request) request.abort();
        request = new AbortController();
        notice.hidden = true; stopAudio(); loading(detail, 'Carregando a conversa…');
        if (!keepList) loading(history, 'Carregando histórico…');
        const params = new URLSearchParams({account:account});
        params.set('lead_id', String(leadId));
        params.set('number', number);
        try {
            const payload = await json(api + (id ? '/' + encodeURIComponent(id) : '') + '/painel?' + params.toString(), {signal:request.signal});
            if (current !== sequence || !dialog.open) return;
            account = payload.account || account;
            updateAccounts(payload.accounts);
            if (!keepList) {
                rows = Array.isArray(payload.calls) ? payload.calls : [];
                leadId = Number(payload.lead_id) || 0; number = payload.number || '';
                search.elements.number.value = number;
            }
            renderDetail(payload.detail_html || '', payload.selected_id);
        } catch (error) {
            if (error.name === 'AbortError' || current !== sequence) return;
            empty(detail, 'Não foi possível abrir a conversa', error.message);
            if (!keepList) { rows = []; renderHistory(); }
            const retry = el('button', 'cw-button cw-button-secondary', 'Tentar novamente');
            retry.type = 'button'; retry.addEventListener('click', () => load(id, keepList));
            detail.firstElementChild.append(retry);
        }
    }
    function open(options) {
        opener = document.activeElement;
        if (!dialog.open) { previousOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden'; dialog.showModal(); }
        dialog.classList.remove('cw-show-history');
        dialog.querySelector('[data-cw-history-toggle]').setAttribute('aria-expanded', 'false');
        account = options.account || account; leadId = Number(options.leadId) || 0; number = ''; selected = options.id ? Number(options.id) : null;
        load(selected, false);
    }
    document.addEventListener('click', event => {
        const call = event.target.closest('[data-cw-open]');
        const line = event.target.closest('[data-cw-account]');
        if (call) { event.preventDefault(); open({id:call.dataset.cwOpen, leadId:call.dataset.cwLead}); }
        else if (line && !line.disabled) { event.preventDefault(); open({account:line.dataset.cwAccount, leadId:line.dataset.cwLead}); }
    });
    dialog.addEventListener('close', () => {
        stopAudio(); if (request) request.abort(); sequence++; document.body.style.overflow = previousOverflow;
        if (opener && opener.isConnected) opener.focus();
    });
    dialog.addEventListener('click', async event => {
        if (event.target === dialog || event.target.closest('[data-cw-close]')) { dialog.close(); return; }
        const call = event.target.closest('[data-cw-select]');
        if (call) { dialog.classList.remove('cw-show-history'); dialog.querySelector('[data-cw-history-toggle]').setAttribute('aria-expanded','false'); load(call.dataset.cwSelect, true); return; }
        if (event.target.closest('[data-cw-refresh]')) { load(selected, false); return; }
        if (event.target.closest('[data-cw-reset]')) { leadId = 0; number = ''; search.elements.number.value = ''; load(null, false); return; }
        if (event.target.closest('[data-cw-history-toggle]')) { const expanded = dialog.classList.toggle('cw-show-history'); dialog.querySelector('[data-cw-history-toggle]').setAttribute('aria-expanded',String(expanded)); return; }
        const tab = event.target.closest('[data-cw-tab]');
        if (tab) { activateTab(tab); return; }
        const copy = event.target.closest('[data-cw-copy]');
        if (copy) { try { await navigator.clipboard.writeText(copy.dataset.cwCopy); notify('Telefone copiado.'); } catch (_) { notify('Selecione o telefone exibido para copiar.', true); } }
    });
    function activateTab(tab) {
        detail.querySelectorAll('[data-cw-tab]').forEach(item => { const active = item === tab; item.setAttribute('aria-selected',String(active)); item.tabIndex = active ? 0 : -1; });
        detail.querySelectorAll('[data-cw-pane]').forEach(item => { item.hidden = item.dataset.cwPane !== tab.dataset.cwTab; });
    }
    dialog.addEventListener('keydown', event => {
        if (!event.target.matches('[data-cw-tab]') || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
        event.preventDefault(); const tabs = Array.from(detail.querySelectorAll('[data-cw-tab]')); const i = tabs.indexOf(event.target);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (i + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        activateTab(tabs[next]); tabs[next].focus();
    });
    picker.addEventListener('change', () => { account = picker.value; load(null, false); });
    search.addEventListener('submit', event => { event.preventDefault(); number = search.elements.number.value.trim(); leadId = 0; load(null, false); });
    detail.addEventListener('error', event => { if (event.target.tagName === 'AUDIO') { const alert = detail.querySelector('[data-cw-audio-error]'); if (alert) alert.hidden = false; } }, true);
    detail.addEventListener('change', event => {
        if (event.target.matches('[data-cw-correction-action]')) {
            const form = event.target.form; form.querySelector('[data-cw-lead-picker]').hidden = event.target.value !== 'link';
        }
    });
    detail.addEventListener('input', event => {
        if (event.target.name === 'reason') event.target.form.querySelector('[data-cw-reason-count]').textContent = event.target.value.trim().length + ' caracteres · mínimo de 50';
        if (!event.target.matches('[data-cw-lead-search]')) return;
        clearTimeout(leadTimer); const query = event.target.value.trim(); const select = event.target.form.querySelector('[data-cw-lead-results]'); const current = ++leadSequence;
        select.replaceChildren(new Option(query.length < 2 ? 'Digite ao menos 2 caracteres' : 'Buscando…',''));
        if (query.length < 2) return;
        leadTimer = setTimeout(async () => {
            try {
                const payload = await json(api + '/buscar-leads?search=' + encodeURIComponent(query));
                if (current !== leadSequence || !select.isConnected) return;
                select.replaceChildren(new Option(payload.leads.length ? 'Selecione o lead correto' : 'Nenhum lead encontrado',''));
                payload.leads.forEach(lead => select.append(new Option(lead.name + ' · ' + (lead.phone || 'Sem telefone'),String(lead.id))));
            } catch (error) { if (current === leadSequence) select.replaceChildren(new Option('Não foi possível buscar. Tente novamente.','')); }
        }, 280);
    });
    detail.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('.cw-analysis-form,.cw-correction-form')) return;
        event.preventDefault(); if (!form.reportValidity()) return;
        const isAnalysis = form.matches('.cw-analysis-form'), id = selected;
        if (isAnalysis && analyzing.has(id)) return;
        if (!isAnalysis && form.elements.action.value === 'link' && !form.elements.lead_id.value) { notify('Busque e selecione o lead correto.',true); return; }
        const button = form.querySelector('button[type=submit]'); const original = button.textContent;
        button.disabled = true; button.textContent = isAnalysis ? 'Analisando a conversa…' : 'Salvando correção…';
        if (isAnalysis) analyzing.add(id);
        const abort = new AbortController(); const timer = setTimeout(() => abort.abort(), 330000);
        try {
            const payload = await json(form.getAttribute('action'), {method:'POST',body:new FormData(form),signal:abort.signal});
            if (isAnalysis) analyzing.delete(id);
            if (dialog.open && selected === id) { await load(id, false); notify(isAnalysis ? 'Análise concluída. Relatório atualizado.' : (payload.message || 'Correção registrada.')); }
        } catch (error) {
            notify(error.name === 'AbortError' ? 'O tempo de espera terminou. Confira o histórico antes de solicitar outra análise.' : error.message,true);
        } finally {
            clearTimeout(timer); analyzing.delete(id); if (button.isConnected) { button.disabled = false; button.textContent = original; }
        }
    });
    json(api + '/contas').then(payload => updateAccounts(payload.accounts)).catch(() => {
        if (dialog.dataset.hydrated !== '1') document.querySelectorAll('[data-cw-line-status]').forEach(item => { item.textContent = 'Configuração indisponível'; });
    });
    window.TitaniumCalls = {open:open};
}());
