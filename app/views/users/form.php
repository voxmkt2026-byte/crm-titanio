<?php
/**
 * app/views/users/form.php
 * Formulário de criação/edição de usuário (restrito a admin).
 */
$isEdit = !empty($user);
?>

<form method="POST" action="<?= e($formAction) ?>">
    <?= Csrf::field() ?>

    <div class="tc-card mb-3">
        <div class="tc-card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nome completo</label>
                    <input type="text" name="name" class="form-control" value="<?= e($user['name'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">E-mail</label>
                    <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Papel</label>
                    <select name="role" class="form-select">
                        <?php foreach (['admin' => 'Administrador', 'supervisor' => 'Supervisor', 'consultor' => 'Consultor'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= ($user['role'] ?? 'consultor') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Função comercial</label>
                    <?php $commercialFunction = $user['commercial_function'] ?? (($user['role'] ?? '') === 'supervisor' ? 'supervisor' : 'vendedor'); ?>
                    <select name="commercial_function" class="form-select">
                        <option value="sdr" <?= $commercialFunction === 'sdr' ? 'selected' : '' ?>>SDR · leads trabalhados</option>
                        <option value="vendedor" <?= $commercialFunction === 'vendedor' ? 'selected' : '' ?>>Vendedor · fechamentos e valor</option>
                        <option value="supervisor" <?= $commercialFunction === 'supervisor' ? 'selected' : '' ?>>Supervisor · vendas da equipe</option>
                    </select>
                    <div class="form-text">Não altera permissões; apenas define os indicadores de meta.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Senha <?= $isEdit ? '(deixe em branco para manter a atual)' : '' ?></label>
                    <input type="password" name="password" class="form-control" minlength="6" placeholder="Mínimo 6 caracteres" <?= $isEdit ? '' : 'required' ?>>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="active" value="1" <?= ($isEdit ? !empty($user['active']) : true) ? 'checked' : '' ?>>
                        <label class="form-check-label">Ativo</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Departamento <span class="text-muted" style="font-size:0.75rem;">(chat interno)</span></label>
                    <select name="department_id" class="form-select">
                        <option value="">Sem departamento</option>
                        <?php foreach (($departments ?? []) as $dept): ?>
                            <option value="<?= (int) $dept['id'] ?>" <?= (int) ($user['department_id'] ?? 0) === (int) $dept['id'] ? 'selected' : '' ?>>
                                <?= e($dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Define a sala de departamento em que o usuário entra automaticamente no Chat.</div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($permissionGroups)): ?>
    <div class="tc-card mb-3">
        <div class="tc-card-header d-flex align-items-center justify-content-between" role="button" data-bs-toggle="collapse" data-bs-target="#tcPermOverrides" style="cursor:pointer;">
            <span><i class="fa-solid fa-user-shield me-1"></i> Permissões avançadas (por pessoa)</span>
            <i class="fa-solid fa-chevron-down"></i>
        </div>
        <div class="collapse" id="tcPermOverrides">
            <div class="tc-card-body">
                <div class="tc-insight-card mb-3">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>Por padrão, o acesso segue o <strong>papel</strong> escolhido acima. Use isto só para exceções: liberar uma permissão extra para esta pessoa específica, ou bloquear uma que o papel dela normalmente libera.</span>
                </div>
                <?php foreach ($permissionGroups as $prefix => $permissions): ?>
                    <div class="mb-3">
                        <div class="fw-semibold text-uppercase mb-1" style="font-size:0.72rem; color:var(--tc-text-muted); letter-spacing:0.05em;"><?= e($prefix) ?></div>
                        <?php foreach ($permissions as $permission): ?>
                            <?php
                                $slug = $permission['slug'];
                                $state = in_array($slug, $overrides['allow'] ?? [], true) ? 'allow'
                                    : (in_array($slug, $overrides['deny'] ?? [], true) ? 'deny' : 'default');
                            ?>
                            <div class="row g-2 align-items-center mb-1">
                                <div class="col-md-7" style="font-size:0.85rem;"><?= e($permission['label']) ?> <span class="text-muted" style="font-size:0.7rem;">(<?= e($slug) ?>)</span></div>
                                <div class="col-md-5">
                                    <select name="perm_state[<?= e($slug) ?>]" class="form-select form-select-sm">
                                        <option value="default" <?= $state === 'default' ? 'selected' : '' ?>>Padrão do papel</option>
                                        <option value="allow" <?= $state === 'allow' ? 'selected' : '' ?>>Permitir sempre</option>
                                        <option value="deny" <?= $state === 'deny' ? 'selected' : '' ?>>Negar sempre</option>
                                    </select>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between">
        <a href="<?= e(url('usuarios')) ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Cancelar</a>
        <button type="submit" class="btn btn-tc-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i> Salvar Usuário</button>
    </div>
</form>
