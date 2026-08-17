<?php
/**
 * app/views/tasks/show.php
 * Detalhe da tarefa: dados completos, timeline/histórico, comentários e
 * ações rápidas de status. Segue o mesmo padrão visual de leads/show.php.
 */

$priorityColors = ['baixa' => 'secondary', 'media' => 'primary', 'alta' => 'warning', 'urgente' => 'danger'];
$priorityLabels = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'];
$statusColors = ['pendente' => 'secondary', 'em_andamento' => 'info', 'aguardando' => 'warning', 'concluida' => 'success', 'cancelada' => 'dark'];
$statusLabels = ['pendente' => 'Pendente', 'em_andamento' => 'Em Andamento', 'aguardando' => 'Aguardando', 'concluida' => 'Concluída', 'cancelada' => 'Cancelada'];

$historyIcons = [
    'criacao'     => 'fa-solid fa-plus text-primary',
    'atribuicao'  => 'fa-solid fa-user-tag text-primary',
    'status'      => 'fa-solid fa-flag text-warning',
    'prioridade'  => 'fa-solid fa-arrow-up-9-1 text-warning',
    'prazo'       => 'fa-solid fa-calendar text-info',
    'comentario'  => 'fa-solid fa-comment text-info',
    'conclusao'   => 'fa-solid fa-circle-check text-success',
];

$isOverdue = !empty($task['due_at']) && strtotime($task['due_at']) < time() && !in_array($task['status'], ['concluida', 'cancelada'], true);
$statusUrl = url('tarefas/' . $task['id'] . '/status');
$csrfToken = Csrf::token();
?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="tc-card mb-3">
            <div class="tc-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><?= e($task['title']) ?></span>
                <span>
                    <span class="badge bg-<?= e($priorityColors[$task['priority']] ?? 'secondary') ?>"><?= e($priorityLabels[$task['priority']] ?? $task['priority']) ?></span>
                    <span class="badge bg-<?= e($statusColors[$task['status']] ?? 'secondary') ?>" id="tcTaskStatusBadge"><?= e($statusLabels[$task['status']] ?? $task['status']) ?></span>
                </span>
            </div>
            <div class="tc-card-body">
                <div class="row g-3">
                    <div class="col-md-4"><strong>Criado por:</strong><br><?= e($task['creator_name'] ?? '-') ?></div>
                    <div class="col-md-4"><strong>Responsável:</strong><br><?= e($task['assigned_name'] ?: 'Não atribuído') ?></div>
                    <div class="col-md-4">
                        <strong>Lead vinculado:</strong><br>
                        <?php if (!empty($task['lead_id'])): ?>
                            <a href="<?= e(url('leads/' . $task['lead_id'])) ?>"><?= e($task['lead_name'] ?: 'Lead #' . $task['lead_id']) ?></a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <strong>Prazo:</strong><br>
                        <?= e(format_date($task['due_at'], true)) ?>
                        <?php if ($isOverdue): ?>
                            <span class="badge bg-danger tc-task-blink ms-1">Atrasada</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <strong>SLA:</strong><br>
                        <?php if ($sla['has_sla']): ?>
                            <span class="badge bg-<?= e($sla['class']) ?>" id="tcTaskSlaBadge"><?= e($sla['label']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">Sem SLA definido</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4"><strong>Criada em:</strong><br><?= e(format_date($task['created_at'], true)) ?></div>

                    <?php if (!empty($task['started_at'])): ?>
                    <div class="col-md-4"><strong>Iniciada em:</strong><br><?= e(format_date($task['started_at'], true)) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($task['completed_at'])): ?>
                    <div class="col-md-4"><strong>Concluída em:</strong><br><?= e(format_date($task['completed_at'], true)) ?></div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($task['description'])): ?>
                    <div class="mt-3">
                        <strong>Descrição:</strong>
                        <p class="mb-0"><?= nl2br(e($task['description'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ações rápidas de status -->
        <div class="tc-card mb-3">
            <div class="tc-card-header">Ações</div>
            <div class="tc-card-body d-flex flex-wrap gap-2" id="tcTaskActions" data-url="<?= e($statusUrl) ?>" data-csrf-token="<?= e($csrfToken) ?>">
                <?php if (!in_array($task['status'], ['em_andamento', 'concluida', 'cancelada'], true)): ?>
                    <button type="button" class="btn btn-info tc-task-status-btn" data-status="em_andamento"><i class="fa-solid fa-play me-1"></i> Iniciar</button>
                <?php endif; ?>
                <?php if (!in_array($task['status'], ['concluida', 'cancelada'], true)): ?>
                    <button type="button" class="btn btn-warning tc-task-status-btn" data-status="aguardando"><i class="fa-solid fa-hourglass-half me-1"></i> Aguardando</button>
                    <button type="button" class="btn btn-success tc-task-status-btn" data-status="concluida"><i class="fa-solid fa-check me-1"></i> Concluir</button>
                    <?php if (!empty($task['lead_id'])): ?>
                        <!-- Fase 7: sincronização OPCIONAL com a Agenda - botão separado, não
                             mexe no fluxo padrão "Concluir" acima. Ver TaskController::syncLeadOnTaskCompleted(). -->
                        <button type="button" class="btn btn-outline-success" id="tcTaskCompleteSyncLead"
                                data-url="<?= e($statusUrl) ?>" data-csrf-token="<?= e($csrfToken) ?>"
                                title="Conclui a tarefa e também atualiza o último contato do lead vinculado">
                            <i class="fa-solid fa-check-double me-1"></i> Concluir e sincronizar contato do lead
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-danger tc-task-status-btn" data-status="cancelada"><i class="fa-solid fa-ban me-1"></i> Cancelar</button>
                <?php endif; ?>
                <?php if (in_array($task['status'], ['concluida', 'cancelada'], true)): ?>
                    <button type="button" class="btn btn-outline-secondary tc-task-status-btn" data-status="pendente"><i class="fa-solid fa-rotate-left me-1"></i> Reabrir</button>
                <?php endif; ?>

                <?php if (Auth::can('chat.create_room')): ?>
                    <button type="button" class="btn btn-outline-primary tc-task-chat-btn"
                            data-task-chat-url="<?= e(url('chat/salas/tarefa/' . $task['id'])) ?>"
                            data-chat-index-url="<?= e(url('chat')) ?>"
                            data-csrf-token="<?= e($csrfToken) ?>">
                        <i class="fa-solid fa-comments me-1"></i> Conversa da tarefa
                    </button>
                <?php endif; ?>

                <?php if ($canManage): ?>
                <a href="<?= e(url('tarefas/' . $task['id'] . '/edit')) ?>" class="btn btn-outline-secondary ms-auto">
                    <i class="fa-solid fa-pen me-1"></i> Editar
                </a>
                <form method="POST" action="<?= e(url('tarefas/' . $task['id'] . '/excluir')) ?>" class="tc-delete-form" data-confirm-text="A tarefa será excluída permanentemente.">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-outline-danger"><i class="fa-solid fa-trash me-1"></i> Excluir</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Comentários -->
        <div class="tc-card">
            <div class="tc-card-header">Comentários</div>
            <div class="tc-card-body">
                <?php if (empty($comments)): ?>
                    <p class="text-muted mb-3" style="font-size:0.85rem;">Nenhum comentário ainda.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-3">
                        <?php foreach ($comments as $comment): ?>
                            <li class="mb-3 pb-3 border-bottom">
                                <div style="font-size:0.85rem;"><?= nl2br(e($comment['comment'])) ?></div>
                                <div class="text-muted" style="font-size:0.72rem;">
                                    <?= e($comment['user_name'] ?? 'Sistema') ?> · <?= e(time_ago($comment['created_at'])) ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <form method="POST" action="<?= e(url('tarefas/' . $task['id'] . '/comentarios')) ?>">
                    <?= Csrf::field() ?>
                    <textarea name="comment" class="form-control mb-2" rows="3" placeholder="Adicionar comentário/observação sobre o andamento..." required></textarea>
                    <button type="submit" class="btn btn-tc-primary btn-sm">
                        <i class="fa-solid fa-paper-plane me-1"></i> Comentar
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="tc-card">
            <div class="tc-card-header">Histórico / Timeline</div>
            <div class="tc-card-body" style="max-height: 600px; overflow-y: auto;">
                <?php if (empty($history)): ?>
                    <p class="text-muted mb-0" style="font-size:0.85rem;">Nenhum evento registrado ainda.</p>
                <?php endif; ?>
                <ul class="list-unstyled mb-0">
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

<?php
// Fase 7: botão "Concluir e sincronizar contato do lead" - AJAX próprio,
// separado do fluxo padrão de status (app.js::initTaskQuickStatus) para não
// interferir no botão "Concluir" comum. Ver TaskController::changeStatus()
// e syncLeadOnTaskCompleted().
$pageScripts = '<script>
document.addEventListener("DOMContentLoaded", function () {
    var btn = document.getElementById("tcTaskCompleteSyncLead");
    if (!btn) {
        return;
    }
    btn.addEventListener("click", function () {
        var url = btn.getAttribute("data-url");
        var csrfToken = btn.getAttribute("data-csrf-token");
        btn.disabled = true;
        fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ csrf_token: csrfToken, status: "concluida", sync_lead_contact: "1" }).toString()
        }).then(function (resp) { return resp.json(); }).then(function (data) {
            if (data && data.success) {
                if (typeof Swal !== "undefined") {
                    Swal.fire({ icon: "success", title: "Tarefa concluída e lead sincronizado!", timer: 1400, showConfirmButton: false });
                }
                window.setTimeout(function () { window.location.reload(); }, 600);
            } else {
                btn.disabled = false;
                if (typeof Swal !== "undefined") {
                    Swal.fire("Erro", (data && data.message) || "Não foi possível concluir a tarefa.", "error");
                }
            }
        }).catch(function () {
            btn.disabled = false;
            if (typeof Swal !== "undefined") {
                Swal.fire("Erro", "Falha de comunicação ao concluir a tarefa.", "error");
            }
        });
    });
});
</script>';
?>
