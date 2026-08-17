<?php
/**
 * app/views/pipeline/index.php
 * Kanban de pipeline com drag-and-drop (HTML5 Drag and Drop API nativo).
 * Fase 7 (auditoria UX): indicador de temperatura + score no card, escopo
 * "Meus leads"/"Todos os leads" e nota rápida via AJAX.
 */
$tcPipelineQuery = [];
if ($scope === 'all' && $assignedTo !== '') {
    $tcPipelineQuery['assigned_to'] = $assignedTo;
}
$tcMineUrl = url('pipeline?' . http_build_query(['view' => 'mine']));
$tcAllUrl = url('pipeline?' . http_build_query(array_merge($tcPipelineQuery, ['view' => 'all'])));
?>

<div class="tc-card mb-3">
    <div class="tc-card-body d-flex align-items-end gap-3 flex-wrap">
        <?php if ($canViewAll): ?>
        <div class="btn-group tc-scope-toggle" role="group">
            <a href="<?= e($tcMineUrl) ?>" class="btn btn-sm btn-outline-secondary <?= $scope === 'mine' ? 'active' : '' ?>">
                <i class="fa-solid fa-user me-1"></i> Meus leads
            </a>
            <a href="<?= e($tcAllUrl) ?>" class="btn btn-sm btn-outline-secondary <?= $scope === 'all' ? 'active' : '' ?>">
                <i class="fa-solid fa-users me-1"></i> Todos os leads
            </a>
        </div>
        <?php else: ?>
        <p class="text-muted mb-0" style="font-size:0.8rem;"><i class="fa-solid fa-lock me-1"></i> Mostrando apenas os leads atribuídos a você.</p>
        <?php endif; ?>

        <?php if ($canViewAll && $scope === 'all'): ?>
        <form method="GET" action="<?= e(url('pipeline')) ?>" class="d-flex gap-2 align-items-end">
            <input type="hidden" name="view" value="all">
            <div>
                <label class="form-label mb-1">Filtrar por responsável</label>
                <select name="assigned_to" class="form-select" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (string) $assignedTo === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<p class="tc-kanban-hint">
    <i class="fa-solid fa-arrows-left-right me-1"></i>
    Arraste para o lado para ver as outras colunas. Em telas menores, use o botão
    <i class="fa-solid fa-arrow-right-arrow-left"></i> em cada card para mover o lead
    (o arraste nativo não funciona bem em telas touch).
</p>

<div class="tc-kanban" id="tcKanbanBoard"
     data-move-url="<?= e(url('pipeline/move')) ?>"
     data-note-url-base="<?= e(url('leads')) ?>"
     data-csrf-token="<?= e(Csrf::token()) ?>">
    <?php foreach ($columns as $col): $stage = $col['stage']; ?>
        <div class="tc-kanban-column">
            <div class="tc-kanban-column-header" style="border-bottom-color: <?= e($stage['color'] ?: '#3b82f6') ?>;">
                <span><?= e($stage['name']) ?></span>
                <span class="badge bg-secondary"><?= count($col['leads']) ?></span>
            </div>
            <div class="tc-kanban-column-body" data-stage-name="<?= e($stage['name']) ?>">
                <?php foreach ($col['leads'] as $lead): ?>
                    <?php
                    $tcCallNumber = preg_replace('/\D/', '', (string) ($lead['phone'] ?: $lead['whatsapp']));
                    $tcWhatsappNumber = preg_replace('/\D/', '', (string) ($lead['whatsapp'] ?: $lead['phone']));
                    $tcEmail = trim((string) ($lead['email'] ?? ''));
                    $tcCallUrl = $tcCallNumber !== '' ? 'tel:' . $tcCallNumber : '';
                    $tcEmailUrl = filter_var($tcEmail, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $tcEmail : '';
                    $tcWhatsappUrl = $tcWhatsappNumber !== '' ? url('leads/' . $lead['id'] . '?open=whatsapp') : '';
                    ?>
                    <div class="tc-kanban-card" data-lead-id="<?= (int) $lead['id'] ?>"
                         data-lead-url="<?= e(url('leads/' . $lead['id'])) ?>"
                         data-call-url="<?= e($tcCallUrl) ?>"
                         data-email-url="<?= e($tcEmailUrl) ?>"
                         data-whatsapp-url="<?= e($tcWhatsappUrl) ?>"
                         data-lead-name="<?= e($lead['name'] ?: 'Sem nome') ?>"
                         data-lead-code="<?= e($lead['lead_code'] ?? '') ?>"
                         data-lead-status="<?= e(status_label($lead['status'])) ?>"
                         data-lead-status-color="<?= e(status_color($lead['status'])) ?>"
                         data-lead-score="<?= (int) ($lead['lead_score'] ?? 0) ?>"
                         data-phone="<?= e(format_phone($lead['phone'])) ?>"
                         data-whatsapp="<?= e(format_phone($lead['whatsapp'])) ?>"
                         data-email="<?= e($tcEmail) ?>"
                         data-city="<?= e($lead['city'] ? $lead['city'] . '/' . $lead['state'] : '') ?>"
                         data-interest="<?= e(interest_label($lead['interest'])) ?>"
                         data-source="<?= e(source_label($lead['source'])) ?>"
                         data-assigned-name="<?= e($lead['assigned_name'] ?? '') ?>"
                         data-last-contact="<?= e(days_since_contact_label($lead['last_contact_at'] ?? null)) ?>"
                         data-created-at="<?= e(format_date($lead['created_at'] ?? null, true)) ?>"
                         role="button" tabindex="0" aria-label="Abrir resumo de <?= e($lead['name'] ?: 'Sem nome') ?>">
                        <div class="tc-card-name d-flex align-items-center justify-content-between">
                            <span class="text-reset">
                                <?php if (!empty($lead['temperature'])): ?>
                                    <span class="tc-kanban-card-temp-strip <?= e(temperature_badge_class($lead['temperature'])) ?>" title="<?= e(temperature_label($lead['temperature'])) ?>"></span>
                                <?php endif; ?>
                                <?= e($lead['name'] ?: 'Sem nome') ?>
                            </span>
                            <span class="d-flex align-items-center gap-1">
                                <span class="badge bg-<?= e(score_badge_class($lead['lead_score'] ?? 0)) ?>" title="Lead Score" style="font-size:0.65rem;"><?= (int) ($lead['lead_score'] ?? 0) ?></span>
                                <button type="button" class="tc-kanban-note-btn" title="Nota rápida" data-lead-id="<?= (int) $lead['id'] ?>">
                                    <i class="fa-solid fa-note-sticky"></i>
                                </button>
                            </span>
                        </div>
                        <div class="tc-card-meta">
                            <i class="fa-solid fa-phone"></i> <?= e(format_phone($lead['whatsapp'] ?: $lead['phone'])) ?: 'Sem telefone' ?>
                        </div>
                        <div class="tc-card-meta">
                            <i class="fa-solid fa-tag"></i> <?= e(interest_label($lead['interest'])) ?>
                        </div>
                        <div class="tc-card-meta <?= days_since_contact_is_stale($lead['last_contact_at'] ?? null) ? 'tc-text-stale' : '' ?>">
                            <i class="fa-solid fa-clock-rotate-left"></i> <?= e(days_since_contact_label($lead['last_contact_at'] ?? null)) ?>
                        </div>
                        <?php if (!empty($lead['assigned_name'])): ?>
                            <div class="tc-card-meta mt-1">
                                <span class="tc-avatar-sm" style="width:20px;height:20px;font-size:0.6rem;">
                                    <?= e(strtoupper(substr($lead['assigned_name'], 0, 1))) ?>
                                </span>
                                <?= e($lead['assigned_name']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="tc-kanban-card-actions">
                            <button type="button" class="tc-kanban-move-btn" title="Mover para outra coluna">
                                <i class="fa-solid fa-arrow-right-arrow-left"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($col['leads'])): ?>
                    <p class="text-muted text-center mb-0" style="font-size:0.78rem;">Nenhum lead nesta coluna.</p>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Modal único, preenchido pelo JavaScript ao selecionar qualquer card do Pipeline. -->
<div class="modal fade" id="tcPipelineLeadModal" tabindex="-1" aria-labelledby="tcPipelineLeadModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="tcPipelineLeadModalTitle">Resumo do lead</h5>
                    <small class="text-muted d-none" id="tcPipelineLeadCode"></small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary" id="tcPipelineLeadStatus">-</span>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
            </div>
            <div class="modal-body">
                <div class="row g-3 tc-pipeline-lead-details">
                    <div class="col-sm-6"><span>Telefone</span><strong id="tcPipelineLeadPhone">-</strong></div>
                    <div class="col-sm-6"><span>WhatsApp</span><strong id="tcPipelineLeadWhatsapp">-</strong></div>
                    <div class="col-sm-6"><span>E-mail</span><strong id="tcPipelineLeadEmail">-</strong></div>
                    <div class="col-sm-6"><span>Cidade/UF</span><strong id="tcPipelineLeadCity">-</strong></div>
                    <div class="col-sm-6"><span>Interesse</span><strong id="tcPipelineLeadInterest">-</strong></div>
                    <div class="col-sm-6"><span>Origem</span><strong id="tcPipelineLeadSource">-</strong></div>
                    <div class="col-sm-6"><span>Responsável</span><strong id="tcPipelineLeadAssigned">-</strong></div>
                    <div class="col-sm-6"><span>Score</span><strong id="tcPipelineLeadScore">0</strong></div>
                    <div class="col-sm-6"><span>Último contato</span><strong id="tcPipelineLeadLastContact">-</strong></div>
                    <div class="col-sm-6"><span>Cadastrado em</span><strong id="tcPipelineLeadCreatedAt">-</strong></div>
                </div>
            </div>
            <div class="modal-footer justify-content-between gap-2">
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-primary btn-sm d-none" id="tcPipelineLeadCall"><i class="fa-solid fa-phone me-1"></i> Ligar</a>
                    <a class="btn btn-outline-secondary btn-sm d-none" id="tcPipelineLeadEmailAction"><i class="fa-solid fa-envelope me-1"></i> E-mail</a>
                    <a class="btn btn-success btn-sm" id="tcPipelineLeadWhatsappAction"><i class="fa-brands fa-whatsapp me-1"></i> WhatsApp</a>
                </div>
                <a class="btn btn-tc-primary btn-sm" id="tcPipelineLeadOpen"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Abrir cadastro</a>
            </div>
        </div>
    </div>
</div>
