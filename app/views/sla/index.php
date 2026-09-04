<?php
/**
 * app/views/sla/index.php
 * Dashboard de SLA de atendimento (Fase 2).
 */
function tc_minutes_label(?float $minutes): string
{
    if ($minutes === null) {
        return '-';
    }
    if ($minutes < 60) {
        return str_replace('.', ',', (string) $minutes) . ' min';
    }
    $hours = floor($minutes / 60);
    $rest = round($minutes - ($hours * 60));
    return $hours . 'h ' . $rest . 'min';
}
?>

<div class="tc-insight-card mb-4" style="border-left: 4px solid var(--tc-accent);">
    <i class="fa-solid fa-circle-info"></i>
    <span>
        <strong>O que conta como "primeira resposta":</strong> qualquer contato (ligação, WhatsApp ou registro manual
        de "contato") feito pela equipe após o lead entrar no sistema. O tempo é calculado da criação do lead até
        esse primeiro evento. Leads sem nenhum evento de contato registrado em até 24h aparecem como "aguardando" na
        tabela abaixo.
    </span>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#3b82f6,#4f46e5);"><i class="fa-solid fa-stopwatch"></i></div>
            <div>
                <div class="tc-kpi-value"><?= e(tc_minutes_label($avgMinutes)) ?></div>
                <div class="tc-kpi-label">Tempo médio até o 1º contato</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#16a34a,#22c55e);"><i class="fa-solid fa-bolt"></i></div>
            <div>
                <div class="tc-kpi-value"><?= e(str_replace('.', ',', (string) $pctAte15)) ?>%</div>
                <div class="tc-kpi-label">Respondidos em até 15 min</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#d97706,#f59e0b);"><i class="fa-solid fa-headset"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $totalRespondidos ?></div>
                <div class="tc-kpi-label">Leads com 1º contato registrado</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="tc-kpi-card" style="<?= $waitingOver24h > 0 ? 'border-left: 4px solid var(--tc-danger);' : '' ?>">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#dc2626,#f87171);"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $waitingOver24h ?></div>
                <div class="tc-kpi-label">Aguardando &gt; 24h sem contato</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="tc-card">
            <div class="tc-card-header">SLA por consultor</div>
            <div class="tc-table-card" style="box-shadow:none;border:none;">
                <div class="table-responsive">
                    <table class="table tc-table mb-0">
                        <thead>
                            <tr>
                                <th>Consultor</th>
                                <th>Leads atribuídos</th>
                                <th>Com 1º contato</th>
                                <th>Tempo médio até 1º contato</th>
                                <th>% em até 15 min</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($byConsultant)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">Nenhum consultor ativo encontrado.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($byConsultant as $c): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($c['name']) ?></td>
                                    <td><?= (int) $c['total_atribuidos'] ?></td>
                                    <td><?= (int) $c['total_respondidos'] ?></td>
                                    <td><?= e(tc_minutes_label($c['avg_minutes'])) ?></td>
                                    <td>
                                        <?php if ($c['pct_15min'] === null): ?>
                                            -
                                        <?php else: ?>
                                            <span class="badge bg-<?= $c['pct_15min'] >= 70 ? 'success' : ($c['pct_15min'] >= 40 ? 'warning' : 'danger') ?>">
                                                <?= e(str_replace('.', ',', (string) $c['pct_15min'])) ?>%
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12">
        <div class="tc-card">
            <div class="tc-card-header">
                <i class="fa-solid fa-clock text-danger me-1"></i>
                Leads aguardando primeiro contato há mais de 24h
            </div>
            <div class="tc-table-card" style="box-shadow:none;border:none;">
                <div class="table-responsive">
                    <table class="table tc-table mb-0">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Telefone</th>
                                <th>Responsável</th>
                                <th>Criado em</th>
                                <th>Aguardando há</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($waitingLeads)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Nenhum lead aguardando fora do SLA. Parabéns!</td></tr>
                            <?php endif; ?>
                            <?php foreach ($waitingLeads as $lead): ?>
                                <tr>
                                    <td><?= e($lead['name'] ?: 'Sem nome') ?></td>
                                    <td><?= e(format_phone($lead['whatsapp'] ?: $lead['phone'])) ?></td>
                                    <td><?= e($lead['assigned_name'] ?: '-') ?></td>
                                    <td><?= e(format_date($lead['created_at'], true)) ?></td>
                                    <td><span class="badge bg-danger"><?= e(time_ago($lead['created_at'])) ?></span></td>
                                    <td class="text-end">
                                        <a href="<?= e(url('leads/' . $lead['id'])) ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="fa-solid fa-eye"></i> Ver
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
