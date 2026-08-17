<?php
/**
 * app/views/leads/show.php
 * Perfil do lead: dados completos, timeline/histórico, tags e observação rápida.
 */

$historyIcons = [
    'criacao'      => 'fa-solid fa-plus text-primary',
    'contato'      => 'fa-solid fa-comment text-info',
    'whatsapp'     => 'fa-brands fa-whatsapp text-success',
    'email'        => 'fa-solid fa-envelope text-primary',
    'ligacao'      => 'fa-solid fa-phone text-info',
    'status'       => 'fa-solid fa-flag text-warning',
    'observacao'   => 'fa-solid fa-note-sticky text-secondary',
    'responsavel'  => 'fa-solid fa-user-tag text-primary',
    'agendamento'  => 'fa-solid fa-calendar text-info',
    'fechamento'   => 'fa-solid fa-circle-check text-success',
    'dado_alterado' => 'fa-solid fa-pen text-secondary',
];
?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="tc-card mb-3">
            <div class="tc-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="d-flex align-items-center gap-2">
                    <?= e($lead['name'] ?: 'Lead sem nome') ?>
                    <?php if (!empty($lead['lead_code'])): ?>
                        <span class="badge tc-badge-code"><i class="fa-solid fa-hashtag me-1"></i><?= e($lead['lead_code']) ?></span>
                    <?php endif; ?>
                </span>
                <span class="badge bg-<?= e(status_color($lead['status'])) ?>"><?= e(status_label($lead['status'])) ?></span>
            </div>
            <div class="tc-card-body">
                <div class="row g-3">
                    <div class="col-md-4"><strong>Telefone:</strong><br><?= e(format_phone($lead['phone'])) ?: '-' ?></div>
                    <div class="col-md-4"><strong>WhatsApp:</strong><br><?= e(format_phone($lead['whatsapp'])) ?: '-' ?></div>
                    <div class="col-md-4"><strong>E-mail:</strong><br><?= e($lead['email']) ?: '-' ?></div>

                    <div class="col-md-4"><strong>CPF:</strong><br><?= e(format_cpf($lead['cpf'])) ?: '-' ?></div>
                    <div class="col-md-4"><strong>Cidade/UF:</strong><br><?= e($lead['city'] ? $lead['city'] . '/' . $lead['state'] : '-') ?></div>
                    <div class="col-md-4"><strong>CEP:</strong><br><?= e(format_cep($lead['zipcode'])) ?: '-' ?></div>

                    <div class="col-md-4"><strong>Origem:</strong><br><?= e(source_label($lead['source'])) ?></div>
                    <div class="col-md-4"><strong>Interesse:</strong><br><?= e(interest_label($lead['interest'])) ?></div>
                    <div class="col-md-4"><strong>Valor desejado:</strong><br><?= e(format_money($lead['desired_value'])) ?></div>
                    <?php if (!empty($lead['closed_value']) || !empty($lead['closed_at'])): ?>
                    <div class="col-md-4"><strong>Venda concluída:</strong><br><?= e(format_money($lead['closed_value'])) ?> · <?= e(format_date($lead['closed_at'])) ?></div>
                    <?php endif; ?>

                    <div class="col-md-4"><strong>Possui entrada:</strong><br><?= !empty($lead['has_down_payment']) ? 'Sim (' . e(format_money($lead['down_payment_value'])) . ')' : 'Não' ?></div>
                    <div class="col-md-4"><strong>Renda:</strong><br><?= e($lead['income_range']) ?: '-' ?></div>
                    <div class="col-md-4"><strong>Profissão:</strong><br><?= e($lead['profession']) ?: '-' ?></div>

                    <div class="col-md-4"><strong>Responsável:</strong><br><?= e($lead['assigned_name']) ?: 'Não atribuído' ?></div>
                    <div class="col-md-4"><strong>Temperatura:</strong><br><?= e(ucfirst($lead['temperature'] ?? '-')) ?></div>
                    <div class="col-md-4"><strong>Prioridade:</strong><br><?= e(ucfirst($lead['priority'] ?? '-')) ?></div>

                    <div class="col-md-4"><strong>Lead Score:</strong><br><?= (int) ($lead['lead_score'] ?? 0) ?>/100</div>
                    <div class="col-md-4"><strong>Último contato:</strong><br><?= e(format_date($lead['last_contact_at'], true)) ?></div>
                    <div class="col-md-4"><strong>Próximo contato:</strong><br><?= e(format_date($lead['next_contact_at'], true)) ?></div>

                    <?php if (!empty($lead['loss_reason_name'])): ?>
                        <div class="col-md-4"><strong>Motivo de perda:</strong><br><?= e($lead['loss_reason_name']) ?></div>
                    <?php endif; ?>

                    <div class="col-md-4"><strong>Cadastrado em:</strong><br><?= e(format_date($lead['created_at'], true)) ?></div>
                    <div class="col-md-4"><strong>Última atualização:</strong><br><?= e(format_date($lead['updated_at'], true)) ?></div>
                </div>

                <?php if (!empty($tags)): ?>
                    <div class="mt-3">
                        <?php foreach ($tags as $tag): ?>
                            <span class="badge" style="background-color: <?= e($tag['color'] ?: '#6b7280') ?>;"><?= e($tag['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($lead['notes'])): ?>
                    <div class="mt-3">
                        <strong>Observações:</strong>
                        <p class="mb-0"><?= nl2br(e($lead['notes'])) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($lead['internal_notes'])): ?>
                    <div class="mt-3">
                        <strong>Observações internas:</strong>
                        <p class="mb-0 text-muted"><?= nl2br(e($lead['internal_notes'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="d-flex gap-2 mb-3">
            <a href="<?= e(url('leads/' . $lead['id'] . '/edit')) ?>" class="btn btn-tc-primary">
                <i class="fa-solid fa-pen me-1"></i> Editar Lead
            </a>
            <?php if (Auth::can('chat.create_room')): ?>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#tcLeadChatRoomModal">
                <i class="fa-solid fa-comments me-1"></i> Sala do lead
            </button>
            <?php endif; ?>
            <?php if (!empty($lead['whatsapp']) || !empty($lead['phone'])): ?>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#tcWhatsappModal">
                <i class="fa-brands fa-whatsapp me-1"></i> Enviar WhatsApp
            </button>
            <?php endif; ?>
            <?php if (Auth::can('leads.delete')): ?>
            <form method="POST" action="<?= e(url('leads/' . $lead['id'] . '/delete')) ?>" class="tc-delete-form" data-confirm-text="O lead será excluído permanentemente.">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-outline-danger"><i class="fa-solid fa-trash me-1"></i> Excluir</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="tc-card mb-3">
            <div class="tc-card-header">Adicionar observação rápida</div>
            <div class="tc-card-body">
                <!-- Fase 7 (auditoria UX): envio via AJAX (POST /leads/{id}/nota-rapida),
                     que também atualiza last_contact_at. Fallback tradicional
                     (action=leads/{id}/note) mantido caso o JS não carregue. -->
                <form method="POST" action="<?= e(url('leads/' . $lead['id'] . '/note')) ?>"
                      class="tc-quick-note-form" data-ajax-url="<?= e(url('leads/' . $lead['id'] . '/nota-rapida')) ?>"
                      data-csrf-token="<?= e(Csrf::token()) ?>">
                    <?= Csrf::field() ?>
                    <textarea name="note" class="form-control mb-2" rows="3" placeholder="Escreva uma observação..." required></textarea>
                    <button type="submit" class="btn btn-tc-primary btn-sm w-100">
                        <i class="fa-solid fa-paper-plane me-1"></i> Registrar
                    </button>
                </form>
            </div>
        </div>

        <?php
        // Tarefas relacionadas (módulo de Tarefas - ver database/sql/migration_tasks.sql
        // e app/controllers/TaskController.php). Calculado direto na view, sem alterar
        // LeadController::show, para não arriscar conflito com outras alterações em
        // andamento na tela de lead. Falha graciosamente se a migration ainda não
        // tiver sido executada.
        $tcRelatedTasks = [];
        try {
            require_once APP_PATH . '/models/Task.php';
            $tcRelatedTasks = (new Task())->forLead((int) $lead['id']);
        } catch (Throwable $e) {
            $tcRelatedTasks = [];
        }
        $tcTaskStatusColors = ['pendente' => 'secondary', 'em_andamento' => 'info', 'aguardando' => 'warning', 'concluida' => 'success', 'cancelada' => 'dark'];
        $tcTaskStatusLabels = ['pendente' => 'Pendente', 'em_andamento' => 'Em Andamento', 'aguardando' => 'Aguardando', 'concluida' => 'Concluída', 'cancelada' => 'Cancelada'];
        ?>
        <?php if (Auth::can('tasks.create') || !empty($tcRelatedTasks)): ?>
        <div class="tc-card mb-3">
            <div class="tc-card-header d-flex justify-content-between align-items-center">
                <span>Tarefas relacionadas</span>
                <?php if (Auth::can('tasks.create')): ?>
                    <a href="<?= e(url('tarefas/nova?lead_id=' . $lead['id'])) ?>" class="btn btn-tc-primary btn-sm">
                        <i class="fa-solid fa-plus me-1"></i> Nova Tarefa
                    </a>
                <?php endif; ?>
            </div>
            <div class="tc-card-body" style="max-height: 320px; overflow-y: auto;">
                <?php if (empty($tcRelatedTasks)): ?>
                    <p class="text-muted mb-0" style="font-size:0.85rem;">Nenhuma tarefa vinculada a este lead.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($tcRelatedTasks as $tTask): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <a href="<?= e(url('tarefas/' . $tTask['id'])) ?>" class="text-decoration-none fw-semibold text-reset" style="font-size:0.85rem;">
                                    <?= e($tTask['title']) ?>
                                </a>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <span class="badge bg-<?= e($tcTaskStatusColors[$tTask['status']] ?? 'secondary') ?>"><?= e($tcTaskStatusLabels[$tTask['status']] ?? $tTask['status']) ?></span>
                                    <span class="text-muted" style="font-size:0.72rem;"><?= e($tTask['assigned_name'] ?: 'Não atribuído') ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="tc-card">
            <div class="tc-card-header">Histórico / Timeline</div>
            <div class="tc-card-body" style="max-height: 480px; overflow-y: auto;">
                <p class="text-muted mb-0 tc-history-empty" style="font-size:0.85rem; <?= !empty($history) ? 'display:none;' : '' ?>">Nenhum evento registrado ainda.</p>
                <ul class="list-unstyled mb-0" id="tcHistoryList">
                    <?php foreach ($history as $event): ?>
                        <li class="mb-3 pb-3 border-bottom">
                            <div class="d-flex gap-2">
                                <i class="<?= e($historyIcons[$event['type']] ?? 'fa-solid fa-circle-info text-secondary') ?> mt-1"></i>
                                <div>
                                    <div style="font-size:0.85rem;"><?= e($event['description']) ?></div>
                                    <div class="text-muted" style="font-size:0.72rem;">
                                        <?= e($event['user_name'] ?? 'Sistema') ?> · <?= e(time_ago($event['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if (Auth::can('chat.create_room')): ?>
<!-- Sala privada de colaboração: só os participantes convidados veem o chat. -->
<div class="modal fade" id="tcLeadChatRoomModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="tcLeadChatRoomForm"
                  action="<?= e(url('chat/salas/lead/' . $lead['id'])) ?>"
                  data-search-url="<?= e(url('chat/usuarios/buscar')) ?>"
                  data-chat-url="<?= e(url('chat')) ?>"
                  method="POST">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-comments me-1 text-primary"></i>Sala privada do lead</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3" style="font-size:0.9rem;">
                        Discuta o atendimento de <strong><?= e($lead['name'] ?: ($lead['lead_code'] ?: 'este lead')) ?></strong> com as pessoas convidadas.
                        O responsável atual entra automaticamente na primeira abertura.
                    </p>
                    <label class="form-label">Convidar pessoas <span class="text-muted">(opcional)</span></label>
                    <input type="text" class="form-control form-control-sm" id="tcLeadChatMemberSearch" placeholder="Buscar colega pelo nome ou e-mail..." autocomplete="off">
                    <div id="tcLeadChatMemberResults" class="tc-chat-search-results d-none"></div>
                    <div id="tcLeadChatMembersSelected" class="d-flex flex-wrap gap-1 mt-2"></div>
                    <p class="tc-chat-modal-hint mt-3 mb-0"><i class="fa-solid fa-lock"></i> Apenas participantes convidados terão acesso à conversa.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-tc-primary"><i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Abrir sala</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Enviar WhatsApp (Fase 3, ver app/controllers/WhatsappController.php) -->
<div class="modal fade" id="tcWhatsappModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="tcWhatsappForm" data-send-url="<?= e(url('leads/' . $lead['id'] . '/whatsapp')) ?>" data-csrf-token="<?= e(Csrf::token()) ?>"
                  data-templates-url="<?= e(url('configuracoes/whatsapp-templates/listar')) ?>"
                  data-lead-name="<?= e($lead['name'] ?: 'Lead #' . $lead['id']) ?>"
                  data-lead-interest="<?= e(interest_label($lead['interest'])) ?>"
                  data-lead-assigned="<?= e($lead['assigned_name'] ?: 'nosso time') ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-brands fa-whatsapp text-success me-1"></i> Enviar WhatsApp</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted" style="font-size:0.85rem;">
                        Para: <strong><?= e($lead['name'] ?: 'Lead #' . $lead['id']) ?></strong>
                        (<?= e(format_phone($lead['whatsapp'] ?: $lead['phone'])) ?>)
                    </p>
                    <div class="mb-2">
                        <label class="form-label" style="font-size:0.8rem;">Template (opcional)</label>
                        <select id="tcWhatsappTemplateSelect" class="form-select form-select-sm">
                            <option value="">Mensagem livre...</option>
                        </select>
                    </div>
                    <textarea name="message" class="form-control" rows="4" placeholder="Digite sua mensagem..." required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success"><i class="fa-solid fa-paper-plane me-1"></i> Enviar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (($_GET['open'] ?? '') === 'whatsapp' && (!empty($lead['whatsapp']) || !empty($lead['phone']))): ?>
<?php
// Veio de um link direto (ex: botão de WhatsApp na Agenda, Fase 5) pedindo
// para abrir o modal automaticamente, sem duplicar a lógica de envio.
$pageScripts = '<script>
document.addEventListener("DOMContentLoaded", function () {
    var modalEl = document.getElementById("tcWhatsappModal");
    if (modalEl && window.bootstrap) {
        new window.bootstrap.Modal(modalEl).show();
    }
});
</script>';
?>
<?php endif; ?>
