<?php
/**
 * app/views/goals/index.php
 * Definição de metas mensais por função comercial. Ver GoalController.
 */
$monthLabels = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
    7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
];
?>

<?php
$functionLabels = [
    'sdr'        => ['label' => 'SDR', 'icon' => 'fa-headset', 'class' => 'primary'],
    'vendedor'   => ['label' => 'Vendedor', 'icon' => 'fa-handshake', 'class' => 'success'],
    'supervisor' => ['label' => 'Supervisor', 'icon' => 'fa-people-group', 'class' => 'warning'],
];
?>

<div class="tc-insight-card mb-3">
    <i class="fa-solid fa-bullseye"></i>
    <span><strong>Metas adequadas à função:</strong> SDR acompanha leads trabalhados; vendedor acompanha fechamentos e valor vendido; supervisor acompanha o valor fechado pela equipe do departamento.</span>
</div>

<div class="tc-card mb-3">
    <div class="tc-card-body">
        <form method="GET" action="<?= e(url('metas')) ?>" class="d-flex gap-2 align-items-end flex-wrap">
            <div>
                <label class="form-label mb-1">Mês</label>
                <select name="month" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($monthLabels as $num => $label): ?>
                        <option value="<?= (int) $num ?>" <?= $num === $month ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label mb-1">Ano</label>
                <input type="number" name="year" class="form-control" value="<?= (int) $year ?>" style="width:110px;" onchange="this.form.submit()">
            </div>
        </form>
    </div>
</div>

<form method="POST" action="<?= e(url('metas/update')) ?>">
    <?= Csrf::field() ?>
    <input type="hidden" name="year" value="<?= (int) $year ?>">
    <input type="hidden" name="month" value="<?= (int) $month ?>">

    <div class="tc-table-card">
        <div class="table-responsive">
            <table class="table tc-table mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Colaborador</th>
                        <th style="width:170px;">Função comercial</th>
                        <th>Metas do mês</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($goals)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">Nenhum usuário ativo encontrado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($goals as $g): ?>
                        <?php $function = $g['commercial_function'] ?? (($g['role'] ?? '') === 'supervisor' ? 'supervisor' : 'vendedor'); ?>
                        <tr>
                            <td class="fw-semibold">
                                <?= e($g['user_name']) ?>
                                <input type="hidden" name="user_id[]" value="<?= (int) $g['user_id'] ?>">
                            </td>
                            <td>
                                <?php $functionMeta = $functionLabels[$function] ?? $functionLabels['vendedor']; ?>
                                <span class="badge bg-<?= e($functionMeta['class']) ?> <?= $function === 'supervisor' ? 'text-dark' : '' ?>">
                                    <i class="fa-solid <?= e($functionMeta['icon']) ?> me-1"></i><?= e($functionMeta['label']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($function === 'sdr'): ?>
                                    <label class="form-label mb-1" for="goal_leads_<?= (int) $g['user_id'] ?>">Leads trabalhados no mês</label>
                                    <input id="goal_leads_<?= (int) $g['user_id'] ?>" type="number" min="0" class="form-control"
                                           name="target_new_leads[<?= (int) $g['user_id'] ?>]"
                                           value="<?= $g['target_new_leads'] !== null ? (int) $g['target_new_leads'] : '' ?>"
                                           placeholder="Ex.: 120">
                                    <div class="form-text">Conta leads distintos com atendimento registrado por este SDR.</div>
                                <?php elseif ($function === 'supervisor'): ?>
                                    <label class="form-label mb-1" for="goal_sales_<?= (int) $g['user_id'] ?>">Meta de vendas da equipe (R$)</label>
                                    <input id="goal_sales_<?= (int) $g['user_id'] ?>" type="number" min="0" step="0.01" inputmode="decimal" class="form-control"
                                           name="target_sales_value[<?= (int) $g['user_id'] ?>]"
                                           value="<?= $g['target_sales_value'] !== null ? number_format((float) $g['target_sales_value'], 2, '.', '') : '' ?>"
                                           placeholder="Ex.: 150000.00">
                                    <div class="form-text">Soma as vendas fechadas pelos membros do mesmo departamento.</div>
                                <?php else: ?>
                                    <div class="row g-2">
                                        <div class="col-md-5">
                                            <label class="form-label mb-1" for="goal_closed_<?= (int) $g['user_id'] ?>">Fechamentos</label>
                                            <input id="goal_closed_<?= (int) $g['user_id'] ?>" type="number" min="0" class="form-control"
                                                   name="target_closed_deals[<?= (int) $g['user_id'] ?>]"
                                                   value="<?= (int) ($g['target_closed_deals'] ?? 0) ?>">
                                        </div>
                                        <div class="col-md-7">
                                            <label class="form-label mb-1" for="goal_sales_<?= (int) $g['user_id'] ?>">Valor vendido (R$) <span class="text-muted">(opcional)</span></label>
                                            <input id="goal_sales_<?= (int) $g['user_id'] ?>" type="number" min="0" step="0.01" inputmode="decimal" class="form-control"
                                                   name="target_sales_value[<?= (int) $g['user_id'] ?>]"
                                                   value="<?= $g['target_sales_value'] !== null ? number_format((float) $g['target_sales_value'], 2, '.', '') : '' ?>"
                                                   placeholder="Ex.: 50000.00">
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-tc-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i> Salvar Metas de <?= e($monthLabels[$month] ?? $month) ?>/<?= (int) $year ?>
        </button>
    </div>
</form>
