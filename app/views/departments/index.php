<?php
/**
 * app/views/departments/index.php
 * Gestão de departamentos (catálogo do Chat interno / ficha do usuário).
 * Sem exclusão permanente — ver DepartmentController para o motivo (FK
 * ON DELETE CASCADE em chat_rooms apagaria o histórico de mensagens).
 */
?>

<?php if (empty($departments)): ?>
    <div class="alert alert-warning">
        Nenhum departamento encontrado. Se esta é a primeira vez acessando esta tela, rode <code>database/sql/migration_chat.sql</code> no banco de dados.
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="tc-card h-100">
            <div class="tc-card-header">Novo departamento</div>
            <div class="tc-card-body">
                <form method="POST" action="<?= e(url('departamentos/store')) ?>">
                    <?= Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label">Nome</label>
                        <input type="text" name="name" class="form-control" placeholder="Ex: Cobrança" required minlength="2">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Cor</label>
                            <input type="color" name="color" class="form-control form-control-color w-100" value="#3b82f6">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Ícone (FontAwesome)</label>
                            <input type="text" name="icon" class="form-control" placeholder="fa-solid fa-comments" value="fa-solid fa-comments">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-tc-primary w-100"><i class="fa-solid fa-plus me-1"></i> Criar departamento</button>
                </form>
                <div class="tc-insight-card mt-3">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>Ao criar, uma sala de chat interno correspondente é criada automaticamente. Colaboradores entram nela quando o departamento é escolhido na ficha do usuário (<a href="<?= e(url('usuarios')) ?>">Usuários</a>).</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="tc-table-card h-100">
            <div class="table-responsive">
                <table class="table tc-table mb-0">
                    <thead>
                        <tr>
                            <th>Departamento</th>
                            <th>Cor</th>
                            <th>Ícone</th>
                            <th>Usuários</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($departments as $dept): ?>
                            <tr>
                                <form method="POST" action="<?= e(url('departamentos/' . $dept['id'] . '/update')) ?>" class="d-none" id="deptForm<?= (int) $dept['id'] ?>">
                                    <?= Csrf::field() ?>
                                </form>
                                <td>
                                    <input type="text" form="deptForm<?= (int) $dept['id'] ?>" name="name" class="form-control form-control-sm" value="<?= e($dept['name']) ?>" style="max-width:180px;" <?= $dept['slug'] === 'geral' ? 'title="Departamento padrão — o nome pode ser alterado, mas o slug interno permanece \'geral\'."' : '' ?>>
                                </td>
                                <td><input type="color" form="deptForm<?= (int) $dept['id'] ?>" name="color" class="form-control form-control-color" value="<?= e($dept['color']) ?>"></td>
                                <td><input type="text" form="deptForm<?= (int) $dept['id'] ?>" name="icon" class="form-control form-control-sm" value="<?= e($dept['icon']) ?>" style="max-width:170px;"></td>
                                <td>
                                    <?php $deptMembers = $members[(int) $dept['id']] ?? []; ?>
                                    <?php if ((int) $dept['user_count'] > 0): ?>
                                        <button type="button" class="badge bg-secondary border-0" data-bs-toggle="collapse" data-bs-target="#deptMembers<?= (int) $dept['id'] ?>" title="Ver colaboradores">
                                            <?= (int) $dept['user_count'] ?> <i class="fa-solid fa-caret-down ms-1"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ((int) $dept['active'] === 1): ?>
                                        <span class="badge bg-success">Ativo</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <button type="submit" form="deptForm<?= (int) $dept['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Salvar">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                    </button>
                                    <?php if ($dept['slug'] !== 'geral'): ?>
                                    <form method="POST" action="<?= e(url('departamentos/' . $dept['id'] . '/status')) ?>" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-<?= (int) $dept['active'] === 1 ? 'warning' : 'success' ?>" title="<?= (int) $dept['active'] === 1 ? 'Desativar' : 'Reativar' ?>">
                                            <i class="fa-solid <?= (int) $dept['active'] === 1 ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if (!empty($deptMembers)): ?>
                            <tr class="collapse" id="deptMembers<?= (int) $dept['id'] ?>">
                                <td colspan="6" class="bg-body-tertiary">
                                    <div class="d-flex flex-wrap gap-1 py-1">
                                        <?php foreach ($deptMembers as $m): ?>
                                            <span class="badge <?= (int) $m['active'] === 1 ? 'bg-light text-dark border' : 'bg-light text-muted border' ?>"><?= e($m['name']) ?><?= (int) $m['active'] === 1 ? '' : ' (inativo)' ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
