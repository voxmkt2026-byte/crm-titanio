<?php
/**
 * app/views/today/index.php
 * "Meu Dia" (Fase 7): visão unificada e pessoal do que fazer agora.
 * Ver TodayController - combina leads atrasados (Agenda), tarefas de
 * hoje/atrasadas (Tarefas) e leads sem primeiro contato (SLA).
 */
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <a href="<?= e(url('agenda')) ?>" class="text-decoration-none">
            <div class="tc-kpi-card" style="<?= count($overdueLeads) > 0 ? 'border-left: 4px solid var(--tc-danger);' : '' ?>">
                <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#dc2626,#f87171);"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div>
                    <div class="tc-kpi-value"><?= count($overdueLeads) ?></div>
                    <div class="tc-kpi-label">Leads vencidos</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-4">
        <a href="<?= e(url('tarefas?overdue=1')) ?>" class="text-decoration-none">
            <div class="tc-kpi-card" style="<?= count($overdueTasks) > 0 ? 'border-left: 4px solid var(--tc-warning);' : '' ?>">
                <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#d97706,#f59e0b);"><i class="fa-solid fa-list-check"></i></div>
                <div>
                    <div class="tc-kpi-value"><?= count($overdueTasks) ?></div>
                    <div class="tc-kpi-label">Tarefas de hoje/atrasadas</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-4">
        <a href="<?= e(url('sla')) ?>" class="text-decoration-none">
            <div class="tc-kpi-card" style="<?= count($leadsWithoutContact) > 0 ? 'border-left: 4px solid var(--tc-accent);' : '' ?>">
                <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#3b82f6,#4f46e5);"><i class="fa-solid fa-comment-slash"></i></div>
                <div>
                    <div class="tc-kpi-value"><?= count($leadsWithoutContact) ?></div>
                    <div class="tc-kpi-label">Leads sem primeiro contato</div>
                </div>
            </div>
        </a>
    </div>
</div>

<?php if (!empty($goalProgress)): ?>
<div class="tc-card mb-4">
    <div class="tc-card-header"><i class="fa-solid fa-bullseye me-2"></i>Minhas metas do mês</div>
    <div class="tc-card-body">
        <?php foreach ($goalProgress as $progressIndex => $progress): ?>
            <?php
                $currentLabel = $progress['format'] === 'moeda' ? format_money($progress['current']) : (int) $progress['current'];
                $targetLabel = $progress['format'] === 'moeda' ? format_money($progress['target']) : (int) $progress['target'];
            ?>
            <div class="<?= $progressIndex > 0 ? 'mt-3' : '' ?>">
                <div class="d-flex justify-content-between mb-1" style="font-size:0.85rem;">
                    <span><i class="fa-solid <?= e($progress['icon']) ?> me-1"></i><?= e($progress['label']) ?>: <?= e($currentLabel) ?> de <?= e($targetLabel) ?></span>
                    <span class="fw-semibold"><?= (int) $progress['percentage'] ?>%</span>
                </div>
                <div class="progress" style="height: 10px;">
                    <div class="progress-bar <?= $progress['percentage'] >= 100 ? 'bg-success' : 'bg-tc-primary' ?>"
                         role="progressbar" style="width: <?= (int) $progress['percentage'] ?>%; <?= $progress['percentage'] < 100 ? 'background: var(--tc-accent);' : '' ?>"
                         aria-valuenow="<?= (int) $progress['percentage'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="tc-card">
    <div class="tc-card-header d-flex align-items-center justify-content-between">
        <span><i class="fa-solid fa-bolt me-2"></i>Prioridades de agora</span>
        <span class="badge bg-secondary"><?= count($combined) ?></span>
    </div>

    <?php if (empty($combined)): ?>
        <div class="tc-card-body text-center text-muted py-5">
            <i class="fa-solid fa-circle-check fa-2x mb-2 d-block" style="color: var(--tc-success, #16a34a);"></i>
            Tudo em dia por aqui. Nenhuma pendência urgente no momento.
        </div>
    <?php else: ?>
        <div class="tc-table-card" style="box-shadow:none;border:none;">
            <div class="table-responsive">
                <table class="table tc-table mb-0">
                    <thead>
                        <tr>
                            <th style="width:40px;"></th>
                            <th>Item</th>
                            <th>Detalhe</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($combined as $item): ?>
                            <tr>
                                <td>
                                    <?php if ($item['kind'] === 'lead_overdue'): ?>
                                        <i class="fa-solid fa-triangle-exclamation text-danger" title="Lead vencido"></i>
                                    <?php elseif ($item['kind'] === 'task'): ?>
                                        <i class="fa-solid fa-list-check text-warning" title="Tarefa"></i>
                                    <?php else: ?>
                                        <i class="fa-solid fa-comment-slash text-primary" title="Sem primeiro contato"></i>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-semibold"><?= e($item['title']) ?></td>
                                <td class="text-muted" style="font-size:0.82rem;"><?= e($item['subtitle']) ?></td>
                                <td class="text-end">
                                    <?php if ($item['kind'] === 'task'): ?>
                                        <a href="<?= e(url('tarefas/' . $item['data']['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="Abrir tarefa">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-success tc-today-task-complete"
                                                data-task-id="<?= (int) $item['data']['id'] ?>" title="Marcar como concluída">
                                            <i class="fa-solid fa-check"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-tc-primary tc-today-quick-contact"
                                                data-lead-id="<?= (int) $item['data']['id'] ?>"
                                                data-lead-name="<?= e($item['title']) ?>" title="Registrar contato agora">
                                            <i class="fa-solid fa-comment-dots"></i>
                                        </button>
                                        <a href="<?= e(url('leads/' . $item['data']['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="Ver perfil do lead">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
// Reaproveita o MESMO endpoint AJAX de "Registrar contato agora" da Agenda
// (AgendaController::quickContact) e o mesmo endpoint de mudança de status
// de tarefa (TaskController::changeStatus), sem duplicar lógica de backend.
$pageScripts = '<script>
document.addEventListener("DOMContentLoaded", function () {
    var csrfToken = ' . json_encode(Csrf::token()) . ';
    var quickContactUrlBase = ' . json_encode(url('agenda')) . ';
    var taskStatusUrlBase = ' . json_encode(url('tarefas')) . ';

    document.querySelectorAll(".tc-today-quick-contact").forEach(function (btn) {
        btn.addEventListener("click", function () {
            if (typeof Swal === "undefined" || typeof $ === "undefined") {
                return;
            }
            var leadId = btn.getAttribute("data-lead-id");
            var leadName = btn.getAttribute("data-lead-name");

            Swal.fire({
                title: "Registrar contato agora",
                html:
                    "<p class=\"text-muted mb-2\" style=\"font-size:0.85rem;\">Lead: <strong>" + leadName + "</strong></p>" +
                    "<select id=\"tcTodayQuickType\" class=\"form-select mb-2\">" +
                        "<option value=\"contato\">Contato (observação manual)</option>" +
                        "<option value=\"ligacao\">Ligação</option>" +
                        "<option value=\"whatsapp\">WhatsApp</option>" +
                    "</select>" +
                    "<textarea id=\"tcTodayQuickDescription\" class=\"form-control mb-2\" rows=\"3\" placeholder=\"O que foi tratado no contato?\"></textarea>" +
                    "<label class=\"form-label mb-1\" style=\"font-size:0.8rem;\">Próximo contato (opcional)</label>" +
                    "<input type=\"datetime-local\" id=\"tcTodayQuickNext\" class=\"form-control\">",
                showCancelButton: true,
                confirmButtonText: "Registrar",
                cancelButtonText: "Cancelar",
                focusConfirm: false,
                preConfirm: function () {
                    var description = document.getElementById("tcTodayQuickDescription").value.trim();
                    if (!description) {
                        Swal.showValidationMessage("Descreva o que foi tratado no contato.");
                        return false;
                    }
                    return {
                        type: document.getElementById("tcTodayQuickType").value,
                        description: description,
                        next_contact_at: document.getElementById("tcTodayQuickNext").value
                    };
                }
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }
                $.ajax({
                    url: quickContactUrlBase + "/" + leadId + "/quick-contact",
                    method: "POST",
                    dataType: "json",
                    data: {
                        csrf_token: csrfToken,
                        type: result.value.type,
                        description: result.value.description,
                        next_contact_at: result.value.next_contact_at
                    }
                }).done(function (resp) {
                    if (resp && resp.success) {
                        var extra = "";
                        if (resp.open_task_warning) {
                            extra = "<p class=\"mt-2\" style=\"font-size:0.8rem;\">" + resp.open_task_warning + "</p>";
                        }
                        Swal.fire({ icon: "success", title: "Contato registrado!", html: extra, timer: extra ? undefined : 1500, showConfirmButton: !!extra })
                            .then(function () { window.location.reload(); });
                    } else {
                        Swal.fire("Erro", (resp && resp.message) || "Não foi possível registrar o contato.", "error");
                    }
                }).fail(function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) || "Falha de comunicação ao registrar o contato.";
                    Swal.fire("Erro", msg, "error");
                });
            });
        });
    });

    document.querySelectorAll(".tc-today-task-complete").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var taskId = btn.getAttribute("data-task-id");
            if (typeof Swal === "undefined" || typeof $ === "undefined") {
                return;
            }
            Swal.fire({
                title: "Concluir tarefa?",
                text: "A tarefa será marcada como concluída.",
                icon: "question",
                showCancelButton: true,
                confirmButtonText: "Concluir",
                cancelButtonText: "Cancelar"
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }
                $.ajax({
                    url: taskStatusUrlBase + "/" + taskId + "/status",
                    method: "POST",
                    dataType: "json",
                    data: { csrf_token: csrfToken, status: "concluida", sync_lead_contact: 1 }
                }).done(function (resp) {
                    if (resp && resp.success) {
                        Swal.fire({ icon: "success", title: "Tarefa concluída!", timer: 1200, showConfirmButton: false })
                            .then(function () { window.location.reload(); });
                    } else {
                        Swal.fire("Erro", (resp && resp.message) || "Não foi possível concluir a tarefa.", "error");
                    }
                }).fail(function () {
                    Swal.fire("Erro", "Falha de comunicação ao concluir a tarefa.", "error");
                });
            });
        });
    });
});
</script>';
?>
