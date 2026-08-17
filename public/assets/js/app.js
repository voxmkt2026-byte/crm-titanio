/**
 * public/assets/js/app.js
 * Scripts globais do Titanium CRM: dark mode, máscaras de input,
 * checagem AJAX de duplicidade e drag-and-drop do Kanban.
 */

(function () {
    'use strict';

    // ---------------- Utilitário compartilhado ----------------

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Feedback rápido não-bloqueante (Toastify.js), para casos em que o
    // SweetAlert2 (modal, bloqueia a tela) é pesado demais - ex: "Mensagem
    // enviada", "Tarefa concluída". Não substitui o SweetAlert2 em
    // confirmações/formulários, só complementa em avisos rápidos.
    function tcToast(message, type) {
        if (typeof Toastify === 'undefined') {
            return;
        }
        var colors = {
            success: '#16a34a',
            error: '#dc2626',
            warning: '#d97706',
            info: '#3b82f6'
        };
        Toastify({
            text: message,
            duration: 3000,
            gravity: 'top',
            position: 'right',
            close: true,
            stopOnFocus: true,
            style: { background: colors[type] || colors.info, borderRadius: '0.5rem' }
        }).showToast();
    }

    // ---------------- Dark mode ----------------

    function applyTheme(theme) {
        if (theme === 'dark') {
            document.body.classList.add('dark-mode');
        } else {
            document.body.classList.remove('dark-mode');
        }
    }

    function initTheme() {
        var saved = localStorage.getItem('tc-theme') || 'light';
        applyTheme(saved);

        var toggle = document.getElementById('tcThemeToggle');
        if (toggle) {
            updateToggleIcon(toggle, saved);
            toggle.addEventListener('click', function () {
                var current = localStorage.getItem('tc-theme') || 'light';
                var next = current === 'dark' ? 'light' : 'dark';
                localStorage.setItem('tc-theme', next);
                applyTheme(next);
                updateToggleIcon(toggle, next);
            });
        }
    }

    function updateToggleIcon(toggle, theme) {
        var icon = toggle.querySelector('i');
        if (!icon) return;
        icon.className = theme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    }

    // ---------------- Tema/cor dos balões de chat + papel de parede (Chat interno e Atendimento WhatsApp) ----------------
    // Preferência só do navegador (localStorage) — reaproveitada pelas duas telas, ver
    // app/views/partials/_chat_theme_picker.php. Roda em toda página (não só nas de chat)
    // porque o botão pode não existir ainda quando polling recria a lista de mensagens.
    function initChatTheme() {
        var VALID_THEMES = ['whatsapp', 'azul', 'roxo', 'grafite', 'rosa', 'esmeralda', 'laranja', 'ardosia'];
        var VALID_WALLPAPERS = ['none', 'pontos', 'linhas'];
        var VALID_SIZES = ['compacto', 'padrao', 'confortavel'];
        var VALID_FAMILIES = ['padrao', 'arredondada'];

        var theme = localStorage.getItem('tc-chat-theme') || 'whatsapp';
        if (VALID_THEMES.indexOf(theme) === -1) theme = 'whatsapp';
        var wallpaper = localStorage.getItem('tc-chat-wallpaper-mode') || 'none';
        if (VALID_WALLPAPERS.indexOf(wallpaper) === -1) wallpaper = 'none';
        var fontSize = localStorage.getItem('tc-chat-font-size') || 'padrao';
        if (VALID_SIZES.indexOf(fontSize) === -1) fontSize = 'padrao';
        var fontFamily = localStorage.getItem('tc-chat-font-family') || 'padrao';
        if (VALID_FAMILIES.indexOf(fontFamily) === -1) fontFamily = 'padrao';

        function apply() {
            document.body.setAttribute('data-chat-theme', theme);
            document.body.setAttribute('data-chat-font-size', fontSize);
            document.body.setAttribute('data-chat-font-family', fontFamily);
            document.querySelectorAll('.tc-chat-messages').forEach(function (el) {
                el.classList.remove('tc-wallpaper-pontos', 'tc-wallpaper-linhas');
                if (wallpaper !== 'none') el.classList.add('tc-wallpaper-' + wallpaper);
            });
            document.querySelectorAll('.tc-chat-theme-swatch').forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-theme') === theme);
            });
            document.querySelectorAll('[data-wallpaper]').forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-wallpaper') === wallpaper);
            });
            document.querySelectorAll('#tcChatFontSize').forEach(function (el) { el.value = fontSize; });
            document.querySelectorAll('#tcChatFontFamily').forEach(function (el) { el.value = fontFamily; });
        }
        apply();

        document.addEventListener('click', function (evt) {
            var swatch = evt.target.closest('.tc-chat-theme-swatch');
            if (swatch) {
                theme = swatch.getAttribute('data-theme');
                localStorage.setItem('tc-chat-theme', theme);
                apply();
                return;
            }
            var wpBtn = evt.target.closest('[data-wallpaper]');
            if (wpBtn) {
                wallpaper = wpBtn.getAttribute('data-wallpaper');
                localStorage.setItem('tc-chat-wallpaper-mode', wallpaper);
                apply();
            }
        });

        document.addEventListener('change', function (evt) {
            if (evt.target && evt.target.id === 'tcChatFontSize') {
                fontSize = evt.target.value;
                localStorage.setItem('tc-chat-font-size', fontSize);
                apply();
            }
            if (evt.target && evt.target.id === 'tcChatFontFamily') {
                fontFamily = evt.target.value;
                localStorage.setItem('tc-chat-font-family', fontFamily);
                apply();
            }
        });
    }

    // ---------------- Sidebar mobile (off-canvas + overlay) ----------------

    function initSidebarToggle() {
        var btn = document.getElementById('tcMobileToggle');
        var sidebar = document.querySelector('.tc-sidebar');
        var backdrop = document.getElementById('tcSidebarBackdrop');
        if (!btn || !sidebar) {
            return;
        }

        function openSidebar() {
            sidebar.classList.add('tc-sidebar-open');
            if (backdrop) {
                backdrop.classList.add('tc-sidebar-backdrop-show');
            }
        }

        function closeSidebar() {
            sidebar.classList.remove('tc-sidebar-open');
            if (backdrop) {
                backdrop.classList.remove('tc-sidebar-backdrop-show');
            }
        }

        btn.addEventListener('click', function () {
            if (sidebar.classList.contains('tc-sidebar-open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        // Fecha ao clicar no overlay escurecido
        if (backdrop) {
            backdrop.addEventListener('click', closeSidebar);
        }

        // Fecha ao clicar em qualquer item de menu (evita drawer aberto ao navegar)
        sidebar.querySelectorAll('.tc-sidebar-nav a').forEach(function (link) {
            link.addEventListener('click', closeSidebar);
        });

        // Fecha com a tecla Esc
        document.addEventListener('keydown', function (evt) {
            if (evt.key === 'Escape') {
                closeSidebar();
            }
        });

        // Se a tela for redimensionada para desktop com o drawer aberto, fecha
        window.addEventListener('resize', function () {
            if (window.innerWidth >= 992) {
                closeSidebar();
            }
        });
    }

    // ---------------- Máscaras de input ----------------

    function maskPhone(value) {
        var digits = value.replace(/\D/g, '').slice(0, 11);
        if (digits.length > 10) {
            return digits.replace(/(\d{2})(\d{5})(\d{0,4})/, function (m, a, b, c) {
                return c ? '(' + a + ') ' + b + '-' + c : '(' + a + ') ' + b;
            });
        }
        if (digits.length > 6) {
            return digits.replace(/(\d{2})(\d{4})(\d{0,4})/, function (m, a, b, c) {
                return c ? '(' + a + ') ' + b + '-' + c : '(' + a + ') ' + b;
            });
        }
        if (digits.length > 2) {
            return digits.replace(/(\d{2})(\d{0,5})/, '($1) $2');
        }
        return digits;
    }

    function maskCpf(value) {
        var digits = value.replace(/\D/g, '').slice(0, 11);
        return digits
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\d{3})(\d)/, '$1.$2')
            .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    }

    function maskCep(value) {
        var digits = value.replace(/\D/g, '').slice(0, 8);
        return digits.replace(/(\d{5})(\d{0,3})/, function (m, a, b) {
            return b ? a + '-' + b : a;
        });
    }

    function initMasks() {
        document.querySelectorAll('[data-mask="phone"]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.value = maskPhone(input.value);
            });
        });
        document.querySelectorAll('[data-mask="cpf"]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.value = maskCpf(input.value);
            });
        });
        document.querySelectorAll('[data-mask="cep"]').forEach(function (input) {
            input.addEventListener('input', function () {
                input.value = maskCep(input.value);
            });
        });
    }

    // ---------------- Checagem de duplicidade (Leads) ----------------

    function initDuplicateCheck() {
        var form = document.getElementById('leadForm');
        if (!form || typeof $ === 'undefined') {
            return;
        }

        var checkUrl = form.getAttribute('data-check-duplicate-url');
        var csrfToken = form.querySelector('input[name="csrf_token"]').value;
        var leadId = form.getAttribute('data-lead-id') || '';
        var duplicateConfirmed = false;

        form.addEventListener('submit', function (evt) {
            if (duplicateConfirmed) {
                return; // usuário já confirmou, prossegue com o submit
            }

            var phone = form.querySelector('[name="phone"]');
            var whatsapp = form.querySelector('[name="whatsapp"]');
            var cpf = form.querySelector('[name="cpf"]');
            var email = form.querySelector('[name="email"]');

            var hasAnyValue = (phone && phone.value) || (whatsapp && whatsapp.value) ||
                (cpf && cpf.value) || (email && email.value);

            if (!hasAnyValue) {
                return; // nada para checar
            }

            evt.preventDefault();

            $.ajax({
                url: checkUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    csrf_token: csrfToken,
                    phone: phone ? phone.value : '',
                    whatsapp: whatsapp ? whatsapp.value : '',
                    cpf: cpf ? cpf.value : '',
                    email: email ? email.value : '',
                    id: leadId
                }
            }).done(function (resp) {
                if (resp && resp.duplicate) {
                    var codeLine = resp.lead.lead_code
                        ? '<br>Código: <b>' + escapeHtml(resp.lead.lead_code) + '</b>'
                        : '';
                    Swal.fire({
                        icon: 'warning',
                        title: 'Possível lead duplicado',
                        html: 'Já existe um cadastro para <b>' + escapeHtml(resp.lead.name) + '</b> ' +
                            '(' + escapeHtml(resp.lead.phone) + ') com status <b>' + escapeHtml(resp.lead.status) + '</b>.' +
                            codeLine + '<br><br>O que você deseja fazer?',
                        showDenyButton: true,
                        showCancelButton: true,
                        reverseButtons: true,
                        confirmButtonText: 'Atualizar cadastro existente',
                        denyButtonText: 'Abrir cadastro existente',
                        cancelButtonText: 'Continuar cadastrando mesmo assim'
                    }).then(function (result) {
                        if (result.isConfirmed) {
                            window.location.href = resp.lead.editUrl;
                        } else if (result.isDenied) {
                            window.location.href = resp.lead.url;
                        } else if (result.dismiss === Swal.DismissReason.cancel) {
                            duplicateConfirmed = true;
                            form.submit();
                        }
                    });
                } else {
                    duplicateConfirmed = true;
                    form.submit();
                }
            }).fail(function () {
                // Se a checagem falhar, não bloqueia o cadastro
                duplicateConfirmed = true;
                form.submit();
            });
        });
    }

    // ---------------- Wizard do formulário de Lead (Fase 4) ----------------

    function initLeadWizard() {
        var form = document.getElementById('leadForm');
        if (!form) {
            return;
        }

        var panes = form.querySelectorAll('.tc-wizard-pane');
        var indicators = document.querySelectorAll('#tcWizardSteps .tc-wizard-step');
        var prevBtn = form.querySelector('.tc-wizard-prev');
        var nextBtn = form.querySelector('.tc-wizard-next');
        if (!panes.length || !nextBtn) {
            return;
        }

        var totalSteps = panes.length;
        var current = 1;

        function goToStep(step) {
            step = Math.max(1, Math.min(totalSteps, step));
            current = step;

            panes.forEach(function (pane) {
                var paneStep = parseInt(pane.getAttribute('data-step'), 10);
                pane.classList.toggle('d-none', paneStep !== current);
            });

            indicators.forEach(function (indicator) {
                var indicatorStep = parseInt(indicator.getAttribute('data-step-indicator'), 10);
                indicator.classList.toggle('active', indicatorStep === current);
                indicator.classList.toggle('completed', indicatorStep < current);
            });

            if (prevBtn) {
                prevBtn.classList.toggle('d-none', current === 1);
            }
            nextBtn.classList.toggle('d-none', current === totalSteps);

            // Rola suavemente até o topo do card ao trocar de aba
            var card = form.querySelector('.tc-card');
            if (card) {
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        nextBtn.addEventListener('click', function () {
            goToStep(current + 1);
        });

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                goToStep(current - 1);
            });
        }

        indicators.forEach(function (indicator) {
            indicator.addEventListener('click', function () {
                var step = parseInt(indicator.getAttribute('data-step-indicator'), 10);
                goToStep(step);
            });
        });

        goToStep(1);
    }

    // ---------------- Exclusão com confirmação (SweetAlert2) ----------------

    function initDeleteConfirm() {
        document.querySelectorAll('.tc-delete-form').forEach(function (form) {
            form.addEventListener('submit', function (evt) {
                if (form.dataset.confirmed === '1') {
                    return;
                }
                evt.preventDefault();

                Swal.fire({
                    icon: 'warning',
                    title: 'Tem certeza?',
                    text: form.dataset.confirmText || 'Esta ação não pode ser desfeita.',
                    showCancelButton: true,
                    confirmButtonText: 'Sim, excluir',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#dc2626'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        form.dataset.confirmed = '1';
                        form.submit();
                    }
                });
            });
        });
    }

    // ---------------- Kanban drag-and-drop (HTML5 DnD nativo) ----------------

    function initKanban() {
        var board = document.getElementById('tcKanbanBoard');
        if (!board) {
            return;
        }

        var moveUrl = board.getAttribute('data-move-url');
        var csrfToken = board.getAttribute('data-csrf-token');
        var leadModalEl = document.getElementById('tcPipelineLeadModal');
        var leadModal = leadModalEl && window.bootstrap ? new window.bootstrap.Modal(leadModalEl) : null;
        var suppressCardOpenUntil = 0;

        function setLeadModalText(id, value) {
            var field = document.getElementById(id);
            if (field) {
                field.textContent = value || '-';
            }
        }

        function setLeadModalAction(id, href) {
            var action = document.getElementById(id);
            if (!action) {
                return;
            }
            action.classList.toggle('d-none', !href);
            if (href) {
                action.setAttribute('href', href);
            } else {
                action.removeAttribute('href');
            }
        }

        function openLeadModal(card) {
            if (!leadModal) {
                var fallbackUrl = card.getAttribute('data-lead-url');
                if (fallbackUrl) {
                    window.location.href = fallbackUrl;
                }
                return;
            }

            setLeadModalText('tcPipelineLeadModalTitle', card.getAttribute('data-lead-name'));
            setLeadModalText('tcPipelineLeadPhone', card.getAttribute('data-phone'));
            setLeadModalText('tcPipelineLeadWhatsapp', card.getAttribute('data-whatsapp'));
            setLeadModalText('tcPipelineLeadEmail', card.getAttribute('data-email'));
            setLeadModalText('tcPipelineLeadCity', card.getAttribute('data-city'));
            setLeadModalText('tcPipelineLeadInterest', card.getAttribute('data-interest'));
            setLeadModalText('tcPipelineLeadSource', card.getAttribute('data-source'));
            setLeadModalText('tcPipelineLeadAssigned', card.getAttribute('data-assigned-name'));
            setLeadModalText('tcPipelineLeadScore', card.getAttribute('data-lead-score'));
            setLeadModalText('tcPipelineLeadLastContact', card.getAttribute('data-last-contact'));
            setLeadModalText('tcPipelineLeadCreatedAt', card.getAttribute('data-created-at'));

            var leadCode = document.getElementById('tcPipelineLeadCode');
            if (leadCode) {
                var code = card.getAttribute('data-lead-code');
                leadCode.textContent = code || '';
                leadCode.classList.toggle('d-none', !code);
            }

            var status = document.getElementById('tcPipelineLeadStatus');
            if (status) {
                status.textContent = card.getAttribute('data-lead-status') || '-';
                status.className = 'badge bg-' + (card.getAttribute('data-lead-status-color') || 'secondary');
            }

            setLeadModalAction('tcPipelineLeadCall', card.getAttribute('data-call-url'));
            setLeadModalAction('tcPipelineLeadEmailAction', card.getAttribute('data-email-url'));
            setLeadModalAction('tcPipelineLeadWhatsappAction', card.getAttribute('data-whatsapp-url'));
            setLeadModalAction('tcPipelineLeadOpen', card.getAttribute('data-lead-url'));
            leadModal.show();
        }

        var cards = board.querySelectorAll('.tc-kanban-card');
        cards.forEach(function (card) {
            card.setAttribute('draggable', 'true');

            card.addEventListener('dragstart', function () {
                card.classList.add('dragging');
                suppressCardOpenUntil = Date.now() + 800;
            });

            card.addEventListener('dragend', function () {
                card.classList.remove('dragging');
                suppressCardOpenUntil = Date.now() + 250;
            });

            card.addEventListener('click', function (evt) {
                if (Date.now() < suppressCardOpenUntil || evt.target.closest('button, a')) {
                    return;
                }
                openLeadModal(card);
            });

            card.addEventListener('keydown', function (evt) {
                if ((evt.key === 'Enter' || evt.key === ' ') && !evt.target.closest('button, a')) {
                    evt.preventDefault();
                    openLeadModal(card);
                }
            });
        });

        // Lista de estágios disponíveis, na ordem exibida (usada pelo fallback
        // de botão "Mover para" em telas touch/mobile, onde o HTML5 Drag and
        // Drop API nativo não é confiável — ver seção KANBAN no app.css).
        var stageNames = [];
        board.querySelectorAll('.tc-kanban-column-body').forEach(function (col) {
            stageNames.push(col.getAttribute('data-stage-name'));
        });

        function moveCardTo(cardEl, stageName) {
            var targetColumn = board.querySelector('.tc-kanban-column-body[data-stage-name="' + CSS.escape(stageName) + '"]');
            if (!targetColumn) {
                return;
            }
            var leadId = cardEl.getAttribute('data-lead-id');
            var previousParent = cardEl.parentElement;
            targetColumn.appendChild(cardEl);

            if (typeof $ === 'undefined') {
                return;
            }

            $.ajax({
                url: moveUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    csrf_token: csrfToken,
                    lead_id: leadId,
                    stage_name: stageName
                }
            }).done(function (resp) {
                if (!resp || !resp.success) {
                    previousParent.appendChild(cardEl);
                    if (typeof Swal !== 'undefined') {
                        Swal.fire('Erro', 'Não foi possível mover o lead.', 'error');
                    }
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'success', title: 'Lead movido!', timer: 1200, showConfirmButton: false });
                }
            }).fail(function () {
                previousParent.appendChild(cardEl);
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Erro', 'Falha de comunicação ao mover o lead.', 'error');
                }
            });
        }

        // Botões "Mover para" (fallback mobile, sempre presentes no DOM mas só
        // exibidos via CSS em telas <768px)
        board.querySelectorAll('.tc-kanban-move-btn').forEach(function (btn) {
            btn.addEventListener('click', function (evt) {
                evt.preventDefault();
                evt.stopPropagation();

                var card = btn.closest('.tc-kanban-card');
                if (!card) {
                    return;
                }
                var currentStage = card.closest('.tc-kanban-column-body');
                var currentStageName = currentStage ? currentStage.getAttribute('data-stage-name') : null;

                if (typeof Swal === 'undefined') {
                    return;
                }

                var options = stageNames
                    .filter(function (name) { return name !== currentStageName; })
                    .map(function (name) { return '<option value="' + escapeHtml(name) + '">' + escapeHtml(name) + '</option>'; })
                    .join('');

                Swal.fire({
                    title: 'Mover lead para...',
                    html: '<select id="tcMoveTargetStage" class="form-select">' + options + '</select>',
                    showCancelButton: true,
                    confirmButtonText: 'Mover',
                    cancelButtonText: 'Cancelar',
                    focusConfirm: false,
                    preConfirm: function () {
                        var sel = document.getElementById('tcMoveTargetStage');
                        return sel ? sel.value : null;
                    }
                }).then(function (result) {
                    if (result.isConfirmed && result.value) {
                        moveCardTo(card, result.value);
                    }
                });
            });
        });

        var columns = board.querySelectorAll('.tc-kanban-column-body');
        columns.forEach(function (column) {
            column.addEventListener('dragover', function (evt) {
                evt.preventDefault();
                column.classList.add('drag-over');
            });

            column.addEventListener('dragleave', function () {
                column.classList.remove('drag-over');
            });

            column.addEventListener('drop', function (evt) {
                evt.preventDefault();
                column.classList.remove('drag-over');

                var dragging = board.querySelector('.dragging');
                if (!dragging) {
                    return;
                }

                var leadId = dragging.getAttribute('data-lead-id');
                var stageName = column.getAttribute('data-stage-name');
                var previousParent = dragging.parentElement;

                column.appendChild(dragging);

                if (typeof $ === 'undefined') {
                    return;
                }

                $.ajax({
                    url: moveUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        csrf_token: csrfToken,
                        lead_id: leadId,
                        stage_name: stageName
                    }
                }).done(function (resp) {
                    if (!resp || !resp.success) {
                        previousParent.appendChild(dragging);
                        if (typeof Swal !== 'undefined') {
                            Swal.fire('Erro', 'Não foi possível mover o lead.', 'error');
                        }
                    }
                }).fail(function () {
                    previousParent.appendChild(dragging);
                    if (typeof Swal !== 'undefined') {
                        Swal.fire('Erro', 'Falha de comunicação ao mover o lead.', 'error');
                    }
                });
            });
        });
    }

    // ---------------- Notificações (Fase 3, polling sem websockets) ----------------

    function initNotifications() {
        var bell = document.getElementById('tcNotificationBell');
        if (!bell || typeof $ === 'undefined') {
            return;
        }

        var unreadUrl = bell.getAttribute('data-unread-url');
        var readUrlBase = bell.getAttribute('data-read-url-base');
        var readAllUrl = bell.getAttribute('data-read-all-url');
        var csrfToken = bell.getAttribute('data-csrf-token');

        var countBadge = document.getElementById('tcNotificationCount');
        var bellIcon = document.getElementById('tcNotifBellIcon');
        var subtitle = document.getElementById('tcNotifSubtitle');
        var list = document.getElementById('tcNotificationList');
        var markAllBtn = document.getElementById('tcMarkAllRead');
        var previousNotificationIds = null;
        var previousUnreadCount = 0;
        var panelOpen = false;

        // A permissão é solicitada somente após interação com o sino, como
        // exigido pelos navegadores modernos.
        bell.addEventListener('click', function () {
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission();
            }
        }, { once: true });

        bell.addEventListener('shown.bs.dropdown', function () { panelOpen = true; playEntranceAnimation(); });
        bell.addEventListener('hidden.bs.dropdown', function () { panelOpen = false; });

        function browserNotify(item) {
            if (!item || !('Notification' in window) || Notification.permission !== 'granted') return;
            var payload = { type: 'NOTIFY', title: item.title || 'Titanium CRM', body: item.message || '', url: item.link || window.location.href };
            if (navigator.serviceWorker && navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage(payload);
            } else {
                new Notification(payload.title, { body: payload.body });
            }
        }

        // Deriva um ícone/cor a partir do link da notificação (sem precisar mudar o back-end).
        function iconFor(item) {
            var link = (item.link || '') + ' ' + (item.title || '');
            if (/atendimento-whatsapp/.test(link)) return { cls: 'whatsapp', icon: 'fa-brands fa-whatsapp' };
            if (/\/chat\b|chat\?/.test(link)) return { cls: 'chat', icon: 'fa-solid fa-message' };
            if (/tarefas/.test(link)) return { cls: 'task', icon: 'fa-solid fa-list-check' };
            if (/meta|conquistad/i.test(link)) return { cls: 'goal', icon: 'fa-solid fa-bullseye' };
            if (/leads/.test(link)) return { cls: 'lead', icon: 'fa-solid fa-user' };
            return { cls: 'default', icon: 'fa-solid fa-bell' };
        }

        function itemHtml(item) {
            var icon = iconFor(item);
            var href = item.link || '#';
            return '<a href="' + href + '" class="tc-notification-item' + (item.read ? '' : ' unread') + '" data-id="' + item.id + '">' +
                '<span class="tc-notif-icon ' + icon.cls + '"><i class="' + icon.icon + '"></i></span>' +
                '<span class="flex-grow-1 min-w-0">' +
                '<span class="tc-notification-title">' + escapeHtml(item.title) + '</span>' +
                (item.message ? '<span class="tc-notification-body">' + escapeHtml(item.message) + '</span>' : '') +
                '<span class="tc-notification-meta" title="' + escapeHtml(item.created_at || '') + '">' + escapeHtml(item.time_ago) + '</span>' +
                '</span></a>';
        }

        function render(items) {
            if (!items || items.length === 0) {
                list.innerHTML = '<div class="tc-notif-empty"><i class="fa-regular fa-bell-slash"></i><span>Nenhuma notificação por aqui.</span></div>';
                return;
            }

            var unread = items.filter(function (i) { return !i.read; });
            var read = items.filter(function (i) { return i.read; });
            var html = '';
            if (unread.length) {
                html += '<div class="tc-notif-section-label">Novas</div>' + unread.map(itemHtml).join('');
            }
            if (read.length) {
                html += '<div class="tc-notif-section-label">Anteriores</div>' + read.map(itemHtml).join('');
            }
            list.innerHTML = html;

            if (typeof tippy !== 'undefined') {
                tippy('#tcNotificationList [title]', { theme: 'light-border', delay: [400, 0] });
            }
        }

        // Entrada em cascata dos itens quando o painel abre (anime.js, se disponível).
        function playEntranceAnimation() {
            if (typeof anime === 'undefined') return;
            var rows = list.querySelectorAll('.tc-notification-item');
            if (!rows.length) return;
            anime.set(rows, { opacity: 0, translateY: 6 });
            anime({
                targets: rows,
                opacity: [0, 1],
                translateY: [6, 0],
                easing: 'easeOutQuad',
                duration: 260,
                delay: anime.stagger(28),
            });
        }

        function ringBell() {
            if (!bellIcon) return;
            bellIcon.classList.remove('tc-notif-bell-ring');
            // força reflow para permitir reiniciar a animação em disparos seguidos
            void bellIcon.offsetWidth;
            bellIcon.classList.add('tc-notif-bell-ring');
        }

        function popBadge() {
            if (!countBadge || typeof anime === 'undefined') return;
            anime({ targets: countBadge, scale: [1, 1.45, 1], duration: 420, easing: 'easeOutElastic(1, .6)' });
        }

        function updateBadge(count) {
            if (!countBadge) return;
            if (count > 0) {
                countBadge.textContent = count > 99 ? '99+' : String(count);
                countBadge.classList.remove('d-none');
            } else {
                countBadge.classList.add('d-none');
            }
            if (subtitle) {
                subtitle.textContent = count > 0 ? (count === 1 ? '1 nova notificação' : count + ' novas notificações') : 'Tudo em dia';
            }
        }

        function refresh() {
            $.ajax({ url: unreadUrl, method: 'GET', dataType: 'json' })
                .done(function (resp) {
                    if (!resp) return;
                    var count = resp.count || 0;
                    var isFirstLoad = previousNotificationIds === null;

                    render(resp.items || []);
                    if (panelOpen) {
                        playEntranceAnimation();
                    }

                    var currentIds = (resp.items || []).filter(function (item) { return !item.read; }).map(function (item) { return String(item.id); });
                    if (!isFirstLoad) {
                        (resp.items || []).forEach(function (item) {
                            if (!item.read && previousNotificationIds.indexOf(String(item.id)) === -1) browserNotify(item);
                        });
                        if (count > previousUnreadCount) {
                            ringBell();
                            popBadge();
                        }
                    }
                    updateBadge(count);
                    previousNotificationIds = currentIds;
                    previousUnreadCount = count;
                });
        }

        list.addEventListener('click', function (evt) {
            var item = evt.target.closest('.tc-notification-item');
            if (!item) return;

            var id = item.getAttribute('data-id');
            if (!id) return;

            item.classList.remove('unread');
            $.ajax({
                url: readUrlBase + '/' + id + '/read',
                method: 'POST',
                dataType: 'json',
                data: { csrf_token: csrfToken }
            });
        });

        if (markAllBtn) {
            markAllBtn.addEventListener('click', function () {
                var unreadItems = list.querySelectorAll('.tc-notification-item.unread');
                if (typeof anime !== 'undefined' && unreadItems.length) {
                    anime({
                        targets: unreadItems,
                        opacity: [1, 0.35],
                        duration: 250,
                        easing: 'easeOutQuad',
                        complete: function () { unreadItems.forEach(function (el) { el.classList.remove('unread'); }); },
                    });
                } else {
                    unreadItems.forEach(function (el) { el.classList.remove('unread'); });
                }

                $.ajax({
                    url: readAllUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: { csrf_token: csrfToken }
                }).done(function () {
                    refresh();
                });
            });
        }

        refresh();
        setInterval(refresh, 45000); // 45s
    }

    // ---------------- Envio de WhatsApp (Fase 3, modal no perfil do lead) ----------------

    function initWhatsappForm() {
        var form = document.getElementById('tcWhatsappForm');
        if (!form || typeof $ === 'undefined') {
            return;
        }

        // Templates de WhatsApp (Fase 7 - auditoria UX): carrega o <select> sob
        // demanda e substitui os placeholders {{nome}}/{{interesse}}/{{responsavel}}
        // pelos dados do lead atual antes de preencher a textarea.
        var templateSelect = document.getElementById('tcWhatsappTemplateSelect');
        var templatesUrl = form.getAttribute('data-templates-url');
        var messageField = form.querySelector('[name="message"]');

        if (templateSelect && templatesUrl) {
            var templatesLoaded = false;
            var templatesData = {};

            form.closest('.modal').addEventListener('show.bs.modal', function () {
                if (templatesLoaded) return;
                templatesLoaded = true;
                $.ajax({ url: templatesUrl, method: 'GET', dataType: 'json' }).done(function (resp) {
                    var items = (resp && resp.items) || [];
                    items.forEach(function (tpl) {
                        templatesData[tpl.id] = tpl.content;
                        var opt = document.createElement('option');
                        opt.value = tpl.id;
                        opt.textContent = tpl.name;
                        templateSelect.appendChild(opt);
                    });
                });
            });

            templateSelect.addEventListener('change', function () {
                var content = templatesData[templateSelect.value];
                if (!content || !messageField) return;

                var replacements = {
                    '{{nome}}': form.getAttribute('data-lead-name') || '',
                    '{{interesse}}': form.getAttribute('data-lead-interest') || '',
                    '{{responsavel}}': form.getAttribute('data-lead-assigned') || ''
                };
                Object.keys(replacements).forEach(function (placeholder) {
                    content = content.split(placeholder).join(replacements[placeholder]);
                });
                messageField.value = content;
            });
        }

        form.addEventListener('submit', function (evt) {
            evt.preventDefault();

            var sendUrl = form.getAttribute('data-send-url');
            var csrfToken = form.getAttribute('data-csrf-token');
            var message = form.querySelector('[name="message"]').value;
            var submitBtn = form.querySelector('button[type="submit"]');

            if (submitBtn) {
                submitBtn.disabled = true;
            }

            $.ajax({
                url: sendUrl,
                method: 'POST',
                dataType: 'json',
                data: { csrf_token: csrfToken, message: message }
            }).done(function (resp) {
                if (resp && resp.success) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'success', title: 'Mensagem enviada!', timer: 2000, showConfirmButton: false });
                    }
                    var modalEl = document.getElementById('tcWhatsappModal');
                    if (modalEl && window.bootstrap) {
                        var modal = window.bootstrap.Modal.getInstance(modalEl) || new window.bootstrap.Modal(modalEl);
                        modal.hide();
                    }
                    form.reset();
                } else {
                    showWhatsappError(resp && resp.message);
                }
            }).fail(function (xhr) {
                var resp = xhr.responseJSON;
                showWhatsappError(resp && resp.message);
            }).always(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
            });
        });

        function showWhatsappError(message) {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Não foi possível enviar',
                    text: message || 'Verifique a configuração da WhatsApp Cloud API em Configurações.'
                });
            }
        }
    }

    // ---------------- Importação de leads via CSV (Fase 4) ----------------

    function initImportForms() {
        // Tela de upload/mapeamento: mostra um spinner enquanto o servidor
        // processa a planilha (upload + preview, ou o processamento final),
        // já que é tudo síncrono (sem fila/cron) e pode levar alguns segundos.
        document.querySelectorAll('.tc-import-loading-form').forEach(function (form) {
            form.addEventListener('submit', function () {
                if (typeof Swal === 'undefined') {
                    return;
                }
                Swal.fire({
                    title: 'Processando...',
                    text: form.dataset.loadingText || 'Aguarde enquanto os leads são importados.',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: function () {
                        Swal.showLoading();
                    }
                });
            });
        });

        // Tela de mapeamento: impede submit se nenhuma coluna foi mapeada
        var mappingForm = document.getElementById('tcImportMappingForm');
        if (mappingForm) {
            mappingForm.addEventListener('submit', function (evt) {
                var selects = mappingForm.querySelectorAll('select[name^="mapping["]');
                var mapped = Array.prototype.some.call(selects, function (sel) {
                    return sel.value && sel.value !== 'ignorar';
                });
                if (!mapped) {
                    evt.preventDefault();
                    if (typeof Swal !== 'undefined') {
                        Swal.fire('Atenção', 'Mapeie pelo menos uma coluna do arquivo antes de continuar.', 'warning');
                    }
                }
            });
        }
    }

    /* ==== TASKS MODULE ==== */
    // Ações rápidas de mudança de status (botões na listagem e na tela de
    // detalhe de tarefas - ver app/controllers/TaskController@changeStatus).
    // AJAX simples via fetch, mesmo padrão de confirmação/feedback (SweetAlert2)
    // já usado no restante do sistema (ex: Kanban).
    function tcTaskPostStatus(url, csrfToken, status, onSuccess) {
        var body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        body.set('status', status);

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (resp) {
            return resp.json().then(function (data) {
                return { ok: resp.ok, data: data };
            });
        }).then(function (result) {
            if (result.ok && result.data && result.data.success) {
                if (typeof onSuccess === 'function') {
                    onSuccess(result.data);
                }
            } else if (typeof Swal !== 'undefined') {
                Swal.fire('Erro', (result.data && result.data.message) || 'Não foi possível atualizar o status da tarefa.', 'error');
            }
        }).catch(function () {
            if (typeof Swal !== 'undefined') {
                Swal.fire('Erro', 'Falha de comunicação ao atualizar a tarefa.', 'error');
            }
        });
    }

    function initTaskQuickStatus() {
        // Botão "Concluir" na listagem (não recarrega a tela inteira)
        document.querySelectorAll('.tc-task-quick-status').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-url');
                var csrfToken = btn.getAttribute('data-csrf-token');
                var status = btn.getAttribute('data-status');
                btn.disabled = true;
                tcTaskPostStatus(url, csrfToken, status, function () {
                    tcToast('Tarefa concluída!', 'success');
                    window.setTimeout(function () { window.location.reload(); }, 600);
                });
                btn.disabled = false;
            });
        });

        // Botões de ação na tela de detalhe (Iniciar / Aguardando / Concluir / Cancelar / Reabrir)
        var actions = document.getElementById('tcTaskActions');
        if (!actions) {
            return;
        }
        var url = actions.getAttribute('data-url');
        var csrfToken = actions.getAttribute('data-csrf-token');

        actions.querySelectorAll('.tc-task-status-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var status = btn.getAttribute('data-status');
                btn.disabled = true;
                tcTaskPostStatus(url, csrfToken, status, function (data) {
                    tcToast('Status atualizado!', 'success');
                    window.setTimeout(function () { window.location.reload(); }, 500);
                });
            });
        });
    }

    // Quadro de tarefas: mantém a mudança de status no mesmo endpoint já
    // auditado pelo módulo. A troca é otimista, mas o card volta à coluna
    // anterior se o servidor rejeitar a alteração.
    function initTaskKanban() {
        var board = document.getElementById('tcTaskKanbanBoard');
        if (!board) {
            return;
        }

        var csrfToken = board.getAttribute('data-csrf-token');
        var statusLabels = {
            pendente: 'Pendentes',
            em_andamento: 'Em andamento',
            aguardando: 'Aguardando',
            concluida: 'Concluídas',
            cancelada: 'Canceladas'
        };

        function refreshCounters() {
            board.querySelectorAll('.tc-task-kanban-column').forEach(function (column) {
                var count = column.querySelectorAll('.tc-task-kanban-card').length;
                var badge = column.querySelector('.tc-kanban-column-header .badge');
                if (badge) {
                    badge.textContent = String(count);
                }
            });
        }

        function showError(message) {
            if (typeof Swal !== 'undefined') {
                Swal.fire('Erro', message || 'Não foi possível atualizar o status da tarefa.', 'error');
            } else {
                window.alert(message || 'Não foi possível atualizar o status da tarefa.');
            }
        }

        function moveCard(card, targetColumn) {
            var previousColumn = card.parentElement;
            var targetStatus = targetColumn.getAttribute('data-task-status');
            if (!previousColumn || previousColumn === targetColumn || !targetStatus) {
                return;
            }

            card.classList.add('tc-task-card-saving');
            targetColumn.appendChild(card);
            refreshCounters();

            var body = new URLSearchParams();
            body.set('csrf_token', csrfToken);
            body.set('status', targetStatus);

            fetch(card.getAttribute('data-status-url'), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    return { ok: response.ok, data: data };
                });
            }).then(function (result) {
                if (!result.ok || !result.data || !result.data.success) {
                    previousColumn.appendChild(card);
                    refreshCounters();
                    showError((result.data && result.data.message) || 'Não foi possível atualizar o status da tarefa.');
                    return;
                }
                tcToast('Tarefa movida para ' + (statusLabels[targetStatus] || 'a nova coluna') + '.', 'success');
            }).catch(function () {
                previousColumn.appendChild(card);
                refreshCounters();
                showError('Falha de comunicação ao atualizar a tarefa.');
            }).then(function () {
                card.classList.remove('tc-task-card-saving');
            });
        }

        board.querySelectorAll('.tc-task-kanban-card').forEach(function (card) {
            card.setAttribute('draggable', 'true');
            card.addEventListener('dragstart', function (event) {
                card.classList.add('dragging');
                if (event.dataTransfer) {
                    event.dataTransfer.effectAllowed = 'move';
                }
            });
            card.addEventListener('dragend', function () {
                card.classList.remove('dragging');
                board.querySelectorAll('.tc-task-kanban-column-body').forEach(function (column) {
                    column.classList.remove('drag-over');
                });
            });
        });

        board.querySelectorAll('.tc-task-kanban-column-body').forEach(function (column) {
            column.addEventListener('dragover', function (event) {
                event.preventDefault();
                column.classList.add('drag-over');
            });
            column.addEventListener('dragleave', function () {
                column.classList.remove('drag-over');
            });
            column.addEventListener('drop', function (event) {
                event.preventDefault();
                column.classList.remove('drag-over');
                var card = board.querySelector('.tc-task-kanban-card.dragging');
                if (card) {
                    moveCard(card, column);
                }
            });
        });

        // Em telas touch, o botão abre uma escolha de coluna: o HTML5 DnD
        // não funciona de forma consistente em navegadores móveis.
        board.querySelectorAll('.tc-task-kanban-move-btn').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var card = button.closest('.tc-task-kanban-card');
                var currentColumn = card ? card.closest('.tc-task-kanban-column-body') : null;
                if (!card || !currentColumn) {
                    return;
                }
                var currentStatus = currentColumn.getAttribute('data-task-status');
                var options = Object.keys(statusLabels).filter(function (status) {
                    return status !== currentStatus;
                });

                if (typeof Swal === 'undefined') {
                    return;
                }
                var html = '<select id="tcTaskKanbanMoveTarget" class="form-select">';
                options.forEach(function (status) {
                    html += '<option value="' + status + '">' + escapeHtml(statusLabels[status]) + '</option>';
                });
                html += '</select>';
                Swal.fire({
                    title: 'Mover tarefa para...',
                    html: html,
                    showCancelButton: true,
                    confirmButtonText: 'Mover',
                    cancelButtonText: 'Cancelar',
                    preConfirm: function () {
                        var select = document.getElementById('tcTaskKanbanMoveTarget');
                        return select ? select.value : null;
                    }
                }).then(function (result) {
                    if (!result.isConfirmed || !result.value) {
                        return;
                    }
                    var target = board.querySelector('.tc-task-kanban-column-body[data-task-status="' + result.value + '"]');
                    if (target) {
                        moveCard(card, target);
                    }
                });
            });
        });
    }

    // Um card de tarefa pode abrir (ou reutilizar) a conversa privada da
    // demanda. O servidor inclui automaticamente criador, responsável e
    // watchers, e só retorna a sala se o usuário atual puder acessá-la.
    function initTaskChatRooms() {
        document.querySelectorAll('.tc-task-chat-btn').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                var board = document.getElementById('tcTaskKanbanBoard');
                var chatUrl = button.getAttribute('data-chat-index-url') || (board && board.getAttribute('data-chat-index-url'));
                var csrfToken = button.getAttribute('data-csrf-token') || (board && board.getAttribute('data-csrf-token'));
                var endpoint = button.getAttribute('data-task-chat-url');
                if (!endpoint || !chatUrl || !csrfToken) {
                    return;
                }

                button.disabled = true;
                fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ csrf_token: csrfToken }).toString()
                }).then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                }).then(function (result) {
                    if (result.ok && result.data && result.data.success && result.data.room_id) {
                        window.location.href = chatUrl + '?sala=' + encodeURIComponent(result.data.room_id);
                        return;
                    }
                    if (typeof Swal !== 'undefined') {
                        Swal.fire('Atenção', (result.data && result.data.message) || 'Não foi possível abrir a conversa da tarefa.', 'warning');
                    }
                }).catch(function () {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire('Erro', 'Falha de comunicação ao abrir a conversa da tarefa.', 'error');
                    }
                }).then(function () {
                    button.disabled = false;
                });
            });
        });
    }

    // ---------------- Chat Interno (polling AJAX, sem websockets) ----------------
    //
    // Duas rotinas de polling independentes, ambas pausadas quando a aba do
    // navegador não está em foco (Page Visibility API), para não sobrecarregar
    // o servidor compartilhado da Hostinger:
    //   - initChatSidebarBadge(): roda em TODAS as telas (sidebar), intervalo
    //     mais longo (18s), só busca o contador total de não lidas.
    //   - initChat(): roda só dentro da tela /chat, intervalo curto (5s),
    //     busca mensagens novas da sala aberta via since_id (incremental).

    function initChatSidebarBadge() {
        var link = document.getElementById('tcChatSidebarLink');
        if (!link || typeof $ === 'undefined') {
            return;
        }
        var url = link.getAttribute('data-unread-url');
        var badge = document.getElementById('tcChatSidebarBadge');
        var previousCount = null;
        var onChatPage = !!document.getElementById('tcChatApp');

        function refresh() {
            if (document.hidden) {
                return;
            }
            $.ajax({ url: url, method: 'GET', dataType: 'json' }).done(function (resp) {
                if (!resp || !badge) return;
                var count = resp.count || 0;
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : String(count);
                    badge.classList.remove('d-none');
                } else {
                    badge.classList.add('d-none');
                }

                // Destaque visual + toast quando o total de não lidas aumenta
                // (ex: mensagem nova chegando enquanto o usuário está em outra
                // tela). Não repete na primeira leitura (previousCount null).
                if (previousCount !== null && count > previousCount) {
                    link.classList.remove('tc-unread-pulse');
                    void link.offsetWidth; // reinicia a animação CSS
                    link.classList.add('tc-unread-pulse');
                    if (!onChatPage) {
                        tcToast('Você tem novas mensagens no Chat.', 'info');
                    }
                }
                previousCount = count;
            });
        }

        refresh();
        setInterval(refresh, 18000); // 18s, fora da tela de chat
    }

    function initChat() {
        var app = document.getElementById('tcChatApp');
        if (!app || typeof $ === 'undefined') {
            return;
        }

        var csrfToken = app.getAttribute('data-csrf-token');
        var urlBase = app.getAttribute('data-url-base');
        var urlSearchUsers = app.getAttribute('data-url-search-users');
        var activeRoomId = parseInt(app.getAttribute('data-active-room'), 10) || 0;
        var canModerateActiveRoom = app.getAttribute('data-can-moderate-active-room') === '1';

        var messagesBox = document.getElementById('tcChatMessages');
        var messagesList = document.getElementById('tcChatMessagesList');
        var composer = document.getElementById('tcChatComposer');
        var input = document.getElementById('tcChatInput');
        var loadOlderBtn = document.getElementById('tcChatLoadOlder');

        function scrollToBottom() {
            if (messagesBox) {
                messagesBox.scrollTop = messagesBox.scrollHeight;
            }
        }
        scrollToBottom();

        function initials(name) {
            if (!name) return '?';
            var parts = name.trim().split(/\s+/);
            var res = parts[0].substr(0, 1);
            if (parts[1]) res += parts[1].substr(0, 1);
            return res.toUpperCase();
        }

        // A foto é lida sempre da resposta atual da API (users.avatar). Se o
        // arquivo falhar, mantemos as iniciais como fallback sem quebrar o chat.
        function userAvatarMarkup(user, className) {
            var avatar = user && (user.avatar || user.user_avatar) ? String(user.avatar || user.user_avatar) : '';
            var name = user && (user.name || user.user_name) ? String(user.name || user.user_name) : '';
            var classes = className + ' tc-chat-avatar-wrap' + (avatar ? ' tc-chat-avatar-has-photo' : '');
            var image = avatar ? '<img src="' + escapeHtml(avatar) + '" alt="" loading="lazy" onerror="this.remove();this.parentElement.classList.remove(\'tc-chat-avatar-has-photo\');">' : '';
            return '<span class="' + classes + '">' + image + '<span class="tc-chat-avatar-initials">' + escapeHtml((user && user.initials) || initials(name)) + '</span></span>';
        }

        function chatMarkdown(text) {
            return escapeHtml(text || '')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/^[-•] (.+)$/gm, '<span class="tc-chat-ai-item"><i class="fa-solid fa-check"></i>$1</span>')
                .replace(/\n/g, '<br>');
        }

        function lastRenderedUserId() {
            if (!messagesList) return undefined;
            var rows = messagesList.querySelectorAll('.tc-chat-bubble-row[data-user-id]');
            if (!rows.length) return undefined;
            var last = rows[rows.length - 1].getAttribute('data-user-id');
            return last === '' ? null : parseInt(last, 10);
        }

        function renderBubble(msg) {
            if (msg.type === 'sistema') {
                var sysDiv = document.createElement('div');
                sysDiv.className = 'tc-chat-system-msg tc-chat-msg-new';
                sysDiv.setAttribute('data-message-id', msg.id);
                sysDiv.textContent = msg.content || '';
                return sysDiv;
            }

            var isGrouped = msg.user_id != null && msg.user_id === lastRenderedUserId();
            var row = document.createElement('div');
            row.className = 'tc-chat-bubble-row tc-chat-msg-new' + (msg.mine ? ' mine' : '') + (isGrouped ? ' tc-grouped' : '');
            row.setAttribute('data-message-id', msg.id);
            row.setAttribute('data-user-id', msg.user_id != null ? msg.user_id : '');

            var html = '';
            if (!msg.mine) {
                html += userAvatarMarkup(msg, 'tc-chat-bubble-avatar');
            }
            html += '<div class="tc-chat-bubble">';
            if (!msg.mine && !isGrouped) {
                html += '<div class="tc-chat-bubble-author">' + escapeHtml(msg.user_name) + '</div>';
            }
            html += '<div class="tc-chat-bubble-content">';
            if (msg.deleted) {
                html += '<em class="text-muted"><i class="fa-solid fa-ban me-1"></i>Mensagem apagada</em>';
            } else {
                html += escapeHtml(msg.content || '').replace(/\n/g, '<br>');
            }
            html += '</div><div class="tc-chat-bubble-meta">' + escapeHtml(msg.time || '');
            if (msg.edited) {
                html += ' <span class="text-muted">(editada)</span>';
            }
            if (!msg.deleted && (msg.mine || canModerateActiveRoom)) {
                html += ' <button type="button" class="tc-chat-delete-btn" data-message-id="' + msg.id + '" title="Apagar mensagem"><i class="fa-solid fa-trash"></i></button>';
            }
            html += '</div></div>';

            row.innerHTML = html;
            return row;
        }

        function appendMessage(msg) {
            if (!messagesList) return;
            messagesList.appendChild(renderBubble(msg));
            if (messagesBox) {
                messagesBox.setAttribute('data-last-id', msg.id);
            }
        }

        function appendEphemeral(text) {
            if (!messagesList) return;
            var div = document.createElement('div');
            div.className = 'tc-chat-system-msg tc-chat-ephemeral tc-chat-msg-new';
            div.innerHTML = '<div class="tc-chat-ai-head"><i class="fa-solid fa-wand-magic-sparkles"></i><strong>Assistente Titanium</strong><span>visível só para você</span></div><div class="tc-chat-ai-body">' + chatMarkdown(text) + '</div>';
            messagesList.appendChild(div);
            scrollToBottom();
        }

        // ---- Envio de mensagens / comandos ----
        if (composer) {
            composer.addEventListener('submit', function (evt) {
                evt.preventDefault();
                sendMessage();
            });

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

        function sendMessage() {
            var content = input.value.trim();
            if (!content) {
                return;
            }
            var roomId = composer.getAttribute('data-room-id');

            input.value = '';
            input.style.height = 'auto';

            $.ajax({
                url: urlBase + '/salas/' + roomId + '/mensagens',
                method: 'POST',
                dataType: 'json',
                data: { csrf_token: csrfToken, content: content }
            }).done(function (resp) {
                if (!resp) return;

                if (resp.command === 'limpar') {
                    window.location.reload();
                    return;
                }
                if (resp.ephemeral) {
                    appendEphemeral(resp.message || '');
                    if (resp.success) {
                        poll(); // /adicionar e /remover geram mensagem de sistema real
                    }
                    return;
                }
                if (resp.success && resp.message) {
                    appendMessage(resp.message);
                    scrollToBottom();
                    return;
                }
                if (!resp.success && typeof Swal !== 'undefined') {
                    Swal.fire('Atenção', resp.message || 'Não foi possível enviar a mensagem.', 'warning');
                }
            }).fail(function () {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Erro', 'Falha de comunicação ao enviar a mensagem.', 'error');
                }
            });
        }

        // ---- Apagar mensagem (própria ou moderação) ----
        if (messagesList) {
            messagesList.addEventListener('click', function (evt) {
                var btn = evt.target.closest('.tc-chat-delete-btn');
                if (!btn) return;

                var messageId = btn.getAttribute('data-message-id');
                if (typeof Swal === 'undefined') {
                    return;
                }
                Swal.fire({
                    icon: 'warning',
                    title: 'Apagar mensagem?',
                    text: 'Esta ação não pode ser desfeita.',
                    showCancelButton: true,
                    confirmButtonText: 'Sim, apagar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#dc2626'
                }).then(function (result) {
                    if (!result.isConfirmed) return;

                    $.ajax({
                        url: urlBase + '/mensagens/' + messageId + '/apagar',
                        method: 'POST',
                        dataType: 'json',
                        data: { csrf_token: csrfToken }
                    }).done(function (resp) {
                        if (resp && resp.success) {
                            var row = messagesList.querySelector('[data-message-id="' + messageId + '"]');
                            if (row) {
                                var content = row.querySelector('.tc-chat-bubble-content');
                                var delBtn = row.querySelector('.tc-chat-delete-btn');
                                if (content) content.innerHTML = '<em class="text-muted"><i class="fa-solid fa-ban me-1"></i>Mensagem apagada</em>';
                                if (delBtn) delBtn.remove();
                            }
                        }
                    });
                });
            });
        }

        // ---- Polling incremental (since_id), pausado com a aba fora de foco ----
        function poll() {
            if (document.hidden || !messagesBox) {
                return;
            }
            var lastId = messagesBox.getAttribute('data-last-id') || '0';

            $.ajax({
                url: urlBase + '/salas/' + activeRoomId + '/mensagens',
                method: 'GET',
                dataType: 'json',
                data: { since_id: lastId }
            }).done(function (resp) {
                if (!resp || !resp.success || !resp.messages || !resp.messages.length) {
                    return;
                }
                var wasAtBottom = messagesBox.scrollTop + messagesBox.clientHeight >= messagesBox.scrollHeight - 40;
                resp.messages.forEach(function (msg) {
                    appendMessage(msg);
                });
                if (wasAtBottom) {
                    scrollToBottom();
                }
                // Marca como lida automaticamente enquanto a sala está aberta e em foco
                $.ajax({
                    url: urlBase + '/salas/' + activeRoomId + '/ler',
                    method: 'POST',
                    dataType: 'json',
                    data: { csrf_token: csrfToken }
                });
            });
        }

        if (activeRoomId) {
            setInterval(poll, 5000); // 5s, só enquanto a tela de chat está aberta
        }

        // ---- Carregar mensagens mais antigas (scroll para cima) ----
        if (loadOlderBtn && messagesList) {
            loadOlderBtn.classList.remove('d-none');
            loadOlderBtn.addEventListener('click', function () {
                var firstRow = messagesList.querySelector('[data-message-id]');
                var beforeId = firstRow ? firstRow.getAttribute('data-message-id') : null;
                if (!beforeId) return;

                $.ajax({
                    url: urlBase + '/salas/' + activeRoomId + '/mensagens',
                    method: 'GET',
                    dataType: 'json',
                    data: { before_id: beforeId }
                }).done(function (resp) {
                    if (!resp || !resp.success || !resp.messages) return;
                    if (!resp.messages.length) {
                        loadOlderBtn.textContent = 'Não há mensagens mais antigas';
                        loadOlderBtn.disabled = true;
                        return;
                    }
                    var previousHeight = messagesBox.scrollHeight;
                    var frag = document.createDocumentFragment();
                    resp.messages.forEach(function (msg) {
                        frag.appendChild(renderBubble(msg));
                    });
                    messagesList.insertBefore(frag, messagesList.firstChild);
                    messagesBox.scrollTop = messagesBox.scrollHeight - previousHeight;
                });
            });
        }

        // ---- Silenciar / reativar notificações da sala ----
        var muteBtn = document.getElementById('tcChatToggleMute');
        if (muteBtn) {
            muteBtn.addEventListener('click', function (evt) {
                evt.preventDefault();
                $.ajax({
                    url: urlBase + '/salas/' + activeRoomId + '/silenciar',
                    method: 'POST',
                    dataType: 'json',
                    data: { csrf_token: csrfToken }
                }).done(function (resp) {
                    if (!resp || !resp.success) return;
                    var label = document.getElementById('tcChatMuteLabel');
                    if (label) {
                        label.textContent = resp.muted ? 'Reativar notificações' : 'Silenciar sala';
                    }
                });
            });
        }

        // ---- Ver membros da sala ----
        var membersBtn = document.getElementById('tcChatShowMembers');
        var membersModalEl = document.getElementById('tcChatMembersModal');
        if (membersBtn && membersModalEl && window.bootstrap) {
            membersBtn.addEventListener('click', function (evt) {
                evt.preventDefault();
                $.ajax({
                    url: urlBase + '/salas/' + activeRoomId + '/membros',
                    method: 'GET',
                    dataType: 'json'
                }).done(function (resp) {
                    var list = document.getElementById('tcChatMembersList');
                    if (!list) return;
                    list.innerHTML = '';
                    if (!resp || !resp.success || !resp.members.length) {
                        list.innerHTML = '<li class="list-group-item text-muted">Nenhum membro encontrado.</li>';
                    } else {
                        resp.members.forEach(function (m) {
                            var roleLabel = m.role === 'admin_sala' ? 'Admin da sala' : (m.role === 'moderador' ? 'Moderador' : 'Membro');
                            list.innerHTML += '<li class="list-group-item d-flex justify-content-between align-items-center gap-2">' +
                                userAvatarMarkup(m, 'tc-chat-bubble-avatar') + '<span class="flex-grow-1">' + escapeHtml(m.name) + '</span>' +
                                '<span class="badge bg-secondary">' + escapeHtml(roleLabel) + '</span></li>';
                        });
                    }
                    new window.bootstrap.Modal(membersModalEl).show();
                });
            });
        }

        // ---- Voltar para a lista de salas no mobile (sem navegar) ----
        var backBtn = document.getElementById('tcChatBackBtn');
        if (backBtn) {
            backBtn.addEventListener('click', function () {
                app.classList.remove('tc-chat-has-active');
            });
        }

        // ---- Busca de colegas para iniciar uma DM ----
        function initUserSearch(inputId, resultsId, onPick) {
            var searchInput = document.getElementById(inputId);
            var resultsBox = document.getElementById(resultsId);
            if (!searchInput || !resultsBox) return;

            var timer = null;
            searchInput.addEventListener('input', function () {
                window.clearTimeout(timer);
                var term = searchInput.value.trim();
                if (term.length < 2) {
                    resultsBox.classList.add('d-none');
                    resultsBox.innerHTML = '';
                    return;
                }
                timer = window.setTimeout(function () {
                    $.ajax({
                        url: urlSearchUsers,
                        method: 'GET',
                        dataType: 'json',
                        data: { q: term }
                    }).done(function (resp) {
                        resultsBox.innerHTML = '';
                        if (!resp || !resp.users || !resp.users.length) {
                            resultsBox.innerHTML = '<div class="tc-chat-search-empty">Nenhum usuário encontrado.</div>';
                            resultsBox.classList.remove('d-none');
                            return;
                        }
                        resp.users.forEach(function (u) {
                            var item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'tc-chat-search-item';
                            item.innerHTML = userAvatarMarkup(u, 'tc-chat-room-avatar') +
                                '<span>' + escapeHtml(u.name) + '<br><small class="text-muted">' + escapeHtml(u.email) + '</small></span>';
                            item.addEventListener('click', function () {
                                onPick(u);
                                resultsBox.classList.add('d-none');
                                resultsBox.innerHTML = '';
                                searchInput.value = '';
                            });
                            resultsBox.appendChild(item);
                        });
                        resultsBox.classList.remove('d-none');
                    });
                }, 300);
            });
        }

        initUserSearch('tcChatUserSearch', 'tcChatUserSearchResults', function (u) {
            // Envia um POST para criar/abrir a DM (navegação normal, com CSRF)
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = urlBase + '/direto/' + u.id;
            var csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = csrfToken;
            form.appendChild(csrfInput);
            document.body.appendChild(form);
            form.submit();
        });

        // ---- Nova sala de grupo (modal) ----
        var newGroupBtn = document.getElementById('tcChatNewGroupBtn');
        var newGroupModalEl = document.getElementById('tcChatNewGroupModal');
        if (newGroupBtn && newGroupModalEl && window.bootstrap) {
            newGroupBtn.addEventListener('click', function () {
                new window.bootstrap.Modal(newGroupModalEl).show();
                window.setTimeout(function () {
                    var nameInput = newGroupModalEl.querySelector('input[name="name"]');
                    if (nameInput) nameInput.focus();
                }, 200);
            });
        }

        var selectedMembers = {};
        initUserSearch('tcChatGroupMemberSearch', 'tcChatGroupMemberResults', function (u) {
            if (selectedMembers[u.id]) return;
            selectedMembers[u.id] = u;
            renderSelectedMembers();
        });

        function renderSelectedMembers() {
            var box = document.getElementById('tcChatGroupMembersSelected');
            if (!box) return;
            box.innerHTML = '';
            Object.keys(selectedMembers).forEach(function (id) {
                var u = selectedMembers[id];
                var chip = document.createElement('span');
                chip.className = 'badge bg-secondary tc-chat-member-chip';
                chip.innerHTML = escapeHtml(u.name) + ' <i class="fa-solid fa-xmark ms-1" style="cursor:pointer;"></i>' +
                    '<input type="hidden" name="member_ids[]" value="' + u.id + '">';
                chip.querySelector('i').addEventListener('click', function () {
                    delete selectedMembers[id];
                    renderSelectedMembers();
                });
                box.appendChild(chip);
            });
        }

        var newGroupForm = document.getElementById('tcChatNewGroupForm');
        if (newGroupForm) {
            newGroupForm.addEventListener('submit', function (evt) {
                evt.preventDefault();
                $.ajax({
                    url: newGroupForm.getAttribute('action'),
                    method: 'POST',
                    dataType: 'json',
                    data: $(newGroupForm).serialize()
                }).done(function (resp) {
                    if (resp && resp.success) {
                        window.location.href = urlBase + '?sala=' + resp.room_id;
                    } else if (typeof Swal !== 'undefined') {
                        Swal.fire('Atenção', (resp && resp.message) || 'Não foi possível criar a sala.', 'warning');
                    }
                }).fail(function () {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire('Erro', 'Falha de comunicação ao criar a sala.', 'error');
                    }
                });
            });
        }

        // ---- Convidar pessoas para a sala aberta (admin/moderador) ----
        var inviteBtn = document.getElementById('tcChatInviteMembers');
        var inviteModalEl = document.getElementById('tcChatInviteMembersModal');
        if (inviteBtn && inviteModalEl && window.bootstrap) {
            inviteBtn.addEventListener('click', function (evt) {
                evt.preventDefault();
                new window.bootstrap.Modal(inviteModalEl).show();
            });
        }

        var inviteSelectedMembers = {};
        function renderInviteSelectedMembers() {
            var box = document.getElementById('tcChatInviteMembersSelected');
            if (!box) return;
            box.innerHTML = '';
            Object.keys(inviteSelectedMembers).forEach(function (id) {
                var u = inviteSelectedMembers[id];
                var chip = document.createElement('span');
                chip.className = 'badge bg-secondary tc-chat-member-chip';
                chip.innerHTML = escapeHtml(u.name) + ' <i class="fa-solid fa-xmark ms-1" style="cursor:pointer;"></i>' +
                    '<input type="hidden" name="member_ids[]" value="' + u.id + '">';
                chip.querySelector('i').addEventListener('click', function () {
                    delete inviteSelectedMembers[id];
                    renderInviteSelectedMembers();
                });
                box.appendChild(chip);
            });
        }

        initUserSearch('tcChatInviteMemberSearch', 'tcChatInviteMemberResults', function (u) {
            if (inviteSelectedMembers[u.id]) return;
            inviteSelectedMembers[u.id] = u;
            renderInviteSelectedMembers();
        });

        var inviteForm = document.getElementById('tcChatInviteMembersForm');
        if (inviteForm) {
            inviteForm.addEventListener('submit', function (evt) {
                evt.preventDefault();
                $.ajax({
                    url: inviteForm.getAttribute('action'),
                    method: 'POST',
                    dataType: 'json',
                    data: $(inviteForm).serialize()
                }).done(function (resp) {
                    if (resp && resp.success) {
                        inviteSelectedMembers = {};
                        renderInviteSelectedMembers();
                        if (window.bootstrap && inviteModalEl) {
                            (window.bootstrap.Modal.getInstance(inviteModalEl) || new window.bootstrap.Modal(inviteModalEl)).hide();
                        }
                        var memberCount = document.getElementById('tcChatMemberCount');
                        if (memberCount && typeof resp.member_count !== 'undefined') {
                            memberCount.textContent = String(resp.member_count);
                        }
                        tcToast(resp.message || 'Convite enviado com sucesso.', 'success');
                    } else if (typeof Swal !== 'undefined') {
                        Swal.fire('Atenção', (resp && resp.message) || 'Não foi possível enviar o convite.', 'warning');
                    }
                }).fail(function (xhr) {
                    var resp = xhr.responseJSON;
                    if (typeof Swal !== 'undefined') {
                        Swal.fire('Erro', (resp && resp.message) || 'Falha de comunicação ao enviar o convite.', 'error');
                    }
                });
            });
        }

        // Atalhos do compositor e sugestões de menção (@nome).
        var composerSuggestions = document.getElementById('tcChatComposerSuggestions');
        document.querySelectorAll('[data-chat-insert]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (!input) return;
                input.value = button.getAttribute('data-chat-insert') || '';
                input.focus();
                input.dispatchEvent(new Event('input'));
            });
        });

        if (input && composerSuggestions) {
            var mentionTimer = null;
            input.addEventListener('input', function () {
                window.clearTimeout(mentionTimer);
                var match = input.value.slice(0, input.selectionStart).match(/(?:^|\s)@([^\s@]{2,})$/);
                if (!match) {
                    composerSuggestions.classList.add('d-none');
                    composerSuggestions.innerHTML = '';
                    return;
                }
                mentionTimer = window.setTimeout(function () {
                    $.getJSON(urlSearchUsers, { q: match[1] }).done(function (resp) {
                        composerSuggestions.innerHTML = '';
                        (resp.users || []).forEach(function (u) {
                            var option = document.createElement('button');
                            option.type = 'button';
                            option.innerHTML = userAvatarMarkup(u, 'tc-chat-room-avatar') + '<span><strong>' + escapeHtml(u.name) + '</strong><small>' + escapeHtml(u.email) + '</small></span>';
                            option.addEventListener('click', function () {
                                input.value = input.value.replace(/@[^\s@]+$/, '@' + u.name.replace(/\s+/g, '_') + ' ');
                                composerSuggestions.classList.add('d-none');
                                input.focus();
                            });
                            composerSuggestions.appendChild(option);
                        });
                        composerSuggestions.classList.toggle('d-none', !resp.users || !resp.users.length);
                    });
                }, 220);
            });
        }
    }

    /* ==== BUSCA GLOBAL (Fase 7 - auditoria UX) ==== */
    // Campo de busca no topbar: nome, telefone, e-mail ou lead_code, com
    // debounce de ~300ms, consultando GET /leads/buscar-rapido.

    function initGlobalSearch() {
        var wrap = document.getElementById('tcGlobalSearch');
        if (!wrap || typeof $ === 'undefined') {
            return;
        }

        var input = document.getElementById('tcGlobalSearchInput');
        var results = document.getElementById('tcGlobalSearchResults');
        var searchUrl = wrap.getAttribute('data-search-url');
        var debounceTimer = null;
        var currentRequest = null;

        function renderResults(items) {
            if (!items || items.length === 0) {
                results.innerHTML = '<div class="tc-global-search-empty">Nenhum lead encontrado.</div>';
                results.classList.add('show');
                return;
            }

            var html = '';
            items.forEach(function (item) {
                html += '<a href="' + item.url + '" class="tc-global-search-item">' +
                    '<div class="tc-gs-name">' + escapeHtml(item.name) + '</div>' +
                    '<div class="tc-gs-meta">' + escapeHtml(item.lead_code || '') +
                    (item.phone ? ' · ' + escapeHtml(item.phone) : '') +
                    ' · ' + escapeHtml(item.status) + '</div>' +
                    '</a>';
            });
            results.innerHTML = html;
            results.classList.add('show');
        }

        input.addEventListener('input', function () {
            var term = input.value.trim();

            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }

            if (term.length < 1) {
                results.classList.remove('show');
                results.innerHTML = '';
                return;
            }

            debounceTimer = setTimeout(function () {
                if (currentRequest && currentRequest.abort) {
                    currentRequest.abort();
                }
                currentRequest = $.ajax({
                    url: searchUrl,
                    method: 'GET',
                    dataType: 'json',
                    data: { q: term }
                }).done(function (resp) {
                    renderResults(resp && resp.items);
                });
            }, 300);
        });

        document.addEventListener('click', function (evt) {
            if (!wrap.contains(evt.target)) {
                results.classList.remove('show');
            }
        });

        input.addEventListener('focus', function () {
            if (results.innerHTML !== '') {
                results.classList.add('show');
            }
        });
    }

    /* ==== NOTA RÁPIDA (Fase 7 - auditoria UX) ==== */
    // Observação rápida via AJAX/SweetAlert2, disponível na listagem de
    // Leads, no card do Kanban e no perfil do lead. Reaproveita o mesmo
    // endpoint POST /leads/{id}/nota-rapida.

    function tcSubmitQuickNote(url, csrfToken, note, onSuccess) {
        if (typeof $ === 'undefined') return;
        $.ajax({
            url: url,
            method: 'POST',
            dataType: 'json',
            data: { csrf_token: csrfToken, note: note }
        }).done(function (resp) {
            if (resp && resp.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({ icon: 'success', title: resp.message || 'Observação registrada!', timer: 1500, showConfirmButton: false });
                }
                if (typeof onSuccess === 'function') {
                    onSuccess(resp);
                }
            } else if (typeof Swal !== 'undefined') {
                Swal.fire('Atenção', (resp && resp.message) || 'Não foi possível registrar a observação.', 'warning');
            }
        }).fail(function (xhr) {
            var resp = xhr.responseJSON;
            if (typeof Swal !== 'undefined') {
                Swal.fire('Erro', (resp && resp.message) || 'Falha de comunicação ao registrar a observação.', 'error');
            }
        });
    }

    function tcPromptQuickNote(url, csrfToken, onSuccess) {
        if (typeof Swal === 'undefined') return;
        Swal.fire({
            title: 'Nota rápida',
            html: '<textarea id="tcQuickNoteText" class="form-control" rows="4" placeholder="Escreva uma observação..."></textarea>',
            showCancelButton: true,
            confirmButtonText: 'Registrar',
            cancelButtonText: 'Cancelar',
            focusConfirm: false,
            preConfirm: function () {
                var el = document.getElementById('tcQuickNoteText');
                var val = el ? el.value.trim() : '';
                if (!val) {
                    Swal.showValidationMessage('Escreva uma observação antes de registrar.');
                    return false;
                }
                return val;
            }
        }).then(function (result) {
            if (result.isConfirmed && result.value) {
                tcSubmitQuickNote(url, csrfToken, result.value, onSuccess);
            }
        });
    }

    function initQuickNoteButtons() {
        // Listagem de Leads: cada linha tem seu próprio botão com data-url/data-csrf-token.
        document.querySelectorAll('.tc-quick-note-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-url');
                var token = btn.getAttribute('data-csrf-token');
                tcPromptQuickNote(url, token);
            });
        });

        // Kanban: botão fica no board, usa data-note-url-base + lead-id.
        var board = document.getElementById('tcKanbanBoard');
        if (board) {
            var noteUrlBase = board.getAttribute('data-note-url-base');
            var csrfToken = board.getAttribute('data-csrf-token');

            board.querySelectorAll('.tc-kanban-note-btn').forEach(function (btn) {
                btn.addEventListener('click', function (evt) {
                    evt.preventDefault();
                    evt.stopPropagation();
                    var leadId = btn.getAttribute('data-lead-id');
                    var url = noteUrlBase + '/' + leadId + '/nota-rapida';
                    tcPromptQuickNote(url, csrfToken);
                });
            });
        }
    }

    // ---------------- Transferência de responsável na listagem de Leads ----------------

    function initLeadTransfer() {
        var modalEl = document.getElementById('tcLeadTransferModal');
        var form = document.getElementById('tcLeadTransferForm');
        if (!modalEl || !form || !window.bootstrap || typeof $ === 'undefined') {
            return;
        }

        var modal = new window.bootstrap.Modal(modalEl);
        var nameEl = document.getElementById('tcLeadTransferName');
        var assignedSelect = document.getElementById('tcLeadTransferAssigned');
        var currentUrl = '';

        document.querySelectorAll('.tc-lead-transfer-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                currentUrl = btn.getAttribute('data-url') || '';
                if (nameEl) {
                    nameEl.textContent = btn.getAttribute('data-lead-name') || 'este lead';
                }
                if (assignedSelect) {
                    assignedSelect.value = btn.getAttribute('data-current-assigned-to') || '';
                }
                modal.show();
            });
        });

        form.addEventListener('submit', function (evt) {
            evt.preventDefault();

            var assignedTo = assignedSelect ? assignedSelect.value : '';
            if (!currentUrl || !assignedTo) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Atenção', 'Selecione o novo responsável.', 'warning');
                }
                return;
            }

            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
            }

            $.ajax({
                url: currentUrl,
                method: 'POST',
                dataType: 'json',
                data: {
                    csrf_token: form.getAttribute('data-csrf-token'),
                    assigned_to: assignedTo
                }
            }).done(function (resp) {
                if (resp && resp.success) {
                    modal.hide();
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'success', title: resp.message || 'Lead transferido!', timer: 1500, showConfirmButton: false })
                            .then(function () { window.location.reload(); });
                    } else {
                        window.location.reload();
                    }
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire('Atenção', (resp && resp.message) || 'Não foi possível transferir o lead.', 'warning');
                }
            }).fail(function (xhr) {
                var resp = xhr.responseJSON;
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Erro', (resp && resp.message) || 'Falha de comunicação ao transferir o lead.', 'error');
                }
            }).always(function () {
                if (submitBtn) {
                    submitBtn.disabled = false;
                }
            });
        });
    }

    /* ==== AÇÕES EM LOTE NA LISTAGEM DE LEADS (Fase 7 - auditoria UX) ==== */

    function initBulkActions() {
        var bar = document.getElementById('tcBulkBar');
        var table = document.getElementById('tcLeadsTable');
        if (!bar || !table || typeof $ === 'undefined') {
            return;
        }

        var selectAll = document.getElementById('tcSelectAll');
        var rowChecks = table.querySelectorAll('.tc-row-check');
        var countLabel = document.getElementById('tcBulkCount');
        var clearBtn = document.getElementById('tcBulkClear');
        var url = bar.getAttribute('data-url');
        var csrfToken = bar.getAttribute('data-csrf-token');

        function selectedIds() {
            return Array.prototype.slice.call(table.querySelectorAll('.tc-row-check:checked')).map(function (el) {
                return el.value;
            });
        }

        function refresh() {
            var ids = selectedIds();
            countLabel.textContent = ids.length + ' selecionado(s)';
            bar.classList.toggle('show', ids.length > 0);
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                rowChecks.forEach(function (chk) { chk.checked = selectAll.checked; });
                refresh();
            });
        }

        rowChecks.forEach(function (chk) {
            chk.addEventListener('change', refresh);
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                rowChecks.forEach(function (chk) { chk.checked = false; });
                if (selectAll) selectAll.checked = false;
                refresh();
            });
        }

        var actionSelects = {
            status: document.getElementById('tcBulkStatus'),
            assigned_to: document.getElementById('tcBulkAssigned'),
            tag: document.getElementById('tcBulkTag')
        };

        bar.querySelectorAll('[data-bulk-action]').forEach(function (applyBtn) {
            applyBtn.addEventListener('click', function () {
                var action = applyBtn.getAttribute('data-bulk-action');
                var select = actionSelects[action];
                var value = select ? select.value : '';
                var ids = selectedIds();

                if (!value) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire('Atenção', 'Selecione um valor antes de aplicar.', 'warning');
                    }
                    return;
                }
                if (ids.length === 0) {
                    return;
                }

                if (typeof Swal === 'undefined') return;

                Swal.fire({
                    title: 'Confirmar ação em lote?',
                    text: ids.length + ' lead(s) serão afetados.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Confirmar',
                    cancelButtonText: 'Cancelar'
                }).then(function (result) {
                    if (!result.isConfirmed) return;

                    $.ajax({
                        url: url,
                        method: 'POST',
                        dataType: 'json',
                        data: { csrf_token: csrfToken, action: action, value: value, ids: ids }
                    }).done(function (resp) {
                        if (resp && resp.success) {
                            Swal.fire({ icon: 'success', title: (resp.affected || 0) + ' lead(s) atualizado(s)!', timer: 1500, showConfirmButton: false })
                                .then(function () { window.location.reload(); });
                        } else {
                            Swal.fire('Erro', (resp && resp.message) || 'Não foi possível executar a ação em lote.', 'error');
                        }
                    }).fail(function (xhr) {
                        var resp = xhr.responseJSON;
                        Swal.fire('Erro', (resp && resp.message) || 'Falha de comunicação ao executar a ação em lote.', 'error');
                    });
                });
            });
        });

        refresh();
    }

    /* ==== OBSERVAÇÃO RÁPIDA VIA AJAX NO PERFIL DO LEAD (Fase 7 - auditoria UX) ==== */

    function initLeadQuickNoteForm() {
        var form = document.querySelector('.tc-quick-note-form');
        if (!form || typeof $ === 'undefined') {
            return;
        }

        form.addEventListener('submit', function (evt) {
            evt.preventDefault();

            var url = form.getAttribute('data-ajax-url');
            var csrfToken = form.getAttribute('data-csrf-token');
            var textarea = form.querySelector('[name="note"]');
            var note = textarea.value.trim();
            var submitBtn = form.querySelector('button[type="submit"]');

            if (!note) return;
            if (submitBtn) submitBtn.disabled = true;

            $.ajax({
                url: url,
                method: 'POST',
                dataType: 'json',
                data: { csrf_token: csrfToken, note: note }
            }).done(function (resp) {
                if (resp && resp.success) {
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon: 'success', title: 'Observação registrada!', timer: 1500, showConfirmButton: false });
                    }
                    var list = document.getElementById('tcHistoryList');
                    var emptyMsg = document.querySelector('.tc-history-empty');
                    if (emptyMsg) emptyMsg.style.display = 'none';
                    if (list) {
                        var li = document.createElement('li');
                        li.className = 'mb-3 pb-3 border-bottom';
                        li.innerHTML = '<div class="d-flex gap-2">' +
                            '<i class="fa-solid fa-note-sticky text-secondary mt-1"></i>' +
                            '<div><div style="font-size:0.85rem;">' + escapeHtml(note) + '</div>' +
                            '<div class="text-muted" style="font-size:0.72rem;">agora mesmo</div></div></div>';
                        list.insertBefore(li, list.firstChild);
                    }
                    textarea.value = '';
                } else if (typeof Swal !== 'undefined') {
                    Swal.fire('Atenção', (resp && resp.message) || 'Não foi possível registrar a observação.', 'warning');
                }
            }).fail(function (xhr) {
                var resp = xhr.responseJSON;
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Erro', (resp && resp.message) || 'Falha de comunicação ao registrar a observação.', 'error');
                }
            }).always(function () {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    }

    // ---------------- PWA: registra o Service Worker (instalável + ponte de notificação) ----------------
    // Ver public/sw.js e public/manifest.json. Registro simples, sem prompt — instalar
    // fica a critério do navegador/usuário (ícone "Instalar app" na barra de endereço).
    function initServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            return;
        }
        navigator.serviceWorker.register('/sw.js').catch(function (err) {
            console.warn('Service Worker não registrado:', err);
        });
    }

    // ---------------- Sala privada de um Lead ----------------
    // A abertura acontece no perfil do lead. A busca usa o mesmo endpoint
    // seguro do chat e o servidor confirma tanto a permissão sobre o lead
    // quanto a participação na sala antes de liberar o histórico.
    function initLeadChatRoom() {
        var form = document.getElementById('tcLeadChatRoomForm');
        if (!form || typeof $ === 'undefined') {
            return;
        }

        var searchInput = document.getElementById('tcLeadChatMemberSearch');
        var resultsBox = document.getElementById('tcLeadChatMemberResults');
        var selectedBox = document.getElementById('tcLeadChatMembersSelected');
        var searchUrl = form.getAttribute('data-search-url');
        var chatUrl = form.getAttribute('data-chat-url');
        var selectedMembers = {};
        var timer = null;

        function renderSelectedMembers() {
            if (!selectedBox) return;
            selectedBox.innerHTML = '';
            Object.keys(selectedMembers).forEach(function (id) {
                var u = selectedMembers[id];
                var chip = document.createElement('span');
                chip.className = 'badge bg-secondary tc-chat-member-chip';
                chip.innerHTML = escapeHtml(u.name) + ' <i class="fa-solid fa-xmark ms-1" style="cursor:pointer;"></i>' +
                    '<input type="hidden" name="member_ids[]" value="' + u.id + '">';
                chip.querySelector('i').addEventListener('click', function () {
                    delete selectedMembers[id];
                    renderSelectedMembers();
                });
                selectedBox.appendChild(chip);
            });
        }

        if (searchInput && resultsBox) {
            searchInput.addEventListener('input', function () {
                window.clearTimeout(timer);
                var term = searchInput.value.trim();
                if (term.length < 2) {
                    resultsBox.classList.add('d-none');
                    resultsBox.innerHTML = '';
                    return;
                }
                timer = window.setTimeout(function () {
                    $.ajax({
                        url: searchUrl,
                        method: 'GET',
                        dataType: 'json',
                        data: { q: term }
                    }).done(function (resp) {
                        resultsBox.innerHTML = '';
                        if (!resp || !resp.users || !resp.users.length) {
                            resultsBox.innerHTML = '<div class="tc-chat-search-empty">Nenhum usuário encontrado.</div>';
                            resultsBox.classList.remove('d-none');
                            return;
                        }
                        resp.users.forEach(function (u) {
                            var item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'tc-chat-search-item';
                            item.innerHTML = '<span class="tc-chat-room-avatar">' + escapeHtml(u.initials) + '</span>' +
                                '<span>' + escapeHtml(u.name) + '<br><small class="text-muted">' + escapeHtml(u.email) + '</small></span>';
                            item.addEventListener('click', function () {
                                selectedMembers[u.id] = u;
                                renderSelectedMembers();
                                resultsBox.classList.add('d-none');
                                resultsBox.innerHTML = '';
                                searchInput.value = '';
                            });
                            resultsBox.appendChild(item);
                        });
                        resultsBox.classList.remove('d-none');
                    });
                }, 280);
            });
        }

        form.addEventListener('submit', function (evt) {
            evt.preventDefault();
            var submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            $.ajax({
                url: form.getAttribute('action'),
                method: 'POST',
                dataType: 'json',
                data: $(form).serialize()
            }).done(function (resp) {
                if (resp && resp.success) {
                    window.location.href = chatUrl + '?sala=' + resp.room_id;
                    return;
                }
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Atenção', (resp && resp.message) || 'Não foi possível abrir a sala.', 'warning');
                }
            }).fail(function (xhr) {
                var resp = xhr.responseJSON;
                if (typeof Swal !== 'undefined') {
                    Swal.fire('Erro', (resp && resp.message) || 'Falha de comunicação ao abrir a sala.', 'error');
                }
            }).always(function () {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    }

    // Cada init roda isolado: um erro em uma função (ex: uma tela que não tem
    // certo elemento no DOM) nunca deve travar as demais — foi exatamente esse
    // tipo de falha silenciosa que deixou o sino de notificações "sem estilo"
    // quando algo antes dele na lista quebrava a execução do script inteiro.
    function safeInit(name, fn) {
        try {
            fn();
        } catch (err) {
            console.error('Falha ao iniciar "' + name + '":', err);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        safeInit('serviceWorker', initServiceWorker);
        safeInit('theme', initTheme);
        safeInit('chatTheme', initChatTheme);
        safeInit('sidebarToggle', initSidebarToggle);
        safeInit('masks', initMasks);
        safeInit('duplicateCheck', initDuplicateCheck);
        safeInit('leadWizard', initLeadWizard);
        safeInit('deleteConfirm', initDeleteConfirm);
        safeInit('kanban', initKanban);
        safeInit('notifications', initNotifications);
        safeInit('whatsappForm', initWhatsappForm);
        safeInit('importForms', initImportForms);
        safeInit('taskQuickStatus', initTaskQuickStatus);
        safeInit('taskKanban', initTaskKanban);
        safeInit('taskChatRooms', initTaskChatRooms);
        safeInit('chatSidebarBadge', initChatSidebarBadge);
        safeInit('chat', initChat);
        safeInit('globalSearch', initGlobalSearch);
        safeInit('quickNoteButtons', initQuickNoteButtons);
        safeInit('leadTransfer', initLeadTransfer);
        safeInit('bulkActions', initBulkActions);
        safeInit('leadQuickNoteForm', initLeadQuickNoteForm);
        safeInit('leadChatRoom', initLeadChatRoom);
    });
})();
