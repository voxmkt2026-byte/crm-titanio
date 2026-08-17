/**
 * public/assets/js/agenda.js
 * Tela de Agenda: "Registrar contato agora" (por linha) e "Novo Agendamento"
 * (agenda o próximo contato de um lead já cadastrado). Extraído de um bloco
 * <script> inline gigante embutido via concatenação de string PHP (ver
 * histórico de app/views/agenda/index.php) para um arquivo estático — HTML
 * inline muito grande é o tipo de coisa que camadas de cache/minificação da
 * hospedagem cortam no meio de forma intermitente, deixando a página em
 * branco com só o final do script (ex: "});") visível.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var app = document.getElementById('tcAgendaApp');
        if (!app || typeof $ === 'undefined') {
            return;
        }

        var csrfToken = app.getAttribute('data-csrf-token');
        var quickContactUrlBase = app.getAttribute('data-quick-contact-url-base');
        var searchUrl = app.getAttribute('data-search-url');
        var scheduleUrl = app.getAttribute('data-schedule-url');

        document.querySelectorAll('.tc-agenda-quick-contact').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (typeof Swal === 'undefined') {
                    return;
                }
                var leadId = btn.getAttribute('data-lead-id');
                var leadName = btn.getAttribute('data-lead-name');

                Swal.fire({
                    title: 'Registrar contato agora',
                    html:
                        '<p class="text-muted mb-2" style="font-size:0.85rem;">Lead: <strong>' + leadName + '</strong></p>' +
                        '<select id="tcQuickType" class="form-select mb-2">' +
                            '<option value="contato">Contato (observação manual)</option>' +
                            '<option value="ligacao">Ligação</option>' +
                            '<option value="whatsapp">WhatsApp</option>' +
                        '</select>' +
                        '<textarea id="tcQuickDescription" class="form-control mb-2" rows="3" placeholder="O que foi tratado no contato?"></textarea>' +
                        '<label class="form-label mb-1" style="font-size:0.8rem;">Próximo contato (opcional - deixe vazio para não reagendar)</label>' +
                        '<input type="datetime-local" id="tcQuickNext" class="form-control">',
                    showCancelButton: true,
                    confirmButtonText: 'Registrar',
                    cancelButtonText: 'Cancelar',
                    focusConfirm: false,
                    preConfirm: function () {
                        var description = document.getElementById('tcQuickDescription').value.trim();
                        if (!description) {
                            Swal.showValidationMessage('Descreva o que foi tratado no contato.');
                            return false;
                        }
                        return {
                            type: document.getElementById('tcQuickType').value,
                            description: description,
                            next_contact_at: document.getElementById('tcQuickNext').value
                        };
                    }
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }
                    $.ajax({
                        url: quickContactUrlBase + '/' + leadId + '/quick-contact',
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            csrf_token: csrfToken,
                            type: result.value.type,
                            description: result.value.description,
                            next_contact_at: result.value.next_contact_at
                        }
                    }).done(function (resp) {
                        if (resp && resp.success) {
                            var extra = '';
                            if (resp.open_task_warning) {
                                extra = '<p class="mt-2" style="font-size:0.8rem;">' + resp.open_task_warning + '</p>';
                            }
                            Swal.fire({ icon: 'success', title: 'Contato registrado!', html: extra, timer: extra ? undefined : 1500, showConfirmButton: !!extra })
                                .then(function () { window.location.reload(); });
                        } else {
                            Swal.fire('Erro', (resp && resp.message) || 'Não foi possível registrar o contato.', 'error');
                        }
                    }).fail(function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Falha de comunicação ao registrar o contato.';
                        Swal.fire('Erro', msg, 'error');
                    });
                });
            });
        });

        // ---- "Novo Agendamento": busca um lead já cadastrado (reaproveita o
        // mesmo endpoint da busca global do topbar) e define o próximo contato
        // dele. Agendar aqui NUNCA cria um evento solto - é sempre vinculado a
        // um lead existente, mantendo a lógica do restante do sistema. ----
        var newBtn = document.getElementById('tcAgendaNewBtn');

        if (newBtn) {
            newBtn.addEventListener('click', function () {
                if (typeof Swal === 'undefined') {
                    return;
                }

                Swal.fire({
                    title: 'Novo Agendamento',
                    html:
                        '<p class="text-muted mb-2" style="font-size:0.8rem;text-align:left;">' +
                        'Agendar aqui significa definir o <strong>próximo contato</strong> de um lead já cadastrado ' +
                        '(não é um evento de calendário solto).</p>' +
                        '<div class="text-start">' +
                        '<label class="form-label mb-1" style="font-size:0.8rem;">Lead</label>' +
                        '<input type="text" id="tcAgSearchInput" class="form-control mb-1" placeholder="Buscar por nome, telefone, e-mail ou código..." autocomplete="off">' +
                        '<div id="tcAgSearchResults" class="tc-agenda-search-results d-none"></div>' +
                        '<div id="tcAgSelectedLead" class="d-none tc-agenda-selected-lead mb-2"></div>' +
                        '<input type="hidden" id="tcAgLeadId" value="">' +
                        '<label class="form-label mb-1" style="font-size:0.8rem;">Data/hora do agendamento</label>' +
                        '<input type="datetime-local" id="tcAgDate" class="form-control mb-2">' +
                        '<label class="form-label mb-1" style="font-size:0.8rem;">Observação (opcional)</label>' +
                        '<textarea id="tcAgNote" class="form-control" rows="2" placeholder="O que deve ser tratado neste contato?"></textarea>' +
                        '</div>',
                    showCancelButton: true,
                    confirmButtonText: 'Agendar',
                    cancelButtonText: 'Cancelar',
                    focusConfirm: false,
                    didOpen: function () {
                        var searchInput = document.getElementById('tcAgSearchInput');
                        var resultsBox = document.getElementById('tcAgSearchResults');
                        var selectedBox = document.getElementById('tcAgSelectedLead');
                        var leadIdInput = document.getElementById('tcAgLeadId');
                        var debounceTimer = null;

                        searchInput.addEventListener('input', function () {
                            var term = searchInput.value.trim();
                            leadIdInput.value = '';
                            selectedBox.classList.add('d-none');

                            if (debounceTimer) {
                                clearTimeout(debounceTimer);
                            }
                            if (term.length < 1) {
                                resultsBox.classList.add('d-none');
                                resultsBox.innerHTML = '';
                                return;
                            }
                            debounceTimer = setTimeout(function () {
                                $.ajax({ url: searchUrl, method: 'GET', dataType: 'json', data: { q: term } })
                                    .done(function (resp) {
                                        var items = (resp && resp.items) || [];
                                        if (!items.length) {
                                            resultsBox.innerHTML = '<div class="tc-agenda-search-empty">Nenhum lead encontrado.</div>';
                                            resultsBox.classList.remove('d-none');
                                            return;
                                        }
                                        var html = '';
                                        items.forEach(function (item) {
                                            item.name = item.name || ('Lead #' + item.id);
                                            item.status = item.status || 'Sem status';
                                            html += '<div class="tc-agenda-search-item" data-id="' + item.id + '" data-name="' +
                                                item.name.replace(/"/g, '&quot;') + '" data-status="' + item.status.replace(/"/g, '&quot;') + '">' +
                                                '<strong>' + item.name + '</strong>' +
                                                '<span>' + (item.lead_code || '') + (item.phone ? ' · ' + item.phone : '') + ' · ' + item.status + '</span>' +
                                                '</div>';
                                        });
                                        resultsBox.innerHTML = html;
                                        resultsBox.classList.remove('d-none');
                                    }).fail(function () {
                                        resultsBox.innerHTML = '<div class="tc-agenda-search-empty text-danger">Não foi possível buscar agora. Tente novamente.</div>';
                                        resultsBox.classList.remove('d-none');
                                    });
                            }, 300);
                        });

                        resultsBox.addEventListener('click', function (evt) {
                            var item = evt.target.closest('.tc-agenda-search-item');
                            if (!item) return;
                            leadIdInput.value = item.getAttribute('data-id');
                            selectedBox.innerHTML = '<i class="fa-solid fa-user-check me-1"></i>Selecionado: <strong>' +
                                item.getAttribute('data-name') + '</strong> (' + item.getAttribute('data-status') + ')';
                            selectedBox.classList.remove('d-none');
                            resultsBox.classList.add('d-none');
                            searchInput.value = '';
                        });
                    },
                    preConfirm: function () {
                        var leadId = document.getElementById('tcAgLeadId').value;
                        var date = document.getElementById('tcAgDate').value;
                        if (!leadId) {
                            Swal.showValidationMessage('Busque e selecione um lead para agendar.');
                            return false;
                        }
                        if (!date) {
                            Swal.showValidationMessage('Informe a data/hora do agendamento.');
                            return false;
                        }
                        return {
                            lead_id: leadId,
                            next_contact_at: date,
                            note: document.getElementById('tcAgNote').value.trim()
                        };
                    }
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }
                    $.ajax({
                        url: scheduleUrl,
                        method: 'POST',
                        dataType: 'json',
                        data: {
                            csrf_token: csrfToken,
                            lead_id: result.value.lead_id,
                            next_contact_at: result.value.next_contact_at,
                            note: result.value.note
                        }
                    }).done(function (resp) {
                        if (resp && resp.success) {
                            Swal.fire({ icon: 'success', title: 'Agendamento criado!', timer: 1500, showConfirmButton: false })
                                .then(function () { window.location.reload(); });
                        } else {
                            Swal.fire('Erro', (resp && resp.message) || 'Não foi possível criar o agendamento.', 'error');
                        }
                    }).fail(function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) || 'Falha de comunicação ao criar o agendamento.';
                        Swal.fire('Erro', msg, 'error');
                    });
                });
            });
        }
    });
})();
