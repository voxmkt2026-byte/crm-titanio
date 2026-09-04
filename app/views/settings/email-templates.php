<?php
/** Biblioteca de e-mails que podem ser revisados e enviados pelo atendimento. */
$categoryLabels = [
    'geral' => 'Geral', 'primeiro_contato' => 'Primeiro contato', 'follow_up' => 'Follow-up',
    'documentacao' => 'Documentação', 'proposta' => 'Proposta', 'pos_venda' => 'Pós-venda',
];
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <p class="text-muted mb-0 small"><i class="fa-solid fa-circle-info me-1"></i>Estes modelos aceleram a preparação do e-mail. O atendente sempre revisa e confirma o envio; nada é disparado automaticamente.</p>
    <a href="<?= e(url('configuracoes/whatsapp-templates')) ?>" class="btn btn-outline-success btn-sm"><i class="fa-brands fa-whatsapp me-1"></i>Templates de WhatsApp</a>
</div>
<div class="alert alert-light border small mb-3">
    <strong>Variáveis disponíveis:</strong> <code>{{nome}}</code>, <code>{{interesse}}</code> e <code>{{responsavel}}</code>.
    Elas são substituídas pelos dados do lead no atendimento antes da revisão.
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="tc-card h-100"><div class="tc-card-header">Novo template de e-mail</div><div class="tc-card-body">
            <form method="POST" action="<?= e(url('configuracoes/email-templates/store')) ?>">
                <?= Csrf::field() ?>
                <div class="mb-2"><label class="form-label">Nome</label><input name="name" class="form-control" placeholder="Ex.: Follow-up consultivo" required></div>
                <div class="mb-2"><label class="form-label">Objetivo</label><select name="category" class="form-select"><?php foreach ($categories as $category): ?><option value="<?= e($category) ?>"><?= e($categoryLabels[$category]) ?></option><?php endforeach; ?></select></div>
                <div class="mb-2"><label class="form-label">Assunto</label><input name="subject" class="form-control" placeholder="Ex.: Podemos avançar sua proposta?" required></div>
                <div class="mb-3"><label class="form-label">Mensagem</label><textarea name="content" class="form-control" rows="7" placeholder="Olá {{nome}},\n\n..." required></textarea></div>
                <button class="btn btn-tc-primary w-100"><i class="fa-solid fa-plus me-1"></i>Adicionar template</button>
            </form>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="tc-table-card h-100"><div class="table-responsive"><table class="table tc-table mb-0 align-middle">
            <thead><tr><th>Modelo</th><th>Assunto e conteúdo</th><th>Ativo</th><th class="text-end">Ações</th></tr></thead><tbody>
            <?php if (empty($templates)): ?><tr><td colspan="4" class="text-center text-muted py-4">Nenhum template cadastrado.</td></tr><?php endif; ?>
            <?php foreach ($templates as $template): $formId = 'emailTemplateForm' . (int) $template['id']; ?>
                <tr>
                    <form method="POST" action="<?= e(url('configuracoes/email-templates/' . $template['id'] . '/update')) ?>" id="<?= e($formId) ?>" class="d-none"><?= Csrf::field() ?></form>
                    <td style="min-width:165px"><input form="<?= e($formId) ?>" name="name" class="form-control form-control-sm mb-1" value="<?= e($template['name']) ?>"><select form="<?= e($formId) ?>" name="category" class="form-select form-select-sm"><?php foreach ($categories as $category): ?><option value="<?= e($category) ?>" <?= $template['category'] === $category ? 'selected' : '' ?>><?= e($categoryLabels[$category]) ?></option><?php endforeach; ?></select></td>
                    <td style="min-width:300px"><input form="<?= e($formId) ?>" name="subject" class="form-control form-control-sm mb-1" value="<?= e($template['subject']) ?>"><textarea form="<?= e($formId) ?>" name="content" class="form-control form-control-sm" rows="3"><?= e($template['content']) ?></textarea></td>
                    <td><div class="form-check form-switch"><input form="<?= e($formId) ?>" class="form-check-input" type="checkbox" name="active" value="1" <?= (int) $template['active'] === 1 ? 'checked' : '' ?>></div></td>
                    <td class="text-end text-nowrap"><button form="<?= e($formId) ?>" class="btn btn-sm btn-outline-secondary" title="Salvar"><i class="fa-solid fa-floppy-disk"></i></button><form method="POST" action="<?= e(url('configuracoes/email-templates/' . $template['id'] . '/delete')) ?>" class="d-inline tc-delete-form" data-confirm-text="O template será excluído permanentemente."><?= Csrf::field() ?><button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="fa-solid fa-trash"></i></button></form></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div></div>
    </div>
</div>
