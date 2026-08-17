<?php
/**
 * app/views/leads/index.php
 * Listagem de leads com busca, filtros, ordenação e paginação server-side.
 * Fase 7 (auditoria UX): colunas de Score/Temperatura/Últ. contato, escopo
 * "Meus leads"/"Todos os leads", nota rápida via AJAX e ações em lote.
 */

function tc_sort_link(string $column, string $label, string $sortBy, string $sortDir, array $filters, string $scope): string
{
    $dir = ($sortBy === $column && $sortDir === 'ASC') ? 'DESC' : 'ASC';
    $query = array_merge($filters, ['sort' => $column, 'dir' => $dir, 'view' => $scope]);
    $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
    $icon = '';
    if ($sortBy === $column) {
        $icon = $sortDir === 'ASC' ? ' <i class="fa-solid fa-arrow-up-short-wide"></i>' : ' <i class="fa-solid fa-arrow-down-wide-short"></i>';
    }
    return '<a href="' . e(url('leads?' . http_build_query($query))) . '" class="text-decoration-none text-reset">' . e($label) . $icon . '</a>';
}

// Filtros "visíveis" (sem a chave 'view', tratada à parte pelo toggle de escopo)
$tcVisibleFilters = array_filter($filters, fn($v) => $v !== '' && $v !== null);
$tcIsTodayView = !empty($filters['created_today']);
$tcCanTransfer = $canViewAll && Auth::can('leads.edit');
?>

<?php if (!empty($filters['sem_contato_dias']) || !empty($filters['vencidos']) || !empty($filters['closed_from']) || !empty($filters['closed_to'])): ?>
<div class="tc-insight-card mb-3" style="border-left: 4px solid var(--tc-warning);">
    <i class="fa-solid fa-filter"></i>
    <span>
        <?php if (!empty($filters['sem_contato_dias'])): ?>
            Mostrando leads sem contato há <?= (int) $filters['sem_contato_dias'] ?>+ dias (filtro vindo dos insights do Dashboard).
        <?php elseif (!empty($filters['vencidos'])): ?>
            Mostrando leads vencidos: agendados para contato em uma data que já passou (filtro vindo dos insights do Dashboard).
        <?php else: ?>
            Mostrando vendas fechadas <?= !empty($filters['closed_from']) ? 'a partir de ' . e(format_date($filters['closed_from'])) : '' ?><?= !empty($filters['closed_from']) && !empty($filters['closed_to']) ? ' ' : '' ?><?= !empty($filters['closed_to']) ? 'até ' . e(format_date($filters['closed_to'])) : '' ?> (filtro vindo dos Indicadores).
        <?php endif; ?>
        <a href="<?= e(url('leads')) ?>" class="ms-1">Limpar filtro</a>
    </span>
</div>
<?php endif; ?>

<?php if ($canViewAll): ?>
<?php
$tcScopeBase = $tcVisibleFilters;
unset($tcScopeBase['assigned_to'], $tcScopeBase['created_today']);
if ($tcIsTodayView) {
    unset($tcScopeBase['date_from'], $tcScopeBase['date_to']);
}
$tcScopeMineUrl = url('leads?' . http_build_query(array_merge($tcScopeBase, ['view' => 'mine'])));
$tcScopeAllUrl = url('leads?' . http_build_query(array_merge($tcScopeBase, ['view' => 'all'])));
$tcScopeTodayUrl = url('leads?' . http_build_query(array_merge($tcScopeBase, ['view' => 'all', 'created_today' => '1'])));
?>
<div class="btn-group tc-scope-toggle mb-3" role="group">
    <a href="<?= e($tcScopeMineUrl) ?>" class="btn btn-sm btn-outline-secondary <?= $scope === 'mine' && !$tcIsTodayView ? 'active' : '' ?>">
        <i class="fa-solid fa-user me-1"></i> Meus leads
    </a>
    <a href="<?= e($tcScopeAllUrl) ?>" class="btn btn-sm btn-outline-secondary <?= $scope === 'all' && !$tcIsTodayView ? 'active' : '' ?>">
        <i class="fa-solid fa-users me-1"></i> Todos os leads
    </a>
    <a href="<?= e($tcScopeTodayUrl) ?>" class="btn btn-sm btn-outline-secondary <?= $tcIsTodayView ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-day me-1"></i> Leads de hoje
    </a>
</div>
<?php else: ?>
<?php
$tcScopeBase = $tcVisibleFilters;
unset($tcScopeBase['assigned_to'], $tcScopeBase['created_today']);
if ($tcIsTodayView) {
    unset($tcScopeBase['date_from'], $tcScopeBase['date_to']);
}
$tcScopeMineUrl = url('leads?' . http_build_query(array_merge($tcScopeBase, ['view' => 'mine'])));
$tcScopeTodayUrl = url('leads?' . http_build_query(array_merge($tcScopeBase, ['view' => 'mine', 'created_today' => '1'])));
?>
<div class="btn-group tc-scope-toggle mb-2" role="group">
    <a href="<?= e($tcScopeMineUrl) ?>" class="btn btn-sm btn-outline-secondary <?= !$tcIsTodayView ? 'active' : '' ?>">
        <i class="fa-solid fa-user me-1"></i> Meus leads
    </a>
    <a href="<?= e($tcScopeTodayUrl) ?>" class="btn btn-sm btn-outline-secondary <?= $tcIsTodayView ? 'active' : '' ?>">
        <i class="fa-solid fa-calendar-day me-1"></i> Leads de hoje
    </a>
</div>
<p class="text-muted mb-3" style="font-size:0.8rem;"><i class="fa-solid fa-lock me-1"></i> Mostrando apenas os leads atribuídos a você.</p>
<?php endif; ?>

<div class="tc-card mb-3">
    <div class="tc-card-body">
        <form method="GET" action="<?= e(url('leads')) ?>" class="row g-2 align-items-end">
            <?php if ($scope !== ''): ?><input type="hidden" name="view" value="<?= e($scope) ?>"><?php endif; ?>
            <div class="col-md-3">
                <label class="form-label">Buscar</label>
                <input type="text" name="search" class="form-control" placeholder="Nome, telefone, e-mail, cidade..." value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Estado</label>
                <select name="state" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($states as $uf => $name): ?>
                        <option value="<?= e($uf) ?>" <?= $filters['state'] === $uf ? 'selected' : '' ?>><?= e($uf) ?> - <?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Origem</label>
                <select name="source" class="form-select">
                    <option value="">Todas</option>
                    <?php foreach (['facebook','instagram','google','indicacao','site','landing_page','organico','cadastro_manual','whatsapp','importacao_csv','api','webhook','outros'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $filters['source'] === $s ? 'selected' : '' ?>><?= e(source_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Interesse</label>
                <select name="interest" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach (['imovel','veiculo','caminhao','moto','maquinario','agronegocio','construcao','capital_giro','investimento','quitacao','outros'] as $i): ?>
                        <option value="<?= e($i) ?>" <?= $filters['interest'] === $i ? 'selected' : '' ?>><?= e(interest_label($i)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach (['novo','primeiro_contato','tentando_contato','em_negociacao','documentacao','aguardando_cliente','aguardando_aprovacao','aprovado','fechado','perdido','sem_interesse','sem_entrada','numero_invalido','nao_responde','bloqueou','duplicado'] as $st): ?>
                        <option value="<?= e($st) ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-tc-primary"><i class="fa-solid fa-filter"></i></button>
            </div>
            <div class="col-md-3">
                <label class="form-label">Data de cadastro — de</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Data de cadastro — até</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($filters['date_to']) ?>">
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted" style="font-size:0.85rem;"><?= (int) $total ?> lead(s) encontrado(s)</span>
    <a href="<?= e(url('leads/create')) ?>" class="btn btn-tc-primary btn-sm">
        <i class="fa-solid fa-user-plus me-1"></i> Novo Lead
    </a>
</div>

<?php if (Auth::can('leads.edit')): ?>
<!-- Fase 7 (auditoria UX): barra de ações em lote, aparece quando há seleção -->
<div class="tc-bulk-bar" id="tcBulkBar"
     data-url="<?= e(url('leads/acao-em-lote')) ?>"
     data-csrf-token="<?= e(Csrf::token()) ?>">
    <span id="tcBulkCount" class="fw-semibold" style="font-size:0.85rem;">0 selecionado(s)</span>

    <select id="tcBulkStatus" class="form-select form-select-sm" style="width:auto;">
        <option value="">Mudar status para...</option>
        <?php foreach (['novo','primeiro_contato','tentando_contato','em_negociacao','documentacao','aguardando_cliente','aguardando_aprovacao','aprovado','fechado','perdido','sem_interesse','sem_entrada','numero_invalido','nao_responde','bloqueou','duplicado'] as $st): ?>
            <option value="<?= e($st) ?>"><?= e(status_label($st)) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-sm btn-outline-primary" data-bulk-action="status">Aplicar</button>

    <select id="tcBulkAssigned" class="form-select form-select-sm" style="width:auto;">
        <option value="">Reatribuir para...</option>
        <?php foreach ($users as $u): ?>
            <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-sm btn-outline-primary" data-bulk-action="assigned_to">Aplicar</button>

    <select id="tcBulkTag" class="form-select form-select-sm" style="width:auto;">
        <option value="">Aplicar tag...</option>
        <?php foreach ($allTags as $tag): ?>
            <option value="<?= (int) $tag['id'] ?>"><?= e($tag['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-sm btn-outline-primary" data-bulk-action="tag">Aplicar</button>

    <button type="button" class="btn btn-sm btn-link text-muted ms-auto" id="tcBulkClear">Limpar seleção</button>
</div>
<?php endif; ?>

<div class="tc-table-card">
    <div class="table-responsive">
        <table class="table tc-table" id="tcLeadsTable">
            <thead>
                <tr>
                    <?php if (Auth::can('leads.edit')): ?>
                    <th style="width:36px;"><input type="checkbox" class="form-check-input" id="tcSelectAll"></th>
                    <?php endif; ?>
                    <th>Código</th>
                    <th><?= tc_sort_link('name', 'Nome', $sortBy, $sortDir, $filters, $scope) ?></th>
                    <th>Telefone</th>
                    <th><?= tc_sort_link('city', 'Cidade/UF', $sortBy, $sortDir, $filters, $scope) ?></th>
                    <th><?= tc_sort_link('source', 'Origem', $sortBy, $sortDir, $filters, $scope) ?></th>
                    <th><?= tc_sort_link('interest', 'Interesse', $sortBy, $sortDir, $filters, $scope) ?></th>
                    <th><?= tc_sort_link('status', 'Status', $sortBy, $sortDir, $filters, $scope) ?></th>
                    <th><?= tc_sort_link('lead_score', 'Score', $sortBy, $sortDir, $filters, $scope) ?></th>
                    <th>Temperatura</th>
                    <th><?= tc_sort_link('last_contact_at', 'Últ. contato', $sortBy, $sortDir, $filters, $scope) ?></th>
                    <th>Responsável</th>
                    <th><?= tc_sort_link('created_at', 'Criado em', $sortBy, $sortDir, $filters, $scope) ?></th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($leads)): ?>
                    <tr><td colspan="14" class="text-center text-muted py-4">Nenhum lead encontrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($leads as $lead): ?>
                    <?php
                    $tcStale = days_since_contact_is_stale($lead['last_contact_at'] ?? null);
                    $tcEmail = trim((string) ($lead['email'] ?? ''));
                    $tcHasValidEmail = (bool) filter_var($tcEmail, FILTER_VALIDATE_EMAIL);
                    $tcHasWhatsapp = !empty($lead['whatsapp']) || !empty($lead['phone']);
                    ?>
                    <tr>
                        <?php if (Auth::can('leads.edit')): ?>
                        <td><input type="checkbox" class="form-check-input tc-row-check" value="<?= (int) $lead['id'] ?>"></td>
                        <?php endif; ?>
                        <td>
                            <?php if (!empty($lead['lead_code'])): ?>
                                <span class="badge tc-badge-code"><?= e($lead['lead_code']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= e(url('leads/' . $lead['id'])) ?>" class="text-decoration-none fw-semibold text-reset">
                                <?= e($lead['name'] ?: 'Sem nome') ?>
                            </a>
                        </td>
                        <td><?= e(format_phone($lead['whatsapp'] ?: $lead['phone'])) ?></td>
                        <td><?= e($lead['city'] ? $lead['city'] . '/' . $lead['state'] : '-') ?></td>
                        <td><?= e(source_label($lead['source'])) ?></td>
                        <td><?= e(interest_label($lead['interest'])) ?></td>
                        <td><span class="badge bg-<?= e(status_color($lead['status'])) ?>"><?= e(status_label($lead['status'])) ?></span></td>
                        <td><span class="badge bg-<?= e(score_badge_class($lead['lead_score'] ?? 0)) ?>"><?= (int) ($lead['lead_score'] ?? 0) ?></span></td>
                        <td>
                            <?php if (!empty($lead['temperature'])): ?>
                                <span class="badge <?= e(temperature_badge_class($lead['temperature'])) ?>"><?= e(temperature_label($lead['temperature'])) ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="<?= $tcStale ? 'tc-text-stale' : '' ?>"><?= e(days_since_contact_label($lead['last_contact_at'] ?? null)) ?></td>
                        <td><?= e($lead['assigned_name'] ?: '-') ?></td>
                        <td><?= e(format_date($lead['created_at'])) ?></td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-success tc-quick-note-btn" title="Nota rápida"
                                    data-lead-id="<?= (int) $lead['id'] ?>"
                                    data-url="<?= e(url('leads/' . $lead['id'] . '/nota-rapida')) ?>"
                                    data-csrf-token="<?= e(Csrf::token()) ?>">
                                <i class="fa-solid fa-note-sticky"></i>
                            </button>
                            <a href="<?= e(url('leads/' . $lead['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="Ver">
                                <i class="fa-solid fa-eye"></i>
                            </a>
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Mais ações">
                                    <i class="fa-solid fa-ellipsis"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                    <?php if ($tcHasWhatsapp): ?>
                                    <li><a class="dropdown-item" href="<?= e(url('leads/' . $lead['id'] . '?open=whatsapp')) ?>"><i class="fa-brands fa-whatsapp text-success me-2"></i>Enviar WhatsApp</a></li>
                                    <?php endif; ?>
                                    <?php if ($tcHasValidEmail): ?>
                                    <li><a class="dropdown-item" href="<?= e('mailto:' . $tcEmail) ?>"><i class="fa-solid fa-envelope text-primary me-2"></i>Enviar e-mail</a></li>
                                    <?php endif; ?>
                                    <?php if ($tcCanTransfer): ?>
                                    <li><button type="button" class="dropdown-item tc-lead-transfer-btn"
                                                data-lead-id="<?= (int) $lead['id'] ?>"
                                                data-lead-name="<?= e($lead['name'] ?: 'Sem nome') ?>"
                                                data-current-assigned-to="<?= (int) ($lead['assigned_to'] ?? 0) ?>"
                                                data-url="<?= e(url('leads/' . $lead['id'] . '/transferir')) ?>"><i class="fa-solid fa-right-left text-warning me-2"></i>Transferir responsável</button></li>
                                    <?php endif; ?>
                                    <li><a class="dropdown-item" href="<?= e(url('leads/' . $lead['id'] . '/edit')) ?>"><i class="fa-solid fa-pen me-2"></i>Editar lead</a></li>
                                    <?php if (Auth::can('leads.delete')): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" action="<?= e(url('leads/' . $lead['id'] . '/delete')) ?>" class="tc-delete-form" data-confirm-text="O lead será excluído permanentemente.">
                                            <?= Csrf::field() ?>
                                            <button type="submit" class="dropdown-item text-danger"><i class="fa-solid fa-trash me-2"></i>Excluir lead</button>
                                        </form>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($tcCanTransfer): ?>
<!-- Modal único para transferência de responsável a partir da coluna de ações. -->
<div class="modal fade" id="tcLeadTransferModal" tabindex="-1" aria-labelledby="tcLeadTransferTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="tcLeadTransferForm" data-csrf-token="<?= e(Csrf::token()) ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="tcLeadTransferTitle"><i class="fa-solid fa-right-left me-1"></i> Transferir lead</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Novo responsável para <strong id="tcLeadTransferName">este lead</strong>:</p>
                    <label for="tcLeadTransferAssigned" class="form-label">Responsável</label>
                    <select id="tcLeadTransferAssigned" class="form-select" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-tc-primary"><i class="fa-solid fa-check me-1"></i> Transferir</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination justify-content-center">
        <?php
        $baseQuery = array_filter($filters, fn($v) => $v !== '' && $v !== null);
        $baseQuery['sort'] = $sortBy;
        $baseQuery['dir'] = $sortDir;
        if ($scope !== '') { $baseQuery['view'] = $scope; }
        ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): $q = array_merge($baseQuery, ['page' => $p]); ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= e(url('leads?' . http_build_query($q))) ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>
