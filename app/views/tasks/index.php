<?php
/**
 * app/views/tasks/index.php
 * Listagem de tarefas com abas rápidas (Minhas Tarefas / Criadas por mim /
 * Todas), filtros, badges de prioridade/status e indicador de SLA.
 */

$priorityColors = ['baixa' => 'secondary', 'media' => 'primary', 'alta' => 'warning', 'urgente' => 'danger'];
$priorityLabels = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'];
$statusColors = ['pendente' => 'secondary', 'em_andamento' => 'info', 'aguardando' => 'warning', 'concluida' => 'success', 'cancelada' => 'dark'];
$statusLabels = ['pendente' => 'Pendente', 'em_andamento' => 'Em Andamento', 'aguardando' => 'Aguardando', 'concluida' => 'Concluída', 'cancelada' => 'Cancelada'];

$taskColumns = [
    'pendente'     => ['label' => 'Pendentes', 'icon' => 'fa-regular fa-circle', 'class' => 'secondary'],
    'em_andamento' => ['label' => 'Em andamento', 'icon' => 'fa-solid fa-play', 'class' => 'info'],
    'aguardando'   => ['label' => 'Aguardando', 'icon' => 'fa-solid fa-hourglass-half', 'class' => 'warning'],
    'concluida'    => ['label' => 'Concluídas', 'icon' => 'fa-solid fa-circle-check', 'class' => 'success'],
    'cancelada'    => ['label' => 'Canceladas', 'icon' => 'fa-solid fa-ban', 'class' => 'dark'],
];
$tasksByStatus = array_fill_keys(array_keys($taskColumns), []);
foreach ($tasks as $task) {
    $status = $task['status'] ?? 'pendente';
    if (isset($tasksByStatus[$status])) {
        $tasksByStatus[$status][] = $task;
    }
}

function tc_task_query(array $filters, string $tab): array
{
    $q = array_filter($filters, fn($v) => $v !== '' && $v !== null);
    $q['tab'] = $tab;
    return $q;
}
?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'mine' ? 'active' : '' ?>" href="<?= e(url('tarefas?' . http_build_query(tc_task_query($filters, 'mine')))) ?>">
            <i class="fa-solid fa-user-check me-1"></i> Minhas Tarefas
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'created' ? 'active' : '' ?>" href="<?= e(url('tarefas?' . http_build_query(tc_task_query($filters, 'created')))) ?>">
            <i class="fa-solid fa-pen-to-square me-1"></i> Criadas por mim
        </a>
    </li>
    <?php if ($canViewAll): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'all' ? 'active' : '' ?>" href="<?= e(url('tarefas?' . http_build_query(tc_task_query($filters, 'all')))) ?>">
            <i class="fa-solid fa-list-check me-1"></i> Todas
        </a>
    </li>
    <?php endif; ?>
</ul>

<div class="tc-card mb-3">
    <div class="tc-card-body">
        <form method="GET" action="<?= e(url('tarefas')) ?>" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="col-md-3">
                <label class="form-label">Buscar</label>
                <input type="text" name="search" class="form-control" placeholder="Título ou descrição..." value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($statusLabels as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $filters['status'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Prioridade</label>
                <select name="priority" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach ($priorityLabels as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $filters['priority'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($tab === 'all'): ?>
            <div class="col-md-2">
                <label class="form-label">Responsável</label>
                <select name="assigned_to" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (string) $filters['assigned_to'] === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <div class="form-check mt-4">
                    <input type="checkbox" class="form-check-input" name="overdue" id="tcOverdue" value="1" <?= !empty($filters['overdue']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="tcOverdue">Só atrasadas</label>
                </div>
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-tc-primary"><i class="fa-solid fa-filter"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted" style="font-size:0.85rem;"><?= (int) $total ?> tarefa(s) encontrada(s)</span>
    <?php if (Auth::can('tasks.create')): ?>
    <a href="<?= e(url('tarefas/nova')) ?>" class="btn btn-tc-primary btn-sm">
        <i class="fa-solid fa-plus me-1"></i> Nova Tarefa
    </a>
    <?php endif; ?>
</div>

<?php if (empty($tasks)): ?>
    <div class="tc-card">
        <div class="tc-card-body text-center text-muted py-5">
            <i class="fa-solid fa-list-check fa-2x mb-2 d-block"></i>
            Nenhuma tarefa encontrada com os filtros selecionados.
        </div>
    </div>
<?php else: ?>
    <p class="tc-kanban-hint"><i class="fa-solid fa-arrow-right-arrow-left me-1"></i>Deslize para ver todas as colunas. No computador, arraste o card para alterar o status.</p>
    <div class="tc-kanban tc-task-kanban" id="tcTaskKanbanBoard"
         data-csrf-token="<?= e(Csrf::token()) ?>"
         data-chat-index-url="<?= e(url('chat')) ?>">
        <?php foreach ($taskColumns as $status => $column): ?>
            <section class="tc-kanban-column tc-task-kanban-column tc-task-kanban-column-<?= e($status) ?>">
                <header class="tc-kanban-column-header">
                    <span><i class="<?= e($column['icon']) ?> me-1"></i><?= e($column['label']) ?></span>
                    <span class="badge bg-<?= e($column['class']) ?>"><?= count($tasksByStatus[$status]) ?></span>
                </header>
                <div class="tc-kanban-column-body tc-task-kanban-column-body" data-task-status="<?= e($status) ?>">
                    <?php foreach ($tasksByStatus[$status] as $task): ?>
                        <?php
                            $isOverdue = !empty($task['due_at']) && strtotime($task['due_at']) < time() && !in_array($task['status'], ['concluida', 'cancelada'], true);
                            $summary = trim((string) ($task['description'] ?? ''));
                            if ($summary !== '') {
                                $summary = mb_strimwidth($summary, 0, 112, '...');
                            }
                        ?>
                        <article class="tc-kanban-card tc-task-kanban-card <?= $isOverdue ? 'tc-task-card-overdue' : '' ?>"
                                 data-task-id="<?= (int) $task['id'] ?>"
                                 data-status-url="<?= e(url('tarefas/' . $task['id'] . '/status')) ?>">
                            <div class="d-flex align-items-start justify-content-between gap-2">
                                <a href="<?= e(url('tarefas/' . $task['id'])) ?>" class="tc-card-name tc-task-card-title text-decoration-none text-reset">
                                    <?= e($task['title']) ?>
                                </a>
                                <span class="badge bg-<?= e($priorityColors[$task['priority']] ?? 'secondary') ?> flex-shrink-0"><?= e($priorityLabels[$task['priority']] ?? $task['priority']) ?></span>
                            </div>

                            <?php if ($summary !== ''): ?>
                                <p class="tc-task-card-description"><?= e($summary) ?></p>
                            <?php endif; ?>

                            <div class="tc-card-meta tc-task-card-meta">
                                <?php if (!empty($task['lead_id'])): ?>
                                    <a href="<?= e(url('leads/' . $task['lead_id'])) ?>" class="text-decoration-none">
                                        <i class="fa-solid fa-address-card me-1"></i><?= e($task['lead_name'] ?: 'Lead #' . $task['lead_id']) ?>
                                    </a>
                                <?php endif; ?>
                                <span><i class="fa-solid fa-user me-1"></i><?= e($task['assigned_name'] ?: 'Não atribuído') ?></span>
                                <?php if (!empty($task['due_at'])): ?>
                                    <span class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
                                        <i class="fa-regular fa-calendar me-1"></i><?= e(format_date($task['due_at'], true)) ?>
                                        <?= $isOverdue ? ' · atrasada' : '' ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="tc-task-card-footer">
                                <a href="<?= e(url('tarefas/' . $task['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="Abrir tarefa">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i><span class="visually-hidden">Abrir tarefa</span>
                                </a>
                                <?php if (Auth::can('chat.create_room')): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary tc-task-chat-btn" title="Abrir conversa da tarefa"
                                            data-task-chat-url="<?= e(url('chat/salas/tarefa/' . $task['id'])) ?>">
                                        <i class="fa-solid fa-comments"></i><span class="visually-hidden">Abrir conversa</span>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="tc-kanban-move-btn tc-task-kanban-move-btn ms-auto" title="Mover para outro status">
                                    <i class="fa-solid fa-arrow-right"></i>
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?= render_pagination($page, $totalPages, url('tarefas'), array_merge($filters, ['tab' => $tab, 'per_page' => $perPage])) ?>
