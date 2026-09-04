<?php
/**
 * app/views/import/report.php
 * Relatório de uma importação de leads via CSV (Fase 4): resumo + erros/avisos.
 */

$statusLabels = [
    'processando'          => 'Processando',
    'concluido'             => 'Concluído',
    'concluido_com_erros'   => 'Concluído com avisos',
    'falhou'                => 'Falhou',
];
$statusColors = [
    'processando'          => 'secondary',
    'concluido'             => 'success',
    'concluido_com_erros'   => 'warning',
    'falhou'                => 'danger',
];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="badge bg-<?= e($statusColors[$import['status']] ?? 'secondary') ?>">
        <?= e($statusLabels[$import['status']] ?? $import['status']) ?>
    </span>
    <div class="d-flex gap-2">
        <?php if (!empty($errors)): ?>
            <a href="<?= e(url('importar/relatorio/' . $import['id'] . '/exportar-erros')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-file-arrow-down me-1"></i> Exportar erros (CSV)
            </a>
        <?php endif; ?>
        <a href="<?= e(url('importar')) ?>" class="btn btn-tc-primary btn-sm">
            <i class="fa-solid fa-file-import me-1"></i> Nova importação
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3 col-6">
        <div class="tc-import-summary-card">
            <div class="tc-import-summary-value"><?= (int) $import['total_rows'] ?></div>
            <div class="tc-import-summary-label">Linhas processadas</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="tc-import-summary-card">
            <div class="tc-import-summary-value text-success"><?= (int) $import['created_count'] ?></div>
            <div class="tc-import-summary-label">Leads criados</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="tc-import-summary-card">
            <div class="tc-import-summary-value text-info"><?= (int) $import['updated_count'] ?></div>
            <div class="tc-import-summary-label">Leads atualizados</div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="tc-import-summary-card">
            <div class="tc-import-summary-value text-danger"><?= (int) $import['error_count'] ?></div>
            <div class="tc-import-summary-label">Erros / avisos</div>
        </div>
    </div>
</div>

<div class="tc-card mb-3">
    <div class="tc-card-body">
        <div class="row g-3">
            <div class="col-md-4"><strong>Arquivo:</strong><br><?= e($import['filename'] ?: '-') ?></div>
            <div class="col-md-4"><strong>Importado por:</strong><br><?= e($import['user_name'] ?? 'Sistema') ?></div>
            <div class="col-md-4"><strong>Data:</strong><br><?= e(format_date($import['created_at'], true)) ?></div>
        </div>
    </div>
</div>

<div class="tc-table-card">
    <div class="tc-card-header">Erros e avisos linha a linha</div>
    <div class="table-responsive">
        <table class="table tc-table">
            <thead>
                <tr>
                    <th>Linha</th>
                    <th>Mensagem</th>
                    <th>Dados da linha</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($errors)): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">Nenhum erro ou aviso registrado nesta importação.</td></tr>
                <?php endif; ?>
                <?php foreach ($errors as $err): ?>
                    <tr>
                        <td><?= (int) $err['row_num'] ?></td>
                        <td><?= e($err['error_message']) ?></td>
                        <td class="text-muted" style="font-size:0.78rem; max-width:360px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= e((string) $err['raw_data']) ?>">
                            <?= e((string) $err['raw_data']) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
