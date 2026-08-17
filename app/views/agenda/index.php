<?php
/**
 * app/views/agenda/index.php
 * Lista de leads agendados (next_contact_at), agrupados por urgência, com
 * contadores de resumo, filtro por consultor (Fase 5) e ação rápida
 * "Registrar contato agora" (AJAX) em cada linha.
 */
function tc_agenda_quick_actions(array $lead): string
{
    $name = $lead['name'] ?: ('Lead #' . $lead['id']);
    return '<button type="button" class="btn btn-sm btn-tc-primary tc-agenda-quick-contact"'
        . ' data-lead-id="' . (int) $lead['id'] . '" data-lead-name="' . e($name) . '" title="Registrar contato agora">'
        . '<i class="fa-solid fa-comment-dots"></i></button> '
        . '<a href="' . e(url('leads/' . $lead['id'])) . '" class="btn btn-sm btn-outline-secondary" title="Ver perfil do lead">'
        . '<i class="fa-solid fa-eye"></i></a> '
        . (!empty($lead['whatsapp']) || !empty($lead['phone'])
            ? '<a href="' . e(url('leads/' . $lead['id'] . '?open=whatsapp')) . '" class="btn btn-sm btn-outline-success" title="Enviar WhatsApp">'
              . '<i class="fa-brands fa-whatsapp"></i></a>'
            : '');
}

function tc_agenda_section(string $title, string $icon, string $color, array $items, string $dateColumnLabel = 'Agendado para', string $dateField = 'next_contact_at'): void
{
    ?>
    <div class="tc-card mb-3">
        <div class="tc-card-header d-flex align-items-center justify-content-between">
            <span><i class="fa-solid <?= e($icon) ?> me-2" style="color: <?= e($color) ?>;"></i><?= e($title) ?></span>
            <span class="badge" style="background: <?= e($color) ?>;"><?= count($items) ?></span>
        </div>
        <?php if (empty($items)): ?>
            <div class="tc-card-body text-muted text-center py-3" style="font-size:0.85rem;">Nenhum lead nesta faixa.</div>
        <?php else: ?>
            <div class="tc-table-card" style="box-shadow:none;border:none;">
                <div class="table-responsive">
                    <table class="table tc-table mb-0">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Telefone</th>
                                <th>Interesse</th>
                                <th>Status</th>
                                <th>Responsável</th>
                                <th><?= e($dateColumnLabel) ?></th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $lead): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($lead['name'] ?: 'Sem nome') ?></td>
                                    <td><?= e(format_phone($lead['whatsapp'] ?: $lead['phone'])) ?></td>
                                    <td><?= e(interest_label($lead['interest'])) ?></td>
                                    <td><span class="badge bg-<?= e(status_color($lead['status'])) ?>"><?= e(status_label($lead['status'])) ?></span></td>
                                    <td><?= e($lead['assigned_name'] ?: '-') ?></td>
                                    <td><?= e(format_date($lead[$dateField] ?? null, true)) ?></td>
                                    <td class="text-end"><?= tc_agenda_quick_actions($lead) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
?>

<div id="tcAgendaApp"
     data-csrf-token="<?= e(Csrf::token()) ?>"
     data-search-url="<?= e(url('leads/buscar-rapido')) ?>"
     data-schedule-url="<?= e(url('agenda/agendar')) ?>"
     data-quick-contact-url-base="<?= e(url('agenda')) ?>">

<div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn btn-tc-primary" id="tcAgendaNewBtn">
        <i class="fa-solid fa-calendar-plus me-1"></i> Novo Agendamento
    </button>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="tc-kpi-card" style="<?= $counts['atrasados'] > 0 ? 'border-left: 4px solid var(--tc-danger);' : '' ?>">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#dc2626,#f87171);"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $counts['atrasados'] ?></div>
                <div class="tc-kpi-label">Atrasados</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#3b82f6,#4f46e5);"><i class="fa-solid fa-calendar-day"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $counts['hoje'] ?></div>
                <div class="tc-kpi-label">Hoje</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#d97706,#f59e0b);"><i class="fa-solid fa-calendar-week"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $counts['semana'] ?></div>
                <div class="tc-kpi-label">Próximos 7 dias</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tc-kpi-card" style="<?= $counts['sem_data'] > 0 ? 'border-left: 4px solid var(--tc-warning);' : '' ?>">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#6b7280,#9ca3af);"><i class="fa-solid fa-calendar-xmark"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $counts['sem_data'] ?></div>
                <div class="tc-kpi-label">Sem data, ativos há <?= (int) $forgottenDays ?>+ dias</div>
            </div>
        </div>
    </div>
</div>

<?php if ($canViewAll): ?>
<div class="tc-card mb-3">
    <div class="tc-card-body">
        <form method="GET" action="<?= e(url('agenda')) ?>" class="d-flex gap-2 align-items-end">
            <div>
                <label class="form-label mb-1">Filtrar por responsável</label>
                <select name="assigned_to" class="form-select" onchange="this.form.submit()">
                    <option value="">Todos os consultores</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= $assignedTo === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
tc_agenda_section('Atrasados', 'fa-triangle-exclamation', 'var(--tc-danger)', $groups['atrasados']);
tc_agenda_section('Hoje', 'fa-calendar-day', 'var(--tc-accent)', $groups['hoje']);
tc_agenda_section('Próximos 7 dias', 'fa-calendar-week', 'var(--tc-warning)', $groups['semana']);
tc_agenda_section('Mais adiante', 'fa-calendar', 'var(--tc-text-muted)', $groups['futuro']);
tc_agenda_section(
    'Sem data definida, ativos há ' . (int) $forgottenDays . '+ dias ("esquecidos")',
    'fa-calendar-xmark',
    'var(--tc-text-muted)',
    $withoutDate,
    'Cadastrado em',
    'created_at'
);
?>

<?php if ($withoutDateTotal > count($withoutDate)): ?>
    <p class="text-muted text-center" style="font-size:0.8rem;">
        Mostrando <?= count($withoutDate) ?> de <?= (int) $withoutDateTotal ?> leads sem data definida. Use os filtros da tela de Leads para ver a lista completa.
    </p>
<?php endif; ?>

</div><!-- /#tcAgendaApp -->

<?php $pageScripts = '<script src="' . e(asset('js/agenda.js')) . '"></script>'; ?>
