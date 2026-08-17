<?php
/**
 * app/views/import/mapping.php
 * Tela 2 do módulo de importação (Fase 4): mapeamento de colunas do CSV
 * para os campos do lead, com prévia das primeiras linhas do arquivo.
 */
?>

<form method="POST" action="<?= e(url('importar/processar')) ?>" id="tcImportMappingForm" class="tc-import-loading-form" data-loading-text="Importando os leads, isso pode levar alguns instantes...">
    <?= Csrf::field() ?>
    <input type="hidden" name="stored_filename" value="<?= e($storedFilename) ?>">
    <input type="hidden" name="original_filename" value="<?= e($originalFilename) ?>">
    <input type="hidden" name="delimiter" value="<?= e($delimiterToken) ?>">

    <div class="tc-card mb-3">
        <div class="tc-card-header d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-table-columns me-1"></i> Mapeamento de colunas</span>
            <span class="text-muted" style="font-size:0.8rem;">
                Arquivo: <strong><?= e($originalFilename) ?></strong> ·
                <?= (int) $totalRows ?> linha(s) de dados
                <?php if ($totalRows > $maxRows): ?>
                    <span class="text-danger">(apenas as primeiras <?= (int) $maxRows ?> serão processadas)</span>
                <?php endif; ?>
            </span>
        </div>
        <div class="tc-card-body">
            <p class="text-muted" style="font-size:0.82rem;">
                Para cada coluna do arquivo, escolha a qual campo do lead ela corresponde, ou selecione
                "Ignorar coluna". O sistema já sugeriu um mapeamento automático com base no nome do cabeçalho —
                revise antes de continuar.
            </p>

            <div class="table-responsive tc-mapping-table">
                <table class="table tc-table">
                    <thead>
                        <tr>
                            <th style="min-width:220px;">Coluna do arquivo</th>
                            <th style="min-width:220px;">Mapear para</th>
                            <th>Prévia (linha 1)</th>
                            <th>Prévia (linha 2)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($header as $index => $label): ?>
                            <tr>
                                <td><strong><?= e((string) $label) ?></strong></td>
                                <td>
                                    <select name="mapping[<?= (int) $index ?>]" class="form-select form-select-sm">
                                        <option value="ignorar" <?= ($guessedMapping[$index] ?? 'ignorar') === 'ignorar' ? 'selected' : '' ?>>Ignorar coluna</option>
                                        <?php foreach ($fieldOptions as $field => $fieldLabel): ?>
                                            <option value="<?= e($field) ?>" <?= ($guessedMapping[$index] ?? '') === $field ? 'selected' : '' ?>><?= e($fieldLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="text-muted" style="font-size:0.8rem;"><?= e((string) ($previewRows[0][$index] ?? '')) ?></td>
                                <td class="text-muted" style="font-size:0.8rem;"><?= e((string) ($previewRows[1][$index] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($previewRows)): ?>
                <div class="tc-form-section-title">Prévia das primeiras linhas do arquivo</div>
                <div class="table-responsive">
                    <table class="table tc-table table-sm">
                        <thead>
                            <tr>
                                <?php foreach ($header as $label): ?>
                                    <th><?= e((string) $label) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($previewRows as $row): ?>
                                <tr>
                                    <?php foreach ($header as $index => $label): ?>
                                        <td style="font-size:0.8rem;"><?= e((string) ($row[$index] ?? '')) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="<?= e(url('importar')) ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Cancelar</a>
        <button type="submit" class="btn btn-tc-primary px-4"><i class="fa-solid fa-file-import me-1"></i> Processar importação</button>
    </div>
</form>
