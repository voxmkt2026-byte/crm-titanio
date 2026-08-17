/* global Toastify */
(function () {
  'use strict';

  function onReady(callback) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', callback);
    else callback();
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>'"]/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' })[char];
    });
  }

  function notify(message, type) {
    if (window.Toastify) {
      Toastify({
        text: message,
        duration: 4200,
        gravity: 'top',
        position: 'right',
        className: type === 'error' ? 'tc-toast-error' : 'tc-toast-success',
      }).showToast();
    } else {
      window.alert(message);
    }
  }

  onReady(function () {
    var page = document.getElementById('tcAutomationPage');
    var form = document.getElementById('tcAutomationForm');
    if (!page || !form) return;

    var trigger = document.getElementById('tcAutomationTrigger');
    var triggerValue = document.getElementById('tcAutomationTriggerValue');
    var valueLabel = document.getElementById('tcAutomationValueLabel');
    var valueUnit = document.getElementById('tcAutomationValueUnit');
    var triggerHelp = document.getElementById('tcAutomationTriggerHelp');
    var statusCondition = document.getElementById('tcAutomationStatusCondition');
    var title = document.getElementById('tcAutomationFormTitle');
    var hint = document.getElementById('tcAutomationFormHint');
    var preview = document.getElementById('tcAutomationPreview');
    var triggers = {};
    try { triggers = JSON.parse(page.dataset.triggers || '{}'); } catch (_) { triggers = {}; }

    function request(url, data) {
      var body = new URLSearchParams(data || new FormData(form));
      body.set('csrf_token', page.dataset.csrf || '');
      return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', Accept: 'application/json' },
        body: body.toString(),
      }).then(function (response) {
        return response.json().catch(function () { return { success: false, message: 'Não foi possível concluir a solicitação.' }; });
      });
    }

    function currentTriggerInfo() {
      return triggers[trigger.value] || { label: 'Gatilho', help: '', unit: 'horas', default: 24 };
    }

    function updateTriggerInfo(resetValue) {
      var info = currentTriggerInfo();
      valueLabel.textContent = trigger.value === 'lead_score' ? 'Score mínimo' : (trigger.value === 'lead_stale' ? 'Sem interação por' : 'Janela de análise');
      valueUnit.textContent = info.unit || 'horas';
      triggerHelp.textContent = info.help || '';
      triggerValue.max = trigger.value === 'lead_score' ? '100' : (trigger.value === 'lead_stale' ? '365' : '720');
      if (resetValue) triggerValue.value = info.default || 24;
      statusCondition.classList.toggle('d-none', trigger.value !== 'lead_status');
    }

    function actionInputs() {
      return Array.prototype.slice.call(form.querySelectorAll('input[name="actions[]"]'));
    }

    function updateActionConfigurations() {
      var selected = actionInputs().filter(function (input) { return input.checked; }).map(function (input) { return input.value; });
      form.querySelectorAll('.tc-automation-action-config').forEach(function (block) {
        block.classList.toggle('is-visible', selected.indexOf(block.dataset.actionConfig) !== -1);
      });
      actionInputs().forEach(function (input) {
        input.closest('.tc-automation-action-choice').classList.toggle('is-selected', input.checked);
      });
    }

    function resetForm() {
      form.reset();
      form.elements.id.value = '';
      form.elements.name.value = 'Novo fluxo comercial';
      form.elements.cooldown_hours.value = '24';
      form.elements.trigger_type.value = 'lead_stale';
      form.elements.task_due_hours.value = '24';
      form.elements.event_in_hours.value = '24';
      form.elements.event_duration_minutes.value = '30';
      form.elements.followup_hours.value = '24';
      form.elements.whatsapp_language.value = 'pt_BR';
      form.elements.tag_name.value = 'Automação';
      form.elements.tag_color.value = '#7c3aed';
      form.elements.task_priority.value = 'alta';
      form.elements.priority_to.value = 'alta';
      form.elements.history_message.value = 'Fluxo automático executado: {{fluxo}}.';
      ['create_task', 'notify_owner', 'log_history'].forEach(function (action) {
        var field = form.querySelector('input[name="actions[]"][value="' + action + '"]');
        if (field) field.checked = true;
      });
      title.textContent = 'Novo fluxo';
      hint.textContent = 'Defina o gatilho, as regras e o que deve acontecer.';
      preview.innerHTML = '<i class="fa-solid fa-flask"></i><div><strong>Valide antes de ativar</strong><span>O teste exibirá a quantidade e uma amostra dos leads que atendem às regras.</span></div>';
      updateTriggerInfo(true);
      updateActionConfigurations();
    }

    function setField(name, value) {
      var field = form.elements[name];
      if (field && value !== undefined && value !== null) field.value = String(value);
    }

    function loadFlow(flow) {
      resetForm();
      var config = flow.trigger_config || {};
      form.elements.id.value = flow.is_template ? '' : (flow.id || '');
      setField('name', flow.name || 'Novo fluxo comercial');
      setField('description', flow.description || '');
      setField('trigger_type', flow.trigger_type || 'lead_stale');
      updateTriggerInfo(false);
      setField('trigger_value', flow.trigger_type === 'lead_stale' ? (config.days || 10) : (flow.trigger_type === 'lead_score' ? (config.min_score || 70) : (config.window_hours || 24)));
      setField('cooldown_hours', config.cooldown_hours || 24);
      setField('condition_source', config.source || '');
      setField('condition_assigned_to', config.assigned_to || '');
      form.elements.only_unassigned.checked = !!config.only_unassigned;
      (config.statuses || []).forEach(function (status) {
        var field = form.querySelector('input[name="trigger_statuses[]"][value="' + status + '"]');
        if (field) field.checked = true;
      });
      setField('whatsapp_template', config.whatsapp_template || '');
      setField('whatsapp_language', config.whatsapp_language || 'pt_BR');
      setField('email_subject', config.email_subject || '');
      setField('email_body', config.email_body || '');
      setField('priority_to', config.priority_to || 'alta');
      setField('status_to', config.status_to || '');
      setField('reassign_to', config.reassign_to || '');
      setField('followup_hours', config.followup_hours || 24);
      setField('history_message', config.history_message || 'Fluxo automático executado: {{fluxo}}.');
      setField('tag_name', (config.tag || {}).name || 'Automação');
      setField('tag_color', (config.tag || {}).color || '#7c3aed');
      setField('task_title', (config.task || {}).title || '');
      setField('task_description', (config.task || {}).description || '');
      setField('task_priority', (config.task || {}).priority || 'alta');
      setField('task_due_hours', (config.task || {}).due_hours || 24);
      setField('task_assigned_to', (config.task || {}).assigned_to || '');
      setField('event_title', (config.event || {}).title || '');
      setField('event_description', (config.event || {}).description || '');
      setField('event_in_hours', (config.event || {}).in_hours || 24);
      setField('event_duration_minutes', (config.event || {}).duration_minutes || 30);
      setField('event_assigned_to', (config.event || {}).assigned_to || '');
      actionInputs().forEach(function (input) { input.checked = (flow.actions || []).indexOf(input.value) !== -1; });
      title.textContent = flow.is_template ? 'Personalizar modelo' : 'Editar fluxo';
      hint.textContent = flow.is_template ? 'Este modelo será salvo como um novo fluxo da sua equipe.' : 'Revise as regras e salve para manter o fluxo ativo.';
      updateActionConfigurations();
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function getFormData() {
      return new FormData(form);
    }

    function showPreview(data) {
      var previewData = data.preview || {};
      var total = Number(previewData.total || 0);
      var samples = Array.isArray(previewData.sample) ? previewData.sample : [];
      var list = samples.length ? '<ul>' + samples.map(function (lead) {
        return '<li><strong>' + escapeHtml(lead.name || 'Lead') + '</strong><span>' + escapeHtml(lead.status || '—') + (lead.source ? ' · ' + escapeHtml(lead.source) : '') + '</span></li>';
      }).join('') + '</ul>' : '<p>Nenhum lead atende a esses critérios neste momento.</p>';
      preview.innerHTML = '<i class="fa-solid fa-flask-vial"></i><div><strong>' + total + (total === 1 ? ' lead elegível' : ' leads elegíveis') + '</strong><span>Simulação sem envio de mensagem nem alteração de dados.</span>' + list + '</div>';
    }

    var newAutomation = document.getElementById('tcAutomationNew');
    if (newAutomation) newAutomation.addEventListener('click', resetForm);
    trigger.addEventListener('change', function () { updateTriggerInfo(true); });
    actionInputs().forEach(function (input) { input.addEventListener('change', updateActionConfigurations); });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!form.elements.name.value.trim()) { notify('Informe o nome do fluxo.', 'error'); return; }
      if (!actionInputs().some(function (input) { return input.checked; })) { notify('Selecione ao menos uma ação.', 'error'); return; }
      var button = form.querySelector('[type="submit"]');
      button.disabled = true;
      request(page.dataset.saveUrl, getFormData()).then(function (data) {
        if (!data.success) throw new Error(data.message || 'Não foi possível salvar o fluxo.');
        notify('Fluxo salvo e ativado.');
        window.setTimeout(function () { window.location.reload(); }, 450);
      }).catch(function (error) { notify(error.message || 'Não foi possível salvar o fluxo.', 'error'); })
        .finally(function () { button.disabled = false; });
    });

    document.getElementById('tcAutomationTest').addEventListener('click', function () {
      if (!form.elements.name.value.trim()) { notify('Informe um nome para testar o fluxo.', 'error'); return; }
      if (!actionInputs().some(function (input) { return input.checked; })) { notify('Selecione pelo menos uma ação antes de testar.', 'error'); return; }
      var button = this;
      button.disabled = true;
      button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Testando';
      request(page.dataset.previewUrl, getFormData()).then(function (data) {
        if (!data.success) throw new Error(data.message || 'Não foi possível testar os critérios.');
        showPreview(data);
      }).catch(function (error) { notify(error.message || 'Não foi possível testar os critérios.', 'error'); })
        .finally(function () { button.disabled = false; button.innerHTML = '<i class="fa-solid fa-flask"></i> Testar critérios'; });
    });

    page.querySelectorAll('.tc-automation-edit').forEach(function (button) {
      button.addEventListener('click', function () {
        try { loadFlow(JSON.parse(button.dataset.flow)); } catch (_) { notify('Não foi possível abrir esse fluxo.', 'error'); }
      });
    });

    function quickAction(button, action, confirmation) {
      if (confirmation && !window.confirm(confirmation)) return;
      button.disabled = true;
      request(page.dataset.baseUrl + '/' + button.dataset.id + '/' + action, new URLSearchParams()).then(function (data) {
        if (!data.success) throw new Error(data.message || 'Ação não concluída.');
        if (action === 'executar') {
          var result = data.result || {};
          notify('Execução concluída: ' + (result.success || 0) + ' sucesso(s), ' + (result.partial || 0) + ' parcial(is), ' + (result.errors || 0) + ' erro(s).');
        } else notify(action === 'alternar' ? 'Status do fluxo atualizado.' : 'Fluxo excluído.');
        window.setTimeout(function () { window.location.reload(); }, 500);
      }).catch(function (error) { notify(error.message || 'Ação não concluída.', 'error'); button.disabled = false; });
    }
    page.querySelectorAll('.tc-automation-run').forEach(function (button) { button.addEventListener('click', function () { quickAction(button, 'executar', 'Executar este fluxo agora? As ações configuradas serão aplicadas aos leads elegíveis.'); }); });
    page.querySelectorAll('.tc-automation-toggle').forEach(function (button) { button.addEventListener('click', function () { quickAction(button, 'alternar'); }); });
    page.querySelectorAll('.tc-automation-delete').forEach(function (button) { button.addEventListener('click', function () { quickAction(button, 'excluir', 'Excluir este fluxo e seu histórico de execução? Esta ação não pode ser desfeita.'); }); });

    var search = document.getElementById('tcAutomationSearch');
    var filter = 'all';
    function filterFlows() {
      var term = (search.value || '').trim().toLowerCase();
      var totalVisible = 0;
      page.querySelectorAll('.tc-automation-flow').forEach(function (flow) {
        var visible = (filter === 'all' || flow.dataset.kind === filter) && (!term || (flow.dataset.search || '').indexOf(term) !== -1);
        flow.classList.toggle('d-none', !visible);
        if (visible) totalVisible++;
      });
      document.getElementById('tcAutomationNoResults').classList.toggle('d-none', totalVisible !== 0);
    }
    search.addEventListener('input', filterFlows);
    page.querySelectorAll('.tc-automation-filter button').forEach(function (button) {
      button.addEventListener('click', function () {
        filter = button.dataset.filter || 'all';
        page.querySelectorAll('.tc-automation-filter button').forEach(function (item) { item.classList.toggle('active', item === button); });
        filterFlows();
      });
    });

    resetForm();
  });
}());
