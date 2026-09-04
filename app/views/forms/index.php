<?php
/**
 * app/views/forms/index.php
 * Lista de formulários de captação (Construtor de Formulários).
 */
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted" style="font-size:0.85rem;"><?= count($forms) ?> formulário(s) cadastrado(s)</span>
    <a href="<?= e(url('formularios/novo')) ?>" class="btn btn-tc-primary btn-sm">
        <i class="fa-solid fa-plus me-1"></i> Novo Formulário
    </a>
</div>

<?php if (empty($forms)): ?>
    <div class="tc-card">
        <div class="tc-card-body text-center py-5">
            <i class="fa-solid fa-file-pen text-muted" style="font-size:2rem;"></i>
            <p class="text-muted mt-3 mb-3">Nenhum formulário criado ainda. Crie um para gerar um link público de captação.</p>
            <a href="<?= e(url('formularios/novo')) ?>" class="btn btn-tc-primary"><i class="fa-solid fa-plus me-1"></i> Criar o primeiro formulário</a>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($forms as $f): ?>
            <?php $publicUrl = url('f/' . $f['slug']); ?>
            <div class="col-lg-6">
                <div class="tc-card h-100">
                    <div class="tc-card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="mb-1"><?= e($f['name']) ?></h6>
                                <?php if ($f['description']): ?><p class="text-muted mb-2" style="font-size:0.8rem;"><?= e($f['description']) ?></p><?php endif; ?>
                            </div>
                            <span class="badge bg-<?= (int) $f['active'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $f['active'] === 1 ? 'Ativo' : 'Inativo' ?></span>
                        </div>

                        <div class="input-group input-group-sm mb-2">
                            <input type="text" class="form-control tc-form-link-input" readonly value="<?= e($publicUrl) ?>">
                            <button type="button" class="btn btn-outline-secondary tc-copy-link-btn" data-copy="<?= e($publicUrl) ?>" title="Copiar link">
                                <i class="fa-solid fa-copy"></i>
                            </button>
                            <a href="<?= e($publicUrl) ?>" target="_blank" class="btn btn-outline-secondary" title="Abrir formulário">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                            </a>
                        </div>

                        <div class="d-flex flex-wrap gap-3 text-muted mb-3" style="font-size:0.78rem;">
                            <span><i class="fa-solid fa-inbox me-1"></i><?= (int) $f['submissions_count'] ?> lead(s) recebido(s)</span>
                            <?php if ($f['assignee_name']): ?><span><i class="fa-solid fa-user me-1"></i>Responsável padrão: <?= e($f['assignee_name']) ?></span><?php endif; ?>
                            <span><i class="fa-solid fa-calendar me-1"></i><?= e(format_date($f['created_at'])) ?></span>
                        </div>

                        <div class="d-flex gap-2">
                            <a href="<?= e(url('formularios/' . $f['id'] . '/editar')) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-pen me-1"></i> Editar</a>
                            <a href="<?= e(url('formularios/' . $f['id'] . '/editar') . '#integracoes') ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-plug-circle-bolt me-1"></i> Integrar</a>
                            <form method="POST" action="<?= e(url('formularios/' . $f['id'] . '/status')) ?>" class="d-inline">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-<?= (int) $f['active'] === 1 ? 'warning' : 'success' ?>">
                                    <i class="fa-solid <?= (int) $f['active'] === 1 ? 'fa-pause' : 'fa-play' ?> me-1"></i> <?= (int) $f['active'] === 1 ? 'Pausar' : 'Ativar' ?>
                                </button>
                            </form>
                            <form method="POST" action="<?= e(url('formularios/' . $f['id'] . '/excluir')) ?>" class="d-inline ms-auto tc-delete-form" data-confirm-text="O formulário será excluído. O link público deixará de funcionar. Os leads já recebidos NÃO são apagados.">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.tc-copy-link-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                navigator.clipboard.writeText(btn.dataset.copy).then(function () {
                    if (typeof Toastify !== 'undefined') {
                        Toastify({ text: 'Link copiado!', duration: 1800, gravity: 'top', position: 'right', style: { background: '#16a34a', borderRadius: '0.5rem' } }).showToast();
                    }
                });
            });
        });
    });
</script>
