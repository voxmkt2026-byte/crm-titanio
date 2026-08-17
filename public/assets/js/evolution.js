/**
 * public/assets/js/evolution.js
 * Atendimento WhatsApp (Evolution/EvoAI CRM): envio de mensagens, polling
 * incremental da conversa ativa (mesmo padrão sem WebSockets do Chat interno,
 * ver public/assets/js/app.js::initChat), vínculo com lead, transferência
 * entre colaboradores e edição de etiquetas. Tudo via AJAX (jQuery), sem
 * recarregar a lista de conversas inteira.
 */
(function () {
    'use strict';

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function toast(message, type) {
        if (typeof Toastify === 'undefined') {
            return;
        }
        var colors = { success: '#16a34a', error: '#dc2626', warning: '#d97706', info: '#3b82f6' };
        Toastify({
            text: message,
            duration: 3200,
            gravity: 'top',
            position: 'right',
            close: true,
            stopOnFocus: true,
            style: { background: colors[type] || colors.info, borderRadius: '0.5rem' }
        }).showToast();
    }

    function initEvolutionInbox() {
        var app = document.getElementById('tcEvoApp');
        if (!app || typeof $ === 'undefined') {
            return;
        }

        var csrfToken = app.getAttribute('data-csrf-token');
        var urlBase = app.getAttribute('data-url-base');
        var agendaUrl = app.getAttribute('data-agenda-url');
        var activeId = app.getAttribute('data-active-id');
        var isManager = app.getAttribute('data-is-manager') === '1';
        var lastMessageId = parseInt(app.getAttribute('data-last-message-id'), 10) || 0;

        var messagesBox = document.getElementById('tcEvoMessages');
        var messagesList = document.getElementById('tcEvoMessagesList');
        var composer = document.getElementById('tcEvoComposer');
        var input = document.getElementById('tcEvoInput');
        var privateCheckbox = document.getElementById('tcEvoPrivate');

        function scrollToBottom() {
            if (messagesBox) {
                messagesBox.scrollTop = messagesBox.scrollHeight;
            }
        }
        scrollToBottom();

        function lastRenderedSenderKey() {
            if (!messagesList) return undefined;
            var rows = messagesList.querySelectorAll('.tc-chat-bubble-row[data-sender-key]');
            if (!rows.length) return undefined;
            return rows[rows.length - 1].getAttribute('data-sender-key');
        }

        function renderBubble(msg) {
            var isOutgoing = msg.type === 'outgoing';
            var isPrivate = !!msg.private;
            var senderKey = msg.type + ':' + (msg.user_id != null ? msg.user_id : (isPrivate ? 'note' : ''));
            var isGrouped = senderKey === lastRenderedSenderKey();
            var row = document.createElement('div');
            row.className = 'tc-chat-bubble-row' + (isOutgoing ? ' mine' : '') + (isPrivate ? ' tc-evo-note' : '') + (isGrouped ? ' tc-grouped' : '');
            row.setAttribute('data-message-id', msg.id);
            row.setAttribute('data-sender-key', senderKey);

            var html = '';
            if (!isOutgoing) {
                html += '<div class="tc-chat-bubble-avatar"><i class="fa-brands fa-whatsapp"></i></div>';
            }
            html += '<div class="tc-chat-bubble">';
            if (isPrivate && !isGrouped) {
                html += '<div class="tc-chat-bubble-author"><i class="fa-solid fa-note-sticky me-1"></i>Nota interna' + (msg.sender ? ' — ' + escapeHtml(msg.sender) : '') + '</div>';
            } else if (isOutgoing && msg.sender && !isGrouped) {
                html += '<div class="tc-chat-bubble-author">' + escapeHtml(msg.sender) + '</div>';
            } else if (!isOutgoing && !isPrivate && !isGrouped) {
                var headerTitle = document.querySelector('.tc-chat-header-title');
                var customerName = msg.sender || (headerTitle ? headerTitle.textContent.trim() : '');
                if (customerName && customerName !== 'Contato WhatsApp') {
                    html += '<div class="tc-chat-bubble-author tc-evo-customer-name">' + escapeHtml(customerName) + '</div>';
                }
            }
            html += '<div class="tc-chat-bubble-content">' + escapeHtml(msg.content || '').replace(/\n/g, '<br>') + '</div>';
            html += '<div class="tc-chat-bubble-meta">' + escapeHtml(msg.time || '') + '</div>';
            html += '</div>';

            row.innerHTML = html;
            return row;
        }

        // ---- Envio de mensagens / notas internas ----
        if (composer) {
            composer.addEventListener('submit', function (evt) {
                evt.preventDefault();
                sendMessage();
            });

            if (input) {
                input.addEventListener('keydown', function (evt) {
                    if (evt.key === 'Enter' && !evt.shiftKey) {
                        evt.preventDefault();
                        sendMessage();
                    }
                });
                input.addEventListener('input', function () {
                    input.style.height = 'auto';
                    input.style.height = Math.min(input.scrollHeight, 120) + 'px';
                });
            }
        }

        function sendMessage() {
            var content = (input.value || '').trim();
            if (!content) {
                return;
            }
            var conversationId = composer.getAttribute('data-conversation-id');
            var isPrivate = privateCheckbox && privateCheckbox.checked;

            input.disabled = true;
            $.ajax({
                url: urlBase + '/' + encodeURIComponent(conversationId) + '/enviar',
                method: 'POST',
                dataType: 'json',
                data: { content: content, private: isPrivate ? 1 : 0, csrf_token: csrfToken }
            }).done(function (resp) {
                if (resp && resp.success) {
                    var meta = resp.message || {};
                    var newId = meta.id || 0;
                    messagesList.appendChild(renderBubble({
                        id: newId,
                        type: 'outgoing',
                        private: isPrivate,
                        content: content,
                        time: meta.time || '',
                        sender: ''
                    }));
                    if (newId > lastMessageId) {
                        lastMessageId = newId;
                    }
                    input.value = '';
                    input.style.height = 'auto';
                    if (privateCheckbox) privateCheckbox.checked = false;
                    scrollToBottom();
                } else {
                    toast((resp && resp.message) || 'Não foi possível enviar a mensagem.', 'error');
                }
            }).fail(function (xhr) {
                var resp = xhr.responseJSON;
                toast((resp && resp.message) || 'Falha de comunicação ao enviar a mensagem.', 'error');
            }).always(function () {
                input.disabled = false;
                input.focus();
            });
        }

        // ---- Fluxo guiado de atendimento ----
        // As etapas nunca disparam resposta automaticamente: o texto sugerido
        // apenas preenche o compositor para o atendente revisar e enviar.
        var flowPanel = document.getElementById('tcEvoFlowPanel');
        var flowSelect = document.getElementById('tcEvoFlowSelect');
        var setFlowBtn = document.getElementById('tcEvoSetFlow');
        var advanceFlowBtn = document.getElementById('tcEvoAdvanceFlow');
        var flowCurrent = document.getElementById('tcEvoFlowCurrent');
        var prepareFlowBtn = document.getElementById('tcEvoPrepareFlow');
        var currentFlow = null;

        var emailModal = document.getElementById('tcEvoEmailModal');
        var emailForm = document.getElementById('tcEvoEmailForm');
        var emailTemplateSelect = document.getElementById('tcEvoEmailTemplate');
        var emailSubject = document.getElementById('tcEvoEmailSubject');
        var emailContent = document.getElementById('tcEvoEmailContent');
        var emailSendButton = document.getElementById('tcEvoEmailSend');
        var emailTemplates = {};
        var emailTemplatesLoaded = false;

        function replaceTemplateVariables(value) {
            var context = emailForm || app;
            if (!context) return value || '';
            var values = {
                nome: context.getAttribute('data-lead-name') || 'cliente',
                interesse: context.getAttribute('data-lead-interest') || 'sua solicitação',
                responsavel: context.getAttribute('data-assigned-name') || ''
            };
            return String(value || '').replace(/\{\{(nome|interesse|responsavel)\}\}/g, function (_, key) {
                return values[key] || '';
            });
        }

        function loadEmailTemplates() {
            if (!emailForm || !emailTemplateSelect || emailTemplatesLoaded) return;
            emailTemplatesLoaded = true;
            fetch(emailForm.getAttribute('data-templates-url'), { credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    emailTemplateSelect.innerHTML = '<option value="">Sem modelo (começar do zero)</option>';
                    (data.items || []).forEach(function (item) {
                        emailTemplates[String(item.id)] = item;
                        var option = document.createElement('option');
                        option.value = item.id;
                        option.textContent = item.name + (item.category ? ' — ' + item.category.replace(/_/g, ' ') : '');
                        emailTemplateSelect.appendChild(option);
                    });
                })
                .catch(function () {
                    emailTemplateSelect.innerHTML = '<option value="">Não foi possível carregar os modelos</option>';
                });
        }

        function applyEmailDraft(subject, content) {
            if (!emailForm) {
                toast('Vincule um lead com e-mail para preparar esta etapa.', 'warning');
                return;
            }
            if (emailSubject) emailSubject.value = replaceTemplateVariables(subject);
            if (emailContent) emailContent.value = replaceTemplateVariables(content);
            loadEmailTemplates();
            if (typeof bootstrap !== 'undefined' && emailModal) {
                bootstrap.Modal.getOrCreateInstance(emailModal).show();
            }
        }

        if (emailModal) {
            emailModal.addEventListener('show.bs.modal', loadEmailTemplates);
        }
        if (emailTemplateSelect) {
            emailTemplateSelect.addEventListener('change', function () {
                var template = emailTemplates[emailTemplateSelect.value];
                if (!template) return;
                if (emailSubject) emailSubject.value = replaceTemplateVariables(template.subject);
                if (emailContent) emailContent.value = replaceTemplateVariables(template.content);
            });
        }
        if (emailForm) {
            emailForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var to = (document.getElementById('tcEvoEmailTo').value || '').trim();
                var subject = (emailSubject.value || '').trim();
                var content = (emailContent.value || '').trim();
                if (!to || !subject || !content) {
                    toast('Preencha destinatário, assunto e mensagem.', 'warning');
                    return;
                }
                if (emailSendButton) emailSendButton.disabled = true;
                $.ajax({
                    url: emailForm.getAttribute('data-url'), method: 'POST', dataType: 'json',
                    data: { to: to, subject: subject, content: content, csrf_token: csrfToken }
                }).done(function (response) {
                    if (response && response.success) {
                        toast(response.message || 'E-mail enviado com sucesso.', 'success');
                        if (typeof bootstrap !== 'undefined' && emailModal) bootstrap.Modal.getOrCreateInstance(emailModal).hide();
                    } else {
                        toast((response && response.message) || 'Não foi possível enviar o e-mail.', 'error');
                    }
                }).fail(function (xhr) {
                    var response = xhr.responseJSON;
                    toast((response && response.message) || 'Falha ao enviar o e-mail.', 'error');
                }).always(function () {
                    if (emailSendButton) emailSendButton.disabled = false;
                });
            });
        }

        // Modelos de WhatsApp também ficam disponíveis dentro do inbox. A
        // seleção apenas insere o texto no compositor para revisão manual.
        var whatsappTemplateSelect = document.getElementById('tcEvoWhatsappTemplate');
        var whatsappTemplates = {};
        if (whatsappTemplateSelect) {
            fetch(whatsappTemplateSelect.getAttribute('data-templates-url'), { credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    (data.items || []).forEach(function (item) {
                        whatsappTemplates[String(item.id)] = item;
                        var option = document.createElement('option');
                        option.value = item.id;
                        option.textContent = item.name + (item.category ? ' — ' + item.category.replace(/_/g, ' ') : '');
                        whatsappTemplateSelect.appendChild(option);
                    });
                });
            whatsappTemplateSelect.addEventListener('change', function () {
                var template = whatsappTemplates[whatsappTemplateSelect.value];
                if (!template || !input) return;
                input.value = replaceTemplateVariables(template.content);
                input.dispatchEvent(new Event('input'));
                input.focus();
            });
        }

        function renderFlow(flow) {
            if (!flowCurrent) return;
            if (!flow) {
                flowCurrent.textContent = 'Selecione um fluxo para orientar este atendimento.';
                flowCurrent.classList.add('text-muted');
                if (advanceFlowBtn) advanceFlowBtn.classList.add('d-none');
                if (prepareFlowBtn) prepareFlowBtn.classList.add('d-none');
                currentFlow = null;
                return;
            }
            currentFlow = flow;
            var channel = flow.channel === 'email' ? 'email' : 'whatsapp';
            flowCurrent.classList.remove('text-muted');
            flowCurrent.setAttribute('data-channel', channel);
            flowCurrent.setAttribute('data-suggestion', flow.suggestion || '');
            flowCurrent.setAttribute('data-email-subject', flow.email_subject || '');
            flowCurrent.setAttribute('data-guidance', flow.guidance || '');
            flowCurrent.innerHTML = '<div><strong>Etapa ' + (Number(flow.step) + 1) + '/' + flow.total + ':</strong> ' + escapeHtml(flow.title || 'Sem título') + '</div>' +
                '<div class="mt-1"><span class="badge text-bg-' + (channel === 'email' ? 'primary' : 'success') + '">' + (channel === 'email' ? 'E-mail' : 'WhatsApp') + '</span>' +
                (flow.guidance ? ' <span class="text-muted">' + escapeHtml(flow.guidance) + '</span>' : '') + '</div>';
            if (advanceFlowBtn) advanceFlowBtn.classList.toggle('d-none', !flow.total || Number(flow.step) >= Number(flow.total) - 1);
            if (prepareFlowBtn) {
                prepareFlowBtn.classList.remove('d-none');
                prepareFlowBtn.innerHTML = '<i class="fa-solid fa-pen-to-square me-1"></i>' + (channel === 'email' ? 'Preparar e-mail' : 'Preparar resposta WhatsApp');
            }
            // WhatsApp mantém a experiência original: a etapa já preenche a
            // resposta para revisão. E-mail abre somente um rascunho ao clicar.
            if (channel === 'whatsapp' && input && flow.suggestion) {
                input.value = flow.suggestion;
                input.dispatchEvent(new Event('input'));
                input.focus();
            }
        }

        function prepareCurrentFlow() {
            var flow = currentFlow;
            if (!flow && flowCurrent && !flowCurrent.classList.contains('text-muted')) {
                flow = {
                    channel: flowCurrent.getAttribute('data-channel'),
                    suggestion: flowCurrent.getAttribute('data-suggestion'),
                    email_subject: flowCurrent.getAttribute('data-email-subject')
                };
            }
            if (!flow) return;
            if (flow.channel === 'email') {
                applyEmailDraft(flow.email_subject || '', flow.suggestion || '');
                return;
            }
            if (input) {
                input.value = flow.suggestion || '';
                input.dispatchEvent(new Event('input'));
                input.focus();
            }
        }

        function postFlow(url, data) {
            if (!url) return;
            $.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                data: Object.assign({ csrf_token: csrfToken }, data || {})
            }).done(function (resp) {
                if (resp && resp.success) {
                    renderFlow(resp.flow || null);
                    toast(resp.message || 'Fluxo atualizado.', 'success');
                } else {
                    toast((resp && resp.message) || 'Não foi possível atualizar o fluxo.', 'error');
                }
            }).fail(function (xhr) {
                var resp = xhr.responseJSON;
                toast((resp && resp.message) || 'Falha de comunicação ao atualizar o fluxo.', 'error');
            });
        }

        if (flowPanel && flowSelect && setFlowBtn) {
            setFlowBtn.addEventListener('click', function () {
                postFlow(flowPanel.getAttribute('data-set-url'), { flow_id: flowSelect.value || '' });
            });
        }
        if (flowPanel && advanceFlowBtn) {
            advanceFlowBtn.addEventListener('click', function () {
                postFlow(flowPanel.getAttribute('data-advance-url'));
            });
        }
        if (prepareFlowBtn) {
            prepareFlowBtn.addEventListener('click', prepareCurrentFlow);
        }

        // ---- Polling incremental da conversa ativa (só id > lastMessageId, direto do nosso banco) ----
        if (activeId) {
            var pollTimer = setInterval(function () {
                $.ajax({ url: urlBase + '/' + encodeURIComponent(activeId) + '/poll', method: 'GET', dataType: 'json', data: { last_id: lastMessageId } })
                    .done(function (resp) {
                        if (!resp || !resp.success || !messagesList) return;
                        var appended = false;
                        (resp.messages || []).forEach(function (msg) {
                            messagesList.appendChild(renderBubble(msg));
                            if (msg.id > lastMessageId) {
                                lastMessageId = msg.id;
                            }
                            appended = true;
                        });
                        if (appended) {
                            scrollToBottom();
                        }
                    });
            }, 6000);
            window.addEventListener('beforeunload', function () { clearInterval(pollTimer); });
        }

        // ---- Vincular lead ----
        var leadSearchInput = document.getElementById('tcEvoLeadSearch');
        var leadResultsBox = document.getElementById('tcEvoLeadResults');
        if (leadSearchInput && leadResultsBox) {
            var leadSearchTimer = null;
            leadSearchInput.addEventListener('input', function () {
                clearTimeout(leadSearchTimer);
                var term = leadSearchInput.value.trim();
                if (term.length < 2) {
                    leadResultsBox.classList.add('d-none');
                    leadResultsBox.innerHTML = '';
                    return;
                }
                leadSearchTimer = setTimeout(function () {
                    $.ajax({ url: '/leads/buscar-rapido', method: 'GET', dataType: 'json', data: { q: term } })
                        .done(function (resp) {
                            var items = (resp && resp.items) || [];
                            if (!items.length) {
                                leadResultsBox.innerHTML = '<div class="tc-chat-empty">Nenhum lead encontrado.</div>';
                                leadResultsBox.classList.remove('d-none');
                                return;
                            }
                            leadResultsBox.innerHTML = items.map(function (item) {
                                return '<button type="button" class="tc-evo-lead-result" data-lead-id="' + item.id + '">' +
                                    '<strong>' + escapeHtml(item.name) + '</strong><span>' + escapeHtml(item.phone || '') + '</span></button>';
                            }).join('');
                            leadResultsBox.classList.remove('d-none');
                        });
                }, 300);
            });

            leadResultsBox.addEventListener('click', function (evt) {
                var btn = evt.target.closest('[data-lead-id]');
                if (!btn) return;
                var leadId = btn.getAttribute('data-lead-id');
                $.ajax({
                    url: urlBase + '/' + encodeURIComponent(activeId) + '/vincular-lead',
                    method: 'POST',
                    dataType: 'json',
                    data: { lead_id: leadId, csrf_token: csrfToken }
                }).done(function (resp) {
                    if (resp && resp.success) {
                        toast('Lead vinculado ao atendimento.', 'success');
                        location.reload();
                    } else {
                        toast((resp && resp.message) || 'Não foi possível vincular o lead.', 'error');
                    }
                }).fail(function (xhr) {
                    var resp = xhr.responseJSON;
                    toast((resp && resp.message) || 'Falha ao vincular o lead.', 'error');
                });
            });
        }

        // ---- Transferir atendimento ----
        var transferSelect = document.getElementById('tcEvoTransferSelect');
        if (transferSelect && isManager) {
            transferSelect.addEventListener('change', function () {
                var userId = transferSelect.value;
                if (!userId) return;
                $.ajax({
                    url: urlBase + '/' + encodeURIComponent(activeId) + '/transferir',
                    method: 'POST',
                    dataType: 'json',
                    data: { user_id: userId, csrf_token: csrfToken }
                }).done(function (resp) {
                    if (resp && resp.success) {
                        toast('Atendimento transferido' + (resp.external_synced ? ' e sincronizado com a Evolution.' : '.'), 'success');
                    } else {
                        toast((resp && resp.message) || 'Não foi possível transferir.', 'error');
                    }
                }).fail(function (xhr) {
                    var resp = xhr.responseJSON;
                    toast((resp && resp.message) || 'Falha ao transferir o atendimento.', 'error');
                });
            });
        }

        // ---- Etiquetas ----
        var labelsInput = document.getElementById('tcEvoLabelsInput');
        var saveLabelsBtn = document.getElementById('tcEvoSaveLabels');
        if (labelsInput && saveLabelsBtn) {
            saveLabelsBtn.addEventListener('click', function () {
                $.ajax({
                    url: urlBase + '/' + encodeURIComponent(activeId) + '/etiquetas',
                    method: 'POST',
                    dataType: 'json',
                    data: { labels: labelsInput.value, csrf_token: csrfToken }
                }).done(function (resp) {
                    if (resp && resp.success) {
                        toast('Etiquetas atualizadas.', 'success');
                        location.reload();
                    } else {
                        toast((resp && resp.message) || 'Não foi possível salvar as etiquetas.', 'error');
                    }
                }).fail(function (xhr) {
                    var resp = xhr.responseJSON;
                    toast((resp && resp.message) || 'Falha ao salvar as etiquetas.', 'error');
                });
            });
        }

        // ---- Cadastrar lead a partir do atendimento ----
        var createLeadForm = document.getElementById('tcEvoCreateLeadForm');
        if (createLeadForm) {
            createLeadForm.addEventListener('submit', function (evt) {
                evt.preventDefault();
                var formData = new FormData(createLeadForm);
                formData.append('csrf_token', csrfToken);
                $.ajax({
                    url: createLeadForm.dataset.url,
                    method: 'POST',
                    dataType: 'json',
                    data: $.param(Array.from(formData.entries()).map(function (pair) { return { name: pair[0], value: pair[1] }; }))
                }).done(function (resp) {
                    if (resp && resp.success) {
                        toast('Lead cadastrado com sucesso.', 'success');
                        location.reload();
                    } else {
                        toast((resp && resp.message) || 'Não foi possível cadastrar o lead.', 'error');
                    }
                }).fail(function (xhr) {
                    var resp = xhr.responseJSON;
                    toast((resp && resp.message) || 'Falha ao cadastrar o lead.', 'error');
                });
            });
        }

        // ---- Criar tarefa a partir do atendimento ----
        var createTaskForm = document.getElementById('tcEvoCreateTaskForm');
        if (createTaskForm) {
            createTaskForm.addEventListener('submit', function (evt) {
                evt.preventDefault();
                var formData = new FormData(createTaskForm);
                formData.append('csrf_token', csrfToken);
                $.ajax({
                    url: createTaskForm.dataset.url,
                    method: 'POST',
                    dataType: 'json',
                    data: $.param(Array.from(formData.entries()).map(function (pair) { return { name: pair[0], value: pair[1] }; }))
                }).done(function (resp) {
                    if (resp && resp.success) {
                        toast('Tarefa criada com sucesso.', 'success');
                        var modalEl = document.getElementById('tcEvoCreateTaskModal');
                        var modal = window.bootstrap && modalEl ? window.bootstrap.Modal.getInstance(modalEl) : null;
                        if (modal) modal.hide();
                        createTaskForm.reset();
                    } else {
                        toast((resp && resp.message) || 'Não foi possível criar a tarefa.', 'error');
                    }
                }).fail(function (xhr) {
                    var resp = xhr.responseJSON;
                    toast((resp && resp.message) || 'Falha ao criar a tarefa.', 'error');
                });
            });
        }

        // ---- Sincronizar histórico existente na Evolution (conversas de antes do webhook) ----
        var syncBtn = document.getElementById('tcEvoSyncBtn');
        if (syncBtn) {
            syncBtn.addEventListener('click', function () {
                var icon = syncBtn.querySelector('i');
                syncBtn.disabled = true;
                if (icon) icon.classList.add('fa-spin');
                $.ajax({
                    url: urlBase + '/sincronizar',
                    method: 'POST',
                    dataType: 'json',
                    data: { csrf_token: csrfToken }
                }).done(function (resp) {
                    if (resp && resp.success) {
                        toast(resp.message || 'Conversas sincronizadas.', 'success');
                        location.reload();
                    } else {
                        toast((resp && resp.message) || 'Não foi possível sincronizar.', 'error');
                    }
                }).fail(function (xhr) {
                    var resp = xhr.responseJSON;
                    toast((resp && resp.message) || 'Falha ao sincronizar conversas.', 'error');
                }).always(function () {
                    syncBtn.disabled = false;
                    if (icon) icon.classList.remove('fa-spin');
                });
            });
        }

        // ---- Remover nota interna ----
        if (messagesList) {
            messagesList.addEventListener('click', function (evt) {
                var btn = evt.target.closest('.tc-evo-delete-note');
                if (!btn) return;
                var messageId = btn.getAttribute('data-message-id');
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ title: 'Remover esta nota interna?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Remover', cancelButtonText: 'Cancelar' }).then(function (result) {
                        if (result.isConfirmed) removeNote(messageId, btn);
                    });
                } else if (window.confirm('Remover esta nota interna?')) {
                    removeNote(messageId, btn);
                }
            });
        }

        function removeNote(messageId, btn) {
            $.ajax({
                url: urlBase + '/notas/' + encodeURIComponent(messageId) + '/remover',
                method: 'POST',
                dataType: 'json',
                data: { csrf_token: csrfToken }
            }).done(function (resp) {
                if (resp && resp.success) {
                    var row = btn.closest('[data-message-id]');
                    if (row) row.remove();
                    toast('Nota removida.', 'success');
                } else {
                    toast((resp && resp.message) || 'Não foi possível remover a nota.', 'error');
                }
            }).fail(function (xhr) {
                var resp = xhr.responseJSON;
                toast((resp && resp.message) || 'Falha ao remover a nota.', 'error');
            });
        }

        // ---- Forçar atualização do contato (nome/foto/número direto na Evolution) ----
        var refreshContactBtn = document.getElementById('tcEvoRefreshContactBtn');
        if (refreshContactBtn) {
            refreshContactBtn.addEventListener('click', function () {
                var icon = refreshContactBtn.querySelector('i');
                refreshContactBtn.disabled = true;
                if (icon) icon.classList.add('fa-spin');
                $.ajax({
                    url: urlBase + '/' + encodeURIComponent(activeId) + '/atualizar-contato',
                    method: 'POST',
                    dataType: 'json',
                    data: { csrf_token: csrfToken }
                }).done(function (resp) {
                    if (resp && resp.success) {
                        toast(resp.found ? 'Contato atualizado.' : 'A Evolution não retornou dados novos para este contato.', resp.found ? 'success' : 'info');
                        if (resp.found) location.reload();
                    } else {
                        toast((resp && resp.message) || 'Não foi possível atualizar o contato.', 'error');
                    }
                }).fail(function (xhr) {
                    var resp = xhr.responseJSON;
                    toast((resp && resp.message) || 'Falha ao atualizar o contato.', 'error');
                }).always(function () {
                    refreshContactBtn.disabled = false;
                    if (icon) icon.classList.remove('fa-spin');
                });
            });
        }

        // ---- Corrigir/informar número manualmente (contatos @lid não expostos pela Meta) ----
        var phoneInput = document.getElementById('tcEvoPhoneInput');
        var savePhoneBtn = document.getElementById('tcEvoSavePhone');
        if (phoneInput && savePhoneBtn) {
            savePhoneBtn.addEventListener('click', function () {
                var phone = phoneInput.value.trim();
                if (!phone) return;
                $.ajax({
                    url: urlBase + '/' + encodeURIComponent(activeId) + '/telefone',
                    method: 'POST',
                    dataType: 'json',
                    data: { phone: phone, csrf_token: csrfToken }
                }).done(function (resp) {
                    if (resp && resp.success) {
                        toast('Número atualizado.', 'success');
                        location.reload();
                    } else {
                        toast((resp && resp.message) || 'Não foi possível salvar o número.', 'error');
                    }
                }).fail(function (xhr) {
                    var resp = xhr.responseJSON;
                    toast((resp && resp.message) || 'Falha ao salvar o número.', 'error');
                });
            });
        }

        // ---- Sugestões de etiquetas (clique para adicionar ao campo) ----
        document.querySelectorAll('.tc-evo-label-suggest').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!labelsInput) return;
                var current = labelsInput.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
                var label = btn.getAttribute('data-label');
                if (current.indexOf(label) === -1) {
                    current.push(label);
                    labelsInput.value = current.join(', ');
                }
            });
        });

        // ---- Integração com a Agenda: agendar o próximo contato do lead vinculado ----
        var scheduleBtn = document.getElementById('tcEvoScheduleBtn');
        if (scheduleBtn) {
            scheduleBtn.addEventListener('click', function () {
                if (typeof Swal === 'undefined') return;
                var leadId = scheduleBtn.getAttribute('data-lead-id');
                Swal.fire({
                    title: 'Agendar próximo contato',
                    html:
                        '<label class="form-label mb-1" style="font-size:0.8rem;">Data/hora</label>' +
                        '<input type="datetime-local" id="tcEvoScheduleDate" class="form-control mb-2">' +
                        '<label class="form-label mb-1" style="font-size:0.8rem;">Observação (opcional)</label>' +
                        '<textarea id="tcEvoScheduleNote" class="form-control" rows="2"></textarea>',
                    showCancelButton: true,
                    confirmButtonText: 'Agendar',
                    cancelButtonText: 'Cancelar',
                    focusConfirm: false,
                    preConfirm: function () {
                        var date = document.getElementById('tcEvoScheduleDate').value;
                        if (!date) {
                            Swal.showValidationMessage('Informe a data/hora do agendamento.');
                            return false;
                        }
                        return { date: date, note: document.getElementById('tcEvoScheduleNote').value.trim() };
                    }
                }).then(function (result) {
                    if (!result.isConfirmed) return;
                    $.ajax({
                        url: agendaUrl,
                        method: 'POST',
                        dataType: 'json',
                        data: { csrf_token: csrfToken, lead_id: leadId, next_contact_at: result.value.date, note: result.value.note }
                    }).done(function (resp) {
                        if (resp && resp.success) {
                            toast('Próximo contato agendado.', 'success');
                        } else {
                            toast((resp && resp.message) || 'Não foi possível agendar.', 'error');
                        }
                    }).fail(function (xhr) {
                        var resp = xhr.responseJSON;
                        toast((resp && resp.message) || 'Falha ao agendar.', 'error');
                    });
                });
            });
        }

        // ---- Off-canvas mobile: lista <-> conversa <-> painel de detalhes ----
        var backBtn = document.getElementById('tcEvoBackBtn');
        if (backBtn) {
            backBtn.addEventListener('click', function () {
                window.location.href = urlBase;
            });
        }
        var showInfoBtn = document.getElementById('tcEvoShowInfo');
        var hideInfoBtn = document.getElementById('tcEvoHideInfo');
        var infoPane = document.getElementById('tcEvoInfoPane');
        if (showInfoBtn && infoPane) {
            showInfoBtn.addEventListener('click', function () { infoPane.classList.add('tc-evo-info-visible'); });
        }
        if (hideInfoBtn && infoPane) {
            hideInfoBtn.addEventListener('click', function () { infoPane.classList.remove('tc-evo-info-visible'); });
        }
    }

    document.addEventListener('DOMContentLoaded', initEvolutionInbox);
})();
