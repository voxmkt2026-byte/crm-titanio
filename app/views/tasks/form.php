<?php
/**
 * app/views/tasks/form.php
 * Formulário de criação/edição de tarefa.
 */

$isEdit = !empty($task);
$t = fn($key, $default = '') => e((string) ($task[$key] ?? $default));

$priorityLabels = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'];

$dueAtValue = '';
if ($isEdit && !empty($task['due_at'])) {
    $dueAtValue = str_replace(' ', 'T', substr($task['due_at'], 0, 16));
}

$leadIdValue = $isEdit ? (int) ($task['lead_id'] ?? 0) : (!empty($preselectedLead) ? (int) $preselectedLead['id'] : 0);
$leadLabel = $isEdit ? ($task['lead_name'] ?? '') : (!empty($preselectedLead) ? ($preselectedLead['name'] ?: 'Lead #' . $preselectedLead['id']) : '');
?>

<form method="POST" action="<?= e($formAction) ?>" id="taskForm">
    <?= Csrf::field() ?>

    <div class="tc-card mb-3">
        <div class="tc-card-body">
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label">Título <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" value="<?= $t('title') ?>" placeholder="Ex: Enviar proposta para o cliente" required>
                </div>

                <div class="col-md-12">
                    <label class="form-label">Descrição / Observações</label>
                    <textarea name="description" class="form-control" rows="4" placeholder="Detalhes da demanda..."><?= $t('description') ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Responsável</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">Não atribuído</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= (int) ($task['assigned_to'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Prioridade</label>
                    <select name="priority" class="form-select">
                        <?php foreach ($priorityLabels as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= ($task['priority'] ?? 'media') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Lead vinculado (opcional)</label>
                    <div class="tc-lead-picker" data-url="<?= e(url('leads/buscar-rapido')) ?>">
                        <input type="hidden" name="lead_id" value="<?= $leadIdValue ?: '' ?>">
                        <input type="text" class="form-control tc-lead-search <?= $leadIdValue ? 'is-valid' : '' ?>" placeholder="Buscar por nome ou telefone" value="<?= e($leadLabel) ?>" autocomplete="off">
                        <div class="tc-lookup-results"></div>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Prazo</label>
                    <input type="datetime-local" name="due_at" class="form-control" value="<?= e($dueAtValue) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">SLA (horas a partir da criação)</label>
                    <input type="number" name="sla_hours" class="form-control" min="1" placeholder="Ex: 4" value="<?= $t('sla_hours') ?>">
                    <div class="form-text">Deixe em branco se a tarefa não tiver um prazo de SLA formal.</div>
                </div>

                <?php if (!$isEdit): ?>
                <div class="col-md-12">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="sync_next_contact" value="1" id="syncNextContact">
                        <label class="form-check-label" for="syncNextContact" style="font-size:0.85rem;">
                            Também atualizar o próximo contato deste lead (Agenda) para a mesma data do prazo acima
                        </label>
                    </div>
                    <div class="form-text">Só tem efeito se um lead vinculado e um prazo forem informados.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-tc-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i> <?= $isEdit ? 'Salvar Alterações' : 'Criar Tarefa' ?>
        </button>
        <a href="<?= e(url($isEdit ? 'tarefas/' . $task['id'] : 'tarefas')) ?>" class="btn btn-outline-secondary">Cancelar</a>
    </div>
</form>
