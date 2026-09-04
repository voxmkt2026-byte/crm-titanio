<?php
/**
 * app/views/reports/customize.php
 * Personalização de relatórios (Fase 6): logo, cor primária, informações
 * adicionais de cabeçalho/rodapé e seleção de colunas exportadas. As
 * preferências ficam salvas globalmente na tabela `settings` e valem para
 * as três exportações (CSV, Excel e impressão/PDF).
 */
$s = fn($key, $default = '') => e($settings[$key] ?? $default);
$currentColor = $settings['report_primary_color'] ?? '#1e3a5f';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $currentColor)) {
    $currentColor = '#1e3a5f';
}
$reportLogo = $settings['report_logo'] ?? '';
$fallbackLogo = $settings['company_logo'] ?? '';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <a href="<?= e(url('relatorios')) ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa-solid fa-arrow-left me-1"></i> Voltar para Relatórios
    </a>
</div>

<form method="POST" action="<?= e(url('relatorios/personalizar/atualizar')) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>

    <div class="tc-card mb-3">
        <div class="tc-card-header">Identidade visual do relatório</div>
        <div class="tc-card-body">
            <div class="row g-3 align-items-start">
                <div class="col-md-4">
                    <label class="form-label">Logo do relatório</label>
                    <input type="file" name="report_logo" class="form-control" accept="image/*">
                    <div class="form-text">
                        Opcional: se não enviar um logo específico, o relatório usa o mesmo logo
                        já cadastrado em Configurações.
                    </div>
                    <?php if ($reportLogo): ?>
                        <div class="mt-2 d-flex align-items-center gap-2">
                            <img src="<?= e($reportLogo) ?>" alt="Logo do relatório" style="max-height:40px;">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="remove_report_logo" value="1" id="tcRemoveReportLogo">
                                <label class="form-check-label" for="tcRemoveReportLogo" style="font-size:0.8rem;">Remover e usar o logo padrão</label>
                            </div>
                        </div>
                    <?php elseif ($fallbackLogo): ?>
                        <div class="mt-2">
                            <span class="text-muted" style="font-size:0.78rem;">Logo padrão em uso:</span><br>
                            <img src="<?= e($fallbackLogo) ?>" alt="Logo do sistema" style="max-height:36px;">
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cor primária do relatório</label>
                    <input type="color" name="report_primary_color" class="form-control form-control-color" value="<?= e($currentColor) ?>" title="Escolha a cor do cabeçalho/títulos">
                    <div class="form-text">Aplicada ao cabeçalho e títulos do PDF/impressão.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="tc-card mb-3">
        <div class="tc-card-header">Informações adicionais (cabeçalho/rodapé)</div>
        <div class="tc-card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">CNPJ</label>
                    <input type="text" name="report_cnpj" class="form-control" value="<?= $s('report_cnpj') ?>" placeholder="00.000.000/0001-00">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Telefone</label>
                    <input type="text" name="report_phone" class="form-control" value="<?= $s('report_phone') ?>" placeholder="(00) 0000-0000">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Endereço</label>
                    <input type="text" name="report_address" class="form-control" value="<?= $s('report_address') ?>" placeholder="Rua, número, cidade/UF">
                </div>
                <div class="col-12">
                    <label class="form-label">Texto de rodapé customizado</label>
                    <textarea name="report_footer_text" class="form-control" rows="2" placeholder="Ex: Este relatório é de uso interno da Titanium Consultoria."><?= $s('report_footer_text') ?></textarea>
                </div>
            </div>
            <div class="tc-insight-card mt-3">
                <i class="fa-solid fa-circle-info"></i>
                <span>Quem gerou o relatório e a data/hora de geração são preenchidos automaticamente, sem precisar configurar nada aqui.</span>
            </div>
        </div>
    </div>

    <div class="tc-card mb-3">
        <div class="tc-card-header">Colunas exportadas</div>
        <div class="tc-card-body">
            <p class="text-muted mb-3" style="font-size:0.85rem;">
                Escolha quais colunas de cada lead aparecem no relatório exportado (CSV, Excel e PDF/impressão).
                A ordem abaixo é a ordem em que as colunas aparecem no relatório.
            </p>
            <div class="row g-2">
                <?php foreach ($columnCatalog as $key => $label): ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="columns[]"
                                   id="tcCol_<?= e($key) ?>" value="<?= e($key) ?>"
                                   <?= in_array($key, $selectedColumns, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="tcCol_<?= e($key) ?>"><?= e($label) ?></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="tcColumnsSelectAll">Marcar todas</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="tcColumnsSelectNone">Desmarcar todas</button>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-tc-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i> Salvar Personalização</button>
</form>

<script>
    (function () {
        var selectAll = document.getElementById('tcColumnsSelectAll');
        var selectNone = document.getElementById('tcColumnsSelectNone');
        var checkboxes = document.querySelectorAll('input[name="columns[]"]');

        if (selectAll) {
            selectAll.addEventListener('click', function () {
                checkboxes.forEach(function (cb) { cb.checked = true; });
            });
        }
        if (selectNone) {
            selectNone.addEventListener('click', function () {
                checkboxes.forEach(function (cb) { cb.checked = false; });
            });
        }
    })();
</script>
