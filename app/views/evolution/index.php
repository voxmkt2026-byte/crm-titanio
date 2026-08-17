<?php
/**
 * app/views/evolution/index.php
 * Atendimento WhatsApp (Evolution API) — inbox estilo "Zap Responder": lista
 * de conversas à esquerda, thread ativa ao centro e um painel de ações
 * (vincular lead, transferir, etiquetas) à direita. Toda a interatividade é
 * feita via public/assets/js/evolution.js, com polling AJAX incremental
 * contra o NOSSO banco (mesmo padrão sem WebSockets do Chat interno — ver
 * EvolutionInboxController e EvolutionWebhookController).
 */
$isManager = Auth::hasRole(['admin', 'supervisor']);
$canCreateTask = Auth::can('tasks.create');
$tcEvoInstanceLabels = [];
foreach (($connections ?? []) as $tcEvoConnection) {
    $tcEvoInstanceLabels[(string) $tcEvoConnection['instance_name']] = (string) ($tcEvoConnection['label'] ?: $tcEvoConnection['instance_name']);
}
$tcEvoActiveLink = $active['link'] ?? [];
$tcEvoActiveFlow = null;
if (!empty($tcEvoActiveLink['flow_id'])) {
    foreach (($flowOptions ?? []) as $tcEvoFlow) {
        if ((int) $tcEvoFlow['id'] === (int) $tcEvoActiveLink['flow_id']) {
            $tcEvoActiveFlow = $tcEvoFlow;
            break;
        }
    }
}
$tcEvoFlowSteps = $tcEvoActiveFlow ? (json_decode((string) ($tcEvoActiveFlow['steps_json'] ?? '[]'), true) ?: []) : [];
$tcEvoFlowStep = min((int) ($tcEvoActiveLink['flow_step'] ?? 0), max(0, count($tcEvoFlowSteps) - 1));
$tcEvoCurrentFlowStep = $tcEvoFlowSteps[$tcEvoFlowStep] ?? null;
$tcEvoInstanceQuery = !empty($selectedInstance) ? '&instancia=' . rawurlencode($selectedInstance) : '';

function tc_evo_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    if (empty($parts) || $parts[0] === '') {
        return '?';
    }
    $i = substr($parts[0], 0, 1);
    if (isset($parts[1])) {
        $i .= substr($parts[1], 0, 1);
    }
    return strtoupper($i);
}

/** Avatar real (foto de perfil do WhatsApp) quando disponível, senão iniciais coloridas — mesmo padrão do Chatwoot/Zap Responder. */
function tc_evo_avatar(string $name, string $avatarUrl, string $class = 'tc-chat-room-avatar'): string
{
    if ($avatarUrl !== '') {
        return '<div class="' . $class . ' tc-evo-avatar-photo" style="background-image:url(\'' . e($avatarUrl) . '\')"></div>';
    }
    return '<div class="' . $class . '">' . e(tc_evo_initials($name)) . '</div>';
}

/** Cor determinística por nome de etiqueta (mesmo hash sempre gera a mesma cor), estilo labels do Chatwoot. */
function tc_evo_label_color(string $label): string
{
    $hue = crc32(strtolower(trim($label))) % 360;
    return 'hsl(' . $hue . ', 65%, 42%)';
}

$filterTabs = ['all' => 'Todas', 'unread' => 'Não lidas'];
if ($isManager) {
    $filterTabs['mine'] = 'Minhas';
    $filterTabs['unassigned'] = 'Sem responsável';
}
?>

<?php if ($error): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if (!$clientConfigured && $isManager): ?>
    <div class="alert alert-info">
        <i class="fa-solid fa-circle-info me-1"></i>
        Configure a URL, o token (apikey) e o nome da instância em <a href="<?= e(url('configuracoes')) ?>">Configurações &gt; Atendimento WhatsApp</a> para começar a enviar mensagens e receber em tempo real.
    </div>
<?php elseif ($connectionStatus !== null && strtolower($connectionStatus) !== 'open' && $isManager): ?>
    <div class="alert alert-warning">
        <i class="fa-solid fa-triangle-exclamation me-1"></i>
        A instância da Evolution API está com status "<?= e($connectionStatus) ?>" (não conectada). Gere um novo QR Code em <a href="<?= e(url('configuracoes')) ?>">Configurações</a> e escaneie novamente no WhatsApp.
    </div>
<?php endif; ?>

<div class="tc-chat-app tc-evo-app <?= $active ? 'tc-chat-has-active' : '' ?>"
     id="tcEvoApp"
     data-csrf-token="<?= e(Csrf::token()) ?>"
     data-active-id="<?= e($active['id'] ?? '') ?>"
     data-last-message-id="<?= !empty($messages) ? (int) end($messages)['id'] : 0 ?>"
     data-url-base="<?= e(url('atendimento-whatsapp')) ?>"
     data-agenda-url="<?= e(url('agenda/agendar')) ?>"
     data-is-manager="<?= $isManager ? '1' : '0' ?>"
     data-lead-name="<?= e((string) ($active['link']['lead_name'] ?? '')) ?>"
     data-lead-interest="<?= e((string) ($active['link']['lead_interest'] ?? '')) ?>"
     data-assigned-name="<?= e((string) ($active['link']['assigned_name'] ?? (Auth::user()['name'] ?? ''))) ?>">

    <!-- Coluna esquerda: lista de conversas -->
    <aside class="tc-chat-rooms" id="tcEvoRoomsPane">
        <div class="tc-chat-rooms-header">
            <div>
                <h6 class="mb-0">Atendimento WhatsApp</h6>
                <small class="text-muted">
                    <?php if ($connectionStatus !== null): ?>
                        <span class="tc-evo-status-dot <?= strtolower($connectionStatus) === 'open' ? 'tc-evo-status-on' : 'tc-evo-status-off' ?>"></span>
                        <?= strtolower($connectionStatus) === 'open' ? 'Conectado' : 'Desconectado' ?>
                    <?php else: ?>
                        Evolution API
                    <?php endif; ?>
                </small>
            </div>
            <?php if ($isManager && $clientConfigured): ?>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="tcEvoSyncBtn" title="Importar conversas já existentes na Evolution">
                    <i class="fa-solid fa-rotate"></i>
                </button>
            <?php endif; ?>
        </div>

        <?php if ($isManager && $stats): ?>
        <div class="tc-evo-stats px-2">
            <div><strong><?= (int) $stats['total'] ?></strong><span>Total</span></div>
            <div><strong><?= (int) $stats['unread'] ?></strong><span>Não lidas</span></div>
            <div><strong><?= (int) $stats['unassigned'] ?></strong><span>Sem responsável</span></div>
        </div>
        <?php endif; ?>

        <form method="GET" action="<?= e(url('atendimento-whatsapp')) ?>" class="tc-chat-search px-2 pt-2">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" name="q" class="form-control" placeholder="Buscar por nome, telefone ou mensagem..." value="<?= e($q) ?>">
            </div>
            <input type="hidden" name="filtro" value="<?= e($filter) ?>">
            <?php if (!empty($connections)): ?>
            <select name="instancia" class="form-select form-select-sm mt-2" onchange="this.form.submit()">
                <option value="">Todas as linhas</option>
                <?php foreach ($connections as $connection): ?>
                    <?php if (!empty($connection['active'])): ?>
                    <option value="<?= e($connection['instance_name']) ?>" <?= $selectedInstance === $connection['instance_name'] ? 'selected' : '' ?>><?= e($connection['label'] ?: $connection['instance_name']) ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </form>

        <ul class="nav nav-tabs tc-chat-tabs px-2 pt-2">
            <?php foreach ($filterTabs as $key => $label): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $filter === $key ? 'active' : '' ?>"
                       href="<?= e(url('atendimento-whatsapp') . '?filtro=' . rawurlencode($key) . ($q !== '' ? '&q=' . rawurlencode($q) : '') . $tcEvoInstanceQuery) ?>"><?= e($label) ?></a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="tc-chat-room-list">
            <?php if (empty($conversations)): ?>
                <div class="tc-chat-empty">Nenhuma conversa encontrada<?php if (!$isManager): ?> atribuída a você<?php endif; ?>.</div>
            <?php endif; ?>
            <?php foreach ($conversations as $conv): ?>
                <?php $isActive = $active && $active['id'] === $conv['id']; $assignedName = $conv['link']['assigned_name'] ?? null; ?>
                <a href="<?= e(url('atendimento-whatsapp') . '?conversa=' . rawurlencode($conv['id']) . '&filtro=' . rawurlencode($filter) . $tcEvoInstanceQuery) ?>"
                   class="tc-chat-room-item tc-evo-room-item <?= $isActive ? 'active' : '' ?>" data-conversation-id="<?= e($conv['id']) ?>">
                    <?= tc_evo_avatar($conv['name'], $conv['avatar'] ?? '') ?>
                    <div class="tc-chat-room-info">
                        <div class="tc-chat-room-name"><?= e($conv['name']) ?></div>
                        <div class="tc-chat-room-preview"><?= e($conv['last_message'] !== '' ? mb_strimwidth($conv['last_message'], 0, 42, '...') : ($conv['phone'] !== '' ? format_phone($conv['phone']) : 'Sem mensagens ainda')) ?></div>
                        <?php if (!empty($conv['labels'])): ?>
                            <div class="tc-evo-label-chips">
                                <?php foreach (array_slice($conv['labels'], 0, 3) as $label): ?>
                                    <span class="tc-evo-label-chip" style="background:<?= e(tc_evo_label_color((string) $label)) ?>"><?= e((string) $label) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($assignedName): ?>
                            <div class="tc-evo-room-assignee"><i class="fa-solid fa-user"></i> <?= e($assignedName) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($conv['unread'] > 0): ?>
                        <span class="badge rounded-pill bg-danger tc-chat-room-badge"><?= $conv['unread'] > 99 ? '99+' : $conv['unread'] ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <!-- Coluna central: conversa ativa -->
    <section class="tc-chat-main" id="tcEvoMainPane">
        <?php if (!$active): ?>
            <div class="tc-chat-placeholder">
                <i class="fa-brands fa-whatsapp"></i>
                <h5>Atendimento ao vivo</h5><p>Selecione uma conversa à esquerda para responder o cliente.</p>
            </div>
        <?php else: ?>
            <header class="tc-chat-header">
                <button type="button" class="tc-icon-btn d-lg-none" id="tcEvoBackBtn" title="Voltar"><i class="fa-solid fa-arrow-left"></i></button>
                <?= tc_evo_avatar($active['name'], $active['avatar'] ?? '', 'tc-chat-header-avatar') ?>
                <div class="flex-grow-1">
                    <div class="tc-chat-header-title"><?= e($active['name']) ?></div>
                    <div class="tc-chat-header-meta" id="tcEvoPhoneDisplay">
                        <?php if ($active['phone'] !== ''): ?>
                            <?= e(format_phone($active['phone'])) ?>
                        <?php else: ?>
                            <span title="A Meta mascara o número real em conversas vindas de anúncio (Instagram/Facebook Ads)">Número não exposto pelo WhatsApp (contato via anúncio)</span>
                        <?php endif; ?>
                        <?php if (!empty($active['instance_name'])): ?>
                            <span class="ms-1">· <?= e($tcEvoInstanceLabels[$active['instance_name']] ?? $active['instance_name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php include APP_PATH . '/views/partials/_chat_theme_picker.php'; ?>
                <?php if ($isManager): ?>
                <button type="button" class="tc-icon-btn" id="tcEvoRefreshContactBtn" title="Forçar atualização do nome/foto/número deste contato"><i class="fa-solid fa-arrows-rotate"></i></button>
                <?php endif; ?>
                <button type="button" class="tc-icon-btn d-lg-none" id="tcEvoShowInfo" title="Ações do atendimento"><i class="fa-solid fa-circle-info"></i></button>
            </header>

            <div class="tc-chat-messages" id="tcEvoMessages">
                <div id="tcEvoMessagesList">
                    <?php $prevSenderKey = null; $prevDateLabel = null; ?>
                    <?php foreach ($messages as $msg): ?>
                        <?php if ($msg['date_label'] !== $prevDateLabel): ?>
                            <div class="tc-chat-date-divider"><?= e($msg['date_label']) ?></div>
                            <?php $prevSenderKey = null; ?>
                        <?php endif; ?>
                        <?php $senderKey = $msg['type'] . ':' . ($msg['user_id'] ?? ($msg['private'] ? 'note' : '')); ?>
                        <?php $isGrouped = $senderKey === $prevSenderKey; ?>
                        <?php include __DIR__ . '/_message.php'; // partial do módulo evolution, não confundir com chat/_message.php ?>
                        <?php $prevSenderKey = $senderKey; $prevDateLabel = $msg['date_label']; ?>
                    <?php endforeach; ?>
                    <?php if (empty($messages)): ?>
                        <div class="tc-chat-empty">Nenhuma mensagem ainda. As mensagens do cliente chegam automaticamente via webhook.</div>
                    <?php endif; ?>
                </div>
            </div>

            <form class="tc-chat-composer" id="tcEvoComposer" data-conversation-id="<?= e($active['id']) ?>">
                <?= Csrf::field() ?>
                <div class="tc-chat-compose-wrap">
                    <div class="tc-chat-compose-tools">
                        <label class="tc-chat-tool tc-evo-private-toggle" title="Nota interna: não é enviada ao cliente">
                            <input type="checkbox" id="tcEvoPrivate" name="private"> <i class="fa-solid fa-note-sticky"></i> Nota interna
                        </label>
                        <?php if (!empty($active['link']['lead_email'])): ?>
                            <button type="button" class="tc-chat-tool" id="tcEvoEmailBtn" data-bs-toggle="modal" data-bs-target="#tcEvoEmailModal" title="Preparar e-mail para o lead"><i class="fa-solid fa-envelope"></i> E-mail</button>
                        <?php else: ?>
                            <button type="button" class="tc-chat-tool" disabled title="Vincule um lead com e-mail para usar este canal"><i class="fa-solid fa-envelope"></i> E-mail</button>
                        <?php endif; ?>
                        <select id="tcEvoWhatsappTemplate" class="tc-chat-tool" data-templates-url="<?= e(url('configuracoes/whatsapp-templates/listar')) ?>" title="Inserir um modelo de WhatsApp">
                            <option value="">Modelos WhatsApp</option>
                        </select>
                    </div>
                    <textarea class="form-control" id="tcEvoInput" name="content" rows="1" placeholder="Escreva uma resposta para o cliente..." maxlength="4000" autocomplete="off"></textarea>
                </div>
                <button type="submit" class="btn btn-tc-primary" title="Enviar"><i class="fa-solid fa-paper-plane"></i></button>
            </form>
        <?php endif; ?>
    </section>

    <!-- Coluna direita: ações do atendimento (lead, transferência, etiquetas) -->
    <?php if ($active): ?>
    <aside class="tc-evo-info" id="tcEvoInfoPane">
        <div class="tc-evo-info-header d-flex justify-content-between align-items-center">
            <strong>Detalhes do atendimento</strong>
            <button type="button" class="tc-icon-btn d-lg-none" id="tcEvoHideInfo"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <?php if ($active['phone'] === ''): ?>
        <div class="tc-evo-info-section">
            <label class="form-label small fw-semibold">Número do WhatsApp</label>
            <div class="small text-muted mb-1">Não exposto pela Meta (contato via anúncio). Se você souber o número (ex: cliente informou por telefone), pode corrigir aqui.</div>
            <div class="input-group input-group-sm">
                <input type="text" id="tcEvoPhoneInput" class="form-control" data-mask="phone" placeholder="(11) 99999-9999">
                <button type="button" class="btn btn-outline-secondary" id="tcEvoSavePhone"><i class="fa-solid fa-check"></i></button>
            </div>
        </div>
        <?php endif; ?>

        <div class="tc-evo-info-section">
            <label class="form-label small fw-semibold">Lead vinculado</label>
            <?php if (!empty($active['link']['lead_id'])): ?>
                <a href="<?= e(url('leads/' . $active['link']['lead_id'])) ?>" class="tc-evo-lead-chip">
                    <i class="fa-solid fa-address-card"></i> <?= e($active['link']['lead_name'] ?: ('Lead #' . $active['link']['lead_id'])) ?>
                </a>
                <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-2" id="tcEvoScheduleBtn" data-lead-id="<?= (int) $active['link']['lead_id'] ?>">
                    <i class="fa-solid fa-calendar-plus me-1"></i> Agendar próximo contato
                </button>
            <?php else: ?>
                <div class="input-group input-group-sm mb-1">
                    <input type="text" id="tcEvoLeadSearch" class="form-control" placeholder="Buscar lead pelo nome ou telefone...">
                </div>
                <div id="tcEvoLeadResults" class="tc-chat-search-results d-none"></div>
                <button type="button" class="btn btn-outline-success btn-sm mt-1 w-100" data-bs-toggle="modal" data-bs-target="#tcEvoCreateLeadModal">
                    <i class="fa-solid fa-user-plus me-1"></i> Cadastrar lead novo
                </button>
            <?php endif; ?>
        </div>

        <div class="tc-evo-info-section">
            <button type="button" class="btn btn-outline-primary btn-sm w-100" <?= $canCreateTask ? 'data-bs-toggle="modal" data-bs-target="#tcEvoCreateTaskModal"' : 'disabled title="Você não tem permissão para criar tarefas."' ?>>
                <i class="fa-solid fa-list-check me-1"></i> Criar tarefa para este atendimento
            </button>
        </div>

        <div class="tc-evo-info-section">
            <label class="form-label small fw-semibold">Responsável</label>
            <?php if ($isManager): ?>
                <select id="tcEvoTransferSelect" class="form-select form-select-sm">
                    <option value="">Transferir para...</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= !empty($active['link']['assigned_to']) && (int) $active['link']['assigned_to'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <div class="small text-muted"><?= e($active['link']['assigned_name'] ?? 'Ninguém atribuído ainda') ?></div>
            <?php endif; ?>
        </div>

        <div class="tc-evo-info-section" id="tcEvoFlowPanel"
             data-set-url="<?= e(url('atendimento-whatsapp/' . $active['id'] . '/fluxo')) ?>"
             data-advance-url="<?= e(url('atendimento-whatsapp/' . $active['id'] . '/fluxo/avancar')) ?>">
            <label class="form-label small fw-semibold"><i class="fa-solid fa-diagram-project me-1"></i>Fluxo de atendimento</label>
            <select id="tcEvoFlowSelect" class="form-select form-select-sm">
                <option value="">Sem fluxo</option>
                <?php foreach (($flowOptions ?? []) as $flow): ?>
                    <option value="<?= (int) $flow['id'] ?>" <?= $tcEvoActiveFlow && (int) $tcEvoActiveFlow['id'] === (int) $flow['id'] ? 'selected' : '' ?>><?= e($flow['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-outline-primary btn-sm w-100 mt-2" id="tcEvoSetFlow"><i class="fa-solid fa-play me-1"></i>Aplicar fluxo</button>
            <div class="small mt-2 <?= $tcEvoCurrentFlowStep ? '' : 'text-muted' ?>" id="tcEvoFlowCurrent" data-step="<?= (int) $tcEvoFlowStep ?>" data-total="<?= count($tcEvoFlowSteps) ?>" data-channel="<?= e(($tcEvoCurrentFlowStep['channel'] ?? 'whatsapp') === 'email' ? 'email' : 'whatsapp') ?>" data-suggestion="<?= e((string) ($tcEvoCurrentFlowStep['suggestion'] ?? '')) ?>" data-email-subject="<?= e((string) ($tcEvoCurrentFlowStep['email_subject'] ?? '')) ?>" data-guidance="<?= e((string) ($tcEvoCurrentFlowStep['guidance'] ?? '')) ?>">
                <?php if ($tcEvoCurrentFlowStep): ?>
                    <div><strong>Etapa <?= (int) $tcEvoFlowStep + 1 ?>/<?= count($tcEvoFlowSteps) ?>:</strong> <?= e($tcEvoCurrentFlowStep['title'] ?? '') ?></div>
                    <div class="mt-1"><span class="badge text-bg-<?= ($tcEvoCurrentFlowStep['channel'] ?? 'whatsapp') === 'email' ? 'primary' : 'success' ?>"><?= ($tcEvoCurrentFlowStep['channel'] ?? 'whatsapp') === 'email' ? 'E-mail' : 'WhatsApp' ?></span><?php if (!empty($tcEvoCurrentFlowStep['guidance'])): ?> <span class="text-muted"><?= e($tcEvoCurrentFlowStep['guidance']) ?></span><?php endif; ?></div>
                <?php else: ?>
                    Selecione um fluxo para orientar este atendimento.
                <?php endif; ?>
            </div>
            <div class="small text-muted mt-2"><i class="fa-solid fa-user-check me-1"></i>A sugestão não é enviada automaticamente: revise antes de confirmar.</div>
            <button type="button" class="btn btn-outline-success btn-sm w-100 mt-2 <?= $tcEvoCurrentFlowStep ? '' : 'd-none' ?>" id="tcEvoPrepareFlow"><i class="fa-solid fa-pen-to-square me-1"></i><?= ($tcEvoCurrentFlowStep['channel'] ?? 'whatsapp') === 'email' ? 'Preparar e-mail' : 'Preparar resposta WhatsApp' ?></button>
            <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-2 <?= $tcEvoCurrentFlowStep ? '' : 'd-none' ?>" id="tcEvoAdvanceFlow"><i class="fa-solid fa-forward me-1"></i>Próxima etapa</button>
        </div>

        <div class="tc-evo-info-section">
            <label class="form-label small fw-semibold">Etiquetas</label>
            <input type="text" id="tcEvoLabelsInput" class="form-control form-control-sm" list="tcEvoLabelSuggestions" placeholder="Separe por vírgula: vip, financiamento..." value="<?= e(implode(', ', $active['labels'] ?? [])) ?>">
            <datalist id="tcEvoLabelSuggestions">
                <?php foreach ($suggestedLabels ?? [] as $label): ?>
                    <option value="<?= e((string) $label) ?>">
                <?php endforeach; ?>
            </datalist>
            <?php if (!empty($suggestedLabels)): ?>
                <div class="tc-evo-label-suggestions mt-1">
                    <?php foreach (array_slice($suggestedLabels, 0, 8) as $label): ?>
                        <button type="button" class="tc-evo-label-suggest" data-label="<?= e((string) $label) ?>" style="border-color:<?= e(tc_evo_label_color((string) $label)) ?>;color:<?= e(tc_evo_label_color((string) $label)) ?>"><?= e((string) $label) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div id="tcEvoLabelsPreview" class="tc-evo-label-chips mt-2">
                <?php foreach ($active['labels'] ?? [] as $label): ?>
                    <span class="tc-evo-label-chip" style="background:<?= e(tc_evo_label_color((string) $label)) ?>"><?= e((string) $label) ?></span>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="tcEvoSaveLabels"><i class="fa-solid fa-tags me-1"></i> Salvar etiquetas</button>
        </div>
    </aside>
    <?php endif; ?>
</div>

<?php if ($active && !empty($active['link']['lead_email'])): ?>
<div class="modal fade" id="tcEvoEmailModal" tabindex="-1" aria-labelledby="tcEvoEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form id="tcEvoEmailForm" data-url="<?= e(url('atendimento-whatsapp/' . $active['id'] . '/email')) ?>" data-templates-url="<?= e(url('configuracoes/email-templates/listar')) ?>" data-lead-name="<?= e((string) ($active['link']['lead_name'] ?? '')) ?>" data-lead-interest="<?= e((string) ($active['link']['lead_interest'] ?? '')) ?>" data-assigned-name="<?= e((string) ($active['link']['assigned_name'] ?? (Auth::user()['name'] ?? ''))) ?>">
            <div class="modal-header"><div><h5 class="modal-title" id="tcEvoEmailModalTitle"><i class="fa-solid fa-envelope me-1"></i>Preparar e-mail</h5><div class="small text-muted">O e-mail só será enviado após sua confirmação.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Modelo <span class="text-muted">(opcional)</span></label><select id="tcEvoEmailTemplate" class="form-select"><option value="">Carregando modelos...</option></select></div>
                    <div class="col-md-6"><label class="form-label">Para</label><input id="tcEvoEmailTo" name="to" type="email" class="form-control" required value="<?= e((string) $active['link']['lead_email']) ?>"></div>
                    <div class="col-12"><label class="form-label">Assunto</label><input id="tcEvoEmailSubject" name="subject" class="form-control" maxlength="255" required></div>
                    <div class="col-12"><label class="form-label">Mensagem</label><textarea id="tcEvoEmailContent" name="content" class="form-control" rows="10" maxlength="12000" required></textarea></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-tc-primary" id="tcEvoEmailSend"><i class="fa-solid fa-paper-plane me-1"></i>Revisar e enviar e-mail</button></div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<!-- Modal: cadastrar lead a partir do atendimento -->
<?php if ($active && empty($active['link']['lead_id'])): ?>
<div class="modal fade" id="tcEvoCreateLeadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="tcEvoCreateLeadForm" data-url="<?= e(url('atendimento-whatsapp/' . $active['id'] . '/criar-lead')) ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-user-plus me-1"></i> Cadastrar lead a partir do atendimento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input type="text" name="name" class="form-control" required value="<?= $active['name'] !== 'Contato WhatsApp' ? e($active['name']) : '' ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">WhatsApp</label>
                        <input type="text" name="whatsapp" class="form-control" data-mask="phone" value="<?= e(format_phone($active['phone'])) ?>" <?= $active['phone'] === '' ? 'placeholder="Número não identificado — preencha se souber"' : '' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Interesse</label>
                        <select name="interest" class="form-select">
                            <option value="">Selecione</option>
                            <?php foreach (['imovel' => 'Imóvel', 'veiculo' => 'Veículo', 'caminhao' => 'Caminhão', 'moto' => 'Moto', 'maquinario' => 'Maquinário', 'agronegocio' => 'Agronegócio', 'construcao' => 'Construção', 'capital_giro' => 'Capital de Giro', 'investimento' => 'Investimento', 'quitacao' => 'Quitação', 'outros' => 'Outros'] as $val => $label): ?>
                                <option value="<?= e($val) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($isManager): ?>
                    <div class="mb-3">
                        <label class="form-label">Responsável</label>
                        <select name="assigned_to" class="form-select">
                            <option value="<?= (int) Auth::id() ?>">Eu mesmo</option>
                            <?php foreach ($users as $u): if ((int) $u['id'] === (int) Auth::id()) continue; ?>
                                <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Observações</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-tc-primary"><i class="fa-solid fa-check me-1"></i> Cadastrar lead</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: criar tarefa a partir do atendimento -->
<?php if ($active && $canCreateTask): ?>
<div class="modal fade" id="tcEvoCreateTaskModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="tcEvoCreateTaskForm" data-url="<?= e(url('atendimento-whatsapp/' . $active['id'] . '/criar-tarefa')) ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-list-check me-1"></i> Nova tarefa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Título</label>
                        <input type="text" name="title" class="form-control" required maxlength="180" value="Retornar contato de <?= e($active['name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="description" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Prioridade</label>
                            <select name="priority" class="form-select">
                                <option value="baixa">Baixa</option>
                                <option value="media" selected>Média</option>
                                <option value="alta">Alta</option>
                                <option value="urgente">Urgente</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Prazo</label>
                            <input type="datetime-local" name="due_at" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Atribuir para</label>
                            <select name="assigned_to" class="form-select">
                                <option value="<?= (int) Auth::id() ?>">Eu mesmo</option>
                                <?php foreach ($users as $u): if ((int) $u['id'] === (int) Auth::id()) continue; ?>
                                    <option value="<?= (int) $u['id'] ?>" <?= !empty($active['link']['assigned_to']) && (int) $active['link']['assigned_to'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-tc-primary"><i class="fa-solid fa-check me-1"></i> Criar tarefa</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php $pageScripts = '<script src="' . e(asset('js/evolution.js')) . '"></script>'; ?>
