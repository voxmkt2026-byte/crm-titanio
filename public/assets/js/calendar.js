(function () {
    'use strict';

    function esc(value) {
        var element = document.createElement('div');
        element.textContent = value == null ? '' : String(value);
        return element.innerHTML;
    }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        }).then(function (response) { return response.json(); });
    }

    function showMessage(message, type) {
        if (window.Swal) {
            Swal.fire(type === 'error' ? 'Erro' : 'Pronto', message, type || 'success');
        }
    }

    function attachLeadPicker(wrapper, url) {
        if (!wrapper || !url) {
            return;
        }

        var input = wrapper.querySelector('.tc-lead-search');
        var hidden = wrapper.querySelector('input[type="hidden"]');
        var results = wrapper.querySelector('.tc-lookup-results');
        if (!input || !hidden || !results) {
            return;
        }

        var timer;
        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            hidden.value = '';
            input.classList.remove('is-valid');

            var term = input.value.trim();
            if (term.length < 1) {
                results.classList.remove('show');
                return;
            }

            results.innerHTML = '<div class="tc-lookup-empty">Buscando…</div>';
            results.classList.add('show');
            timer = window.setTimeout(function () {
                fetch(url + '?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('Falha na busca');
                        }
                        return response.json();
                    })
                    .then(function (data) {
                        var items = data.items || [];
                        results.innerHTML = '';
                        items.forEach(function (lead) {
                            var button = document.createElement('button');
                            button.type = 'button';
                            button.innerHTML = '<strong>' + esc(lead.name) + '</strong><small>#' + esc(lead.id)
                                + ' · ' + esc(lead.lead_code || 'sem código') + ' · ' + esc(lead.phone || '') + '</small>';
                            button.addEventListener('click', function () {
                                hidden.value = lead.id;
                                input.value = lead.name + (lead.phone ? ' · ' + lead.phone : '');
                                input.classList.add('is-valid');
                                results.classList.remove('show');
                            });
                            results.appendChild(button);
                        });
                        if (!items.length) {
                            results.innerHTML = '<div class="tc-lookup-empty">Nenhum lead encontrado.</div>';
                        }
                        results.classList.add('show');
                    })
                    .catch(function () {
                        results.innerHTML = '<div class="tc-lookup-empty text-danger">Não foi possível consultar agora.</div>';
                        results.classList.add('show');
                    });
            }, 160);
        });

        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target)) {
                results.classList.remove('show');
            }
        });
    }

    function toDateTimeInput(value) {
        if (!value) {
            return '';
        }
        var text = String(value);
        if (/^\d{4}-\d{2}-\d{2}$/.test(text)) {
            return text + 'T09:00';
        }
        return text.replace(' ', 'T').substring(0, 16);
    }

    function nowForDateTimeInput() {
        var now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        return now.toISOString().substring(0, 16);
    }

    var eventTypes = [
        ['reuniao', 'Reunião'],
        ['ligacao', 'Ligação'],
        ['visita', 'Visita'],
        ['follow_up', 'Follow-up'],
        ['tarefa', 'Tarefa'],
        ['outro', 'Outro']
    ];

    function eventTypeLabel(value) {
        for (var index = 0; index < eventTypes.length; index++) {
            if (eventTypes[index][0] === value) {
                return eventTypes[index][1];
            }
        }
        return 'Reunião';
    }

    function eventTypeOptions(selected) {
        return eventTypes.map(function (type) {
            return '<option value="' + type[0] + '"' + (type[0] === selected ? ' selected' : '') + '>' + type[1] + '</option>';
        }).join('');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var calendarElement = document.getElementById('tcFullCalendar');
        if (!calendarElement || !window.FullCalendar) {
            return;
        }

        calendarElement.dataset.calendarReady = '1';

        var users = Array.prototype.slice.call(calendarElement.querySelectorAll('.tc-calendar-user')).map(function (user) {
            return { id: user.dataset.id, name: user.dataset.name };
        });

        function userOptions(selected) {
            return '<option value="">Não atribuído</option>' + users.map(function (user) {
                return '<option value="' + user.id + '"' + (String(selected || '') === user.id ? ' selected' : '') + '>' + esc(user.name) + '</option>';
            }).join('');
        }

        function eventForm(event, props, start) {
            var end = event && event.endStr ? toDateTimeInput(event.endStr) : '';
            return [
                '<div class="text-start">',
                '<label class="form-label">Título</label>',
                '<input id="ceTitle" class="form-control mb-2" value="' + esc(event ? event.title : '') + '">',
                '<div class="row g-2">',
                '<div class="col-md-6"><label class="form-label">Início</label><input id="ceStart" type="datetime-local" class="form-control" value="' + esc(start) + '"></div>',
                '<div class="col-md-6"><label class="form-label">Fim</label><input id="ceEnd" type="datetime-local" class="form-control" value="' + esc(end) + '"></div>',
                '</div>',
                '<div class="row g-2 mt-1">',
                '<div class="col-md-6"><label class="form-label">Tipo do evento</label><select id="ceEventType" class="form-select">' + eventTypeOptions(props.event_type || 'reuniao') + '</select></div>',
                '<div class="col-md-6"><label class="form-label">Prioridade</label><select id="cePriority" class="form-select"><option value="baixa">Baixa</option><option value="media">Média</option><option value="alta">Alta</option><option value="urgente">Urgente</option></select></div>',
                '</div>',
                '<div class="row g-2 mt-1">',
                '<div class="col-md-6"><label class="form-label">Responsável / colaborador</label><select id="ceAssigned" class="form-select">' + userOptions(props.assigned_to) + '</select></div>',
                '<div class="col-md-6"><label class="form-label">Pessoa vinculada</label><input id="cePersonName" class="form-control" maxlength="180" value="' + esc(props.person_name || '') + '" placeholder="Cliente, parceiro ou contato"></div>',
                '</div>',
                '<label class="form-label mt-2">Lead vinculado</label>',
                '<div id="ceLeadPicker" class="tc-lead-picker" data-url="' + esc(calendarElement.dataset.leadSearch) + '">',
                '<input type="hidden" id="ceLead" value="' + esc(props.lead_id || '') + '">',
                '<input class="form-control tc-lead-search" value="' + esc(props.lead || '') + '" placeholder="Buscar por nome ou telefone">',
                '<div class="tc-lookup-results"></div></div>',
                '<label class="form-label mt-2">Descrição</label><textarea id="ceDesc" class="form-control" rows="2">' + esc(props.description || '') + '</textarea>',
                '<label class="form-label mt-2">Orientações para o colaborador</label><textarea id="ceGuidance" class="form-control" rows="2">' + esc(props.guidance || '') + '</textarea>',
                '<label class="form-check mt-2"><input class="form-check-input" type="checkbox" id="ceCreateTask" ' + (props.task_id ? 'checked disabled' : '') + '> <span class="form-check-label">Criar também uma tarefa para o colaborador</span></label>',
                '</div>'
            ].join('');
        }

        function editEvent(info) {
            var event = info && info.event;
            var props = event ? event.extendedProps : {};
            var start = info && info.dateStr ? toDateTimeInput(info.dateStr) : (event ? toDateTimeInput(event.startStr) : nowForDateTimeInput());

            Swal.fire({
                title: event ? 'Ver ou editar evento' : 'Novo evento',
                width: 700,
                html: eventForm(event, props, start),
                showCancelButton: true,
                showDenyButton: !!event,
                denyButtonText: 'Excluir evento',
                denyButtonColor: '#dc2626',
                confirmButtonText: 'Salvar alterações',
                cancelButtonText: 'Fechar',
                didOpen: function () {
                    document.getElementById('cePriority').value = props.priority || 'media';
                    attachLeadPicker(document.getElementById('ceLeadPicker'), calendarElement.dataset.leadSearch);
                },
                preConfirm: function () {
                    var title = document.getElementById('ceTitle').value.trim();
                    var startAt = document.getElementById('ceStart').value;
                    var endAt = document.getElementById('ceEnd').value;
                    if (!title || !startAt) {
                        Swal.showValidationMessage('Informe o título e o início do evento.');
                        return false;
                    }
                    if (endAt && endAt < startAt) {
                        Swal.showValidationMessage('O fim não pode ser anterior ao início.');
                        return false;
                    }
                    var priority = document.getElementById('cePriority').value;
                    return {
                        title: title,
                        start_at: startAt,
                        end_at: endAt,
                        event_type: document.getElementById('ceEventType').value,
                        priority: priority,
                        assigned_to: document.getElementById('ceAssigned').value,
                        person_name: document.getElementById('cePersonName').value.trim(),
                        lead_id: document.getElementById('ceLead').value,
                        description: document.getElementById('ceDesc').value,
                        guidance: document.getElementById('ceGuidance').value,
                        create_task: document.getElementById('ceCreateTask').checked ? '1' : '0',
                        color: { baixa: '#64748b', media: '#3b82f6', alta: '#f59e0b', urgente: '#dc2626' }[priority]
                    };
                }
            }).then(function (result) {
                if (result.isDenied && event) {
                    post(calendarElement.dataset.move + '/' + event.id + '/excluir', { csrf_token: calendarElement.dataset.csrf })
                        .then(function (response) {
                            if (response.success) {
                                calendar.refetchEvents();
                            } else {
                                showMessage(response.message, 'error');
                            }
                        });
                    return;
                }
                if (!result.isConfirmed) {
                    return;
                }
                post(calendarElement.dataset.save, Object.assign({ csrf_token: calendarElement.dataset.csrf, id: event ? event.id : '' }, result.value))
                    .then(function (response) {
                        if (response.success) {
                            calendar.refetchEvents();
                        } else {
                            showMessage(response.message || 'Não foi possível salvar o evento.', 'error');
                        }
                    })
                    .catch(function () { showMessage('Falha de comunicação ao salvar o evento.', 'error'); });
            });
        }

        function moveEvent(info) {
            post(calendarElement.dataset.move + '/' + info.event.id + '/mover', {
                csrf_token: calendarElement.dataset.csrf,
                start: info.event.startStr,
                end: info.event.endStr
            }).then(function (response) {
                if (!response.success) {
                    info.revert();
                }
            }).catch(function () { info.revert(); });
        }

        var calendar = new FullCalendar.Calendar(calendarElement, {
            locale: 'pt-br',
            initialView: 'dayGridMonth',
            height: 'auto',
            editable: true,
            selectable: true,
            nowIndicator: true,
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
            },
            buttonText: { today: 'Hoje', month: 'Mês', week: 'Semana', day: 'Dia', list: 'Lista' },
            events: calendarElement.dataset.events,
            dateClick: editEvent,
            eventClick: editEvent,
            eventDrop: moveEvent,
            eventResize: moveEvent,
            eventDidMount: function (info) {
                var props = info.event.extendedProps;
                var details = [
                    props.event_type ? 'Tipo: ' + eventTypeLabel(props.event_type) : '',
                    props.person_name ? 'Pessoa: ' + props.person_name : '',
                    props.lead ? 'Lead: ' + props.lead : '',
                    props.assigned ? 'Responsável: ' + props.assigned : '',
                    props.priority ? 'Prioridade: ' + props.priority : ''
                ].filter(Boolean);
                info.el.title = details.join(' · ');
            }
        });

        calendar.render();
        var newEventButton = document.getElementById('tcNewEvent');
        if (newEventButton) {
            newEventButton.addEventListener('click', function () {
                editEvent({ dateStr: nowForDateTimeInput() });
            });
        }
    });
})();
