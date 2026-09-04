<?php
/**
 * app/views/users/index.php
 * Listagem de usuários (CRUD, restrito a admin).
 */
$roleLabels = ['admin' => 'Administrador', 'supervisor' => 'Supervisor', 'consultor' => 'Consultor'];
$commercialFunctionLabels = ['sdr' => 'SDR', 'vendedor' => 'Vendedor', 'supervisor' => 'Supervisor'];
?>

<div class="tc-card mb-3">
    <div class="tc-card-body">
        <form method="GET" action="<?= e(url('usuarios')) ?>" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Buscar</label>
                <input type="text" name="search" class="form-control" placeholder="Nome ou e-mail..." value="<?= e($filters['search']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Papel</label>
                <select name="role" class="form-select">
                    <option value="">Todos</option>
                    <option value="admin" <?= $filters['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                    <option value="supervisor" <?= $filters['role'] === 'supervisor' ? 'selected' : '' ?>>Supervisor</option>
                    <option value="consultor" <?= $filters['role'] === 'consultor' ? 'selected' : '' ?>>Consultor</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Departamento</label>
                <select name="department_id" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= $filters['department_id'] === (string) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">Status</label>
                <select name="active" class="form-select">
                    <option value="">Todos</option>
                    <option value="1" <?= $filters['active'] === '1' ? 'selected' : '' ?>>Ativos</option>
                    <option value="0" <?= $filters['active'] === '0' ? 'selected' : '' ?>>Inativos</option>
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-tc-primary w-100"><i class="fa-solid fa-filter"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted" style="font-size:0.85rem;"><?= (int) $total ?> usuário(s) encontrado(s)</span>
    <a href="<?= e(url('usuarios/create')) ?>" class="btn btn-tc-primary btn-sm">
        <i class="fa-solid fa-user-plus me-1"></i> Novo Usuário
    </a>
</div>

<div class="tc-table-card">
    <div class="table-responsive">
        <table class="table tc-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>Papel</th>
                    <th>Função comercial</th>
                    <th>Departamento</th>
                    <th>Status</th>
                    <th>Criado em</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Nenhum usuário encontrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($u['name']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><span class="badge bg-secondary"><?= e($roleLabels[$u['role']] ?? $u['role']) ?></span></td>
                        <td><span class="badge bg-info text-dark"><?= e($commercialFunctionLabels[$u['commercial_function'] ?? 'vendedor'] ?? 'Vendedor') ?></span></td>
                        <td><?= $u['department_name'] ? e($u['department_name']) : '<span class="text-muted">—</span>' ?></td>
                        <td>
                            <?php if ((int) $u['active'] === 1): ?>
                                <span class="badge bg-success">Ativo</span>
                            <?php else: ?>
                                <span class="badge bg-danger">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(format_date($u['created_at'])) ?></td>
                        <td class="text-end">
                            <a href="<?= e(url('usuarios/' . $u['id'] . '/edit')) ?>" class="btn btn-sm btn-outline-secondary" title="Editar">
                                <i class="fa-solid fa-pen"></i>
                            </a>
                            <?php if ((int) $u['id'] !== Auth::id()): ?>
                            <form method="POST" action="<?= e(url('usuarios/' . $u['id'] . '/status')) ?>" class="d-inline">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-<?= (int) $u['active'] === 1 ? 'warning' : 'success' ?>" title="<?= (int) $u['active'] === 1 ? 'Desativar acesso' : 'Reativar acesso' ?>">
                                    <i class="fa-solid <?= (int) $u['active'] === 1 ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                                </button>
                            </form>
                            <form method="POST" action="<?= e(url('usuarios/' . $u['id'] . '/delete')) ?>" class="d-inline tc-delete-form" data-confirm-text="O usuário será excluído permanentemente. Prefira desativar (botão ao lado) se quiser apenas bloquear o acesso.">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?= render_pagination($page, $totalPages, url('usuarios'), $filters) ?>
