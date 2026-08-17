<?php
/**
 * app/views/import/index.php
 * Tela 1 do módulo de importação (Fase 4): upload do arquivo CSV.
 */
?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="tc-card mb-3">
            <div class="tc-card-header">
                <i class="fa-solid fa-file-csv me-1"></i> Importar leads via CSV
            </div>
            <div class="tc-card-body">
                <form method="POST" action="<?= e(url('importar/preview')) ?>" enctype="multipart/form-data" class="tc-import-loading-form" data-loading-text="Lendo o arquivo e preparando o mapeamento de colunas...">
                    <?= Csrf::field() ?>

                    <div class="tc-import-dropzone mb-3">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p class="mb-2">Selecione um arquivo <strong>.csv</strong> com os leads a importar</p>
                        <input type="file" name="csv_file" accept=".csv,text/csv" class="form-control" required style="max-width: 360px; margin: 0 auto;">
                    </div>

                    <p class="text-muted" style="font-size:0.82rem;">
                        A primeira linha do arquivo deve conter o cabeçalho das colunas (ex: Nome, Telefone, E-mail...).
                        Na próxima tela você poderá mapear cada coluna do arquivo para um campo do lead e revisar
                        uma prévia das primeiras linhas antes de confirmar.
                    </p>

                    <button type="submit" class="btn btn-tc-primary">
                        <i class="fa-solid fa-arrow-right me-1"></i> Continuar para o mapeamento
                    </button>
                </form>
            </div>
        </div>

        <div class="tc-insight-card">
            <i class="fa-solid fa-circle-info"></i>
            <div>
                Leads cuja combinação de WhatsApp/telefone/CPF/e-mail já existir no sistema serão
                <strong>atualizados</strong> (campos vazios no CSV mantêm o valor atual). Os demais serão
                <strong>criados</strong> com origem "Importação CSV" e um código único (LEAD-AAAAMMDD-NNNN).
                Suporte a .xlsx binário nativo fica para uma fase futura — exporte sua planilha como CSV
                pelo Excel/Google Sheets.
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="tc-card">
            <div class="tc-card-header">Importações recentes</div>
            <div class="tc-card-body p-0">
                <?php if (empty($recentImports)): ?>
                    <p class="text-muted text-center py-4 mb-0" style="font-size:0.85rem;">Nenhuma importação realizada ainda.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table tc-table mb-0">
                            <thead>
                                <tr>
                                    <th>Arquivo</th>
                                    <th>Resultado</th>
                                    <th>Quando</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentImports as $imp): ?>
                                    <tr>
                                        <td style="max-width:160px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= e($imp['filename']) ?>"><?= e($imp['filename'] ?: '-') ?></td>
                                        <td style="font-size:0.78rem;">
                                            <span class="text-success"><?= (int) $imp['created_count'] ?> criados</span> ·
                                            <span class="text-info"><?= (int) $imp['updated_count'] ?> atualiz.</span>
                                            <?php if ((int) $imp['error_count'] > 0): ?>
                                                · <span class="text-danger"><?= (int) $imp['error_count'] ?> erros</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:0.78rem;"><?= e(time_ago($imp['created_at'])) ?></td>
                                        <td class="text-end">
                                            <a href="<?= e(url('importar/relatorio/' . $imp['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="Ver relatório">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
