<?php
/**
 * app/views/settings/whatsapp-templates.php
 * CRUD de templates de mensagem para WhatsApp (Fase 7 - auditoria UX).
 * Placeholders aceitos: {{nome}}, {{interesse}}, {{responsavel}}.
 */
$categoryLabels = [
    'geral' => 'Geral', 'primeiro_contato' => 'Primeiro contato', 'follow_up' => 'Follow-up',
    'documentacao' => 'Documentação', 'proposta' => 'Proposta', 'pos_venda' => 'Pós-venda',
];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <p class="text-muted mb-0 small"><i class="fa-solid fa-circle-info me-1"></i>Use <code>{{nome}}</code>, <code>{{interesse}}</code> e <code>{{responsavel}}</code>. Os dados são ajustados ao lead antes da revisão e do envio manual.</p>
    <a href="<?= e(url('configuracoes/email-templates')) ?>" class="btn btn-outline-primary btn-sm"><i class="fa-solid fa-envelope me-1"></i>Templates de E-mail</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="tc-card h-100">
            <div class="tc-card-header">Novo template</div>
            <div class="tc-card-body">
                <form method="POST" action="<?= e(url('configuracoes/whatsapp-templates/store')) ?>">
                    <?= Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label">Nome do template</label>
                        <input type="text" name="name" class="form-control" placeholder="Ex: Primeiro contato" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Objetivo</label>
                        <select name="category" class="form-select">
                            <?php foreach ($categories as $category): ?><option value="<?= e($category) ?>"><?= e($categoryLabels[$category]) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Mensagem</label>
                        <textarea name="content" class="form-control" rows="4" placeholder="Olá {{nome}}, tudo bem?" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-tc-primary w-100"><i class="fa-solid fa-plus me-1"></i> Adicionar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="tc-table-card h-100">
            <div class="table-responsive">
                <table class="table tc-table mb-0">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Objetivo e mensagem</th>
                            <th>Ativo</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($templates)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">Nenhum template cadastrado.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($templates as $tpl): ?>
                            <tr>
                                <form method="POST" action="<?= e(url('configuracoes/whatsapp-templates/' . $tpl['id'] . '/update')) ?>" class="d-none" id="tplForm<?= (int) $tpl['id'] ?>">
                                    <?= Csrf::field() ?>
                                </form>
                                <td style="min-width:160px;">
                                    <input type="text" form="tplForm<?= (int) $tpl['id'] ?>" name="name" class="form-control form-control-sm" value="<?= e($tpl['name']) ?>">
                                </td>
                                <td style="min-width:240px;">
                                    <select form="tplForm<?= (int) $tpl['id'] ?>" name="category" class="form-select form-select-sm mb-1">
                                        <?php foreach ($categories as $category): ?><option value="<?= e($category) ?>" <?= ($tpl['category'] ?? 'geral') === $category ? 'selected' : '' ?>><?= e($categoryLabels[$category]) ?></option><?php endforeach; ?>
                                    </select>
                                    <textarea form="tplForm<?= (int) $tpl['id'] ?>" name="content" class="form-control form-control-sm" rows="2"><?= e($tpl['content']) ?></textarea>
                                </td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" form="tplForm<?= (int) $tpl['id'] ?>" name="active" value="1" <?= (int) $tpl['active'] === 1 ? 'checked' : '' ?>>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <button type="submit" form="tplForm<?= (int) $tpl['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Salvar">
                                        <i class="fa-solid fa-floppy-disk"></i>
                                    </button>
                                    <form method="POST" action="<?= e(url('configuracoes/whatsapp-templates/' . $tpl['id'] . '/delete')) ?>" class="d-inline tc-delete-form" data-confirm-text="O template será excluído permanentemente.">
                                        <?= Csrf::field() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Excluir">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
