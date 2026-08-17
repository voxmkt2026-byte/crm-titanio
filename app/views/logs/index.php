<?php
/**
 * app/views/logs/index.php
 * Listagem paginada de logs de auditoria (activity_log).
 */
?>

<div class="tc-card mb-3">
    <div class="tc-card-body">
        <form method="GET" action="<?= e(url('logs')) ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Usuário</label>
                <select name="user_id" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= (string) $filters['user_id'] === (string) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Ação (busca parcial)</label>
                <input type="text" name="action" class="form-control" value="<?= e($filters['action']) ?>" placeholder="Ex: lead_criado">
            </div>
            <div class="col-md-2">
                <label class="form-label">De</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($filters['date_from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Até</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($filters['date_to']) ?>">
            </div>
            <div class="col-md-2 d-grid">
                <button type="submit" class="btn btn-tc-primary"><i class="fa-solid fa-filter me-1"></i> Filtrar</button>
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted" style="font-size:0.85rem;"><?= (int) $total ?> registro(s) encontrado(s)</span>
</div>

<div class="tc-table-card">
    <div class="table-responsive">
        <table class="table tc-table">
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>Usuário</th>
                    <th>Ação</th>
                    <th>Detalhes</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= e(format_date($log['created_at'], true)) ?></td>
                        <td><?= e($log['user_name'] ?? 'Sistema') ?></td>
                        <td><span class="badge bg-secondary"><?= e($log['action'] ?: '-') ?></span></td>
                        <td><?= e($log['details'] ?: '-') ?></td>
                        <td class="text-muted"><?= e($log['ip_address'] ?: '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination justify-content-center">
        <?php
        $baseQuery = array_filter($filters, fn($v) => $v !== '' && $v !== null);
        ?>
        <?php for ($p = 1; $p <= $totalPages; $p++): $q = array_merge($baseQuery, ['page' => $p]); ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= e(url('logs?' . http_build_query($q))) ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>
