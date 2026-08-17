<?php
/**
 * app/views/reports/print.php
 * Página standalone (sem layout) formatada para impressão/"Salvar como PDF"
 * (window.print()), já que o projeto não usa libs externas para gerar PDF
 * binário nativo (sem Composer).
 *
 * Fase 6: cabeçalho/rodapé e colunas agora são personalizáveis (ver
 * ReportController::printView() e app/views/reports/customize.php).
 */
$logo = $reportLogo ?? '';
$color = $reportColor ?? '#1e3a5f';
$cnpj = $reportCnpj ?? '';
$address = $reportAddress ?? '';
$phone = $reportPhone ?? '';
$footer = $reportFooter ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --tc-print-color: <?= e($color) ?>; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #1f2937; padding: 2rem; }
        .tc-print-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; border-bottom: 3px solid var(--tc-print-color); padding-bottom: 0.75rem; margin-bottom: 0.75rem; }
        .tc-print-header img { max-height: 52px; }
        h1 { font-size: 1.3rem; margin-bottom: 0; color: var(--tc-print-color); }
        .tc-print-company-info { font-size: 0.76rem; color: #6b7280; text-align: right; }
        .tc-print-meta { color: #6b7280; font-size: 0.8rem; margin-bottom: 1.25rem; }
        .table-scroll { width: 100%; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }
        th, td { border: 1px solid #d1d5db; padding: 0.4rem 0.5rem; text-align: left; white-space: nowrap; }
        thead th { background: var(--tc-print-color); color: #fff; }
        .tc-print-toolbar { margin-bottom: 1.5rem; }
        .tc-print-footer { margin-top: 1.5rem; font-size: 0.74rem; color: #6b7280; border-top: 1px solid #e5e9ef; padding-top: 0.6rem; }
        @media print {
            .tc-print-toolbar { display: none; }
            body { padding: 0; }
            table { font-size: 0.7rem; }
            th, td { white-space: normal; }
        }
        @media (max-width: 575.98px) {
            body { padding: 1rem; }
            .tc-print-company-info { text-align: left; }
        }
    </style>
</head>
<body>
    <div class="tc-print-toolbar">
        <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Imprimir / Salvar como PDF</button>
    </div>

    <div class="tc-print-header">
        <div class="d-flex align-items-center gap-3">
            <?php if ($logo): ?>
                <img src="<?= e($logo) ?>" alt="Logo">
            <?php endif; ?>
            <h1><?= e(APP_NAME) ?> — Relatório de Leads</h1>
        </div>
        <?php if ($cnpj || $address || $phone): ?>
            <div class="tc-print-company-info">
                <?php if ($cnpj): ?><div>CNPJ: <?= e($cnpj) ?></div><?php endif; ?>
                <?php if ($phone): ?><div>Tel: <?= e($phone) ?></div><?php endif; ?>
                <?php if ($address): ?><div><?= e($address) ?></div><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="tc-print-meta">
        Gerado em <?= e($generatedAt) ?> por <?= e($generatedBy) ?> &middot; <?= count($leads) ?> lead(s)
    </div>

    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <?php foreach ($columns as $colKey): ?>
                        <th><?= e($columnLabels[$colKey] ?? $colKey) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leads as $lead): ?>
                    <tr>
                        <?php foreach ($columns as $colKey): ?>
                            <?php
                                switch ($colKey) {
                                    case 'lead_code': $val = $lead['lead_code'] ?? ''; break;
                                    case 'name': $val = $lead['name'] ?: '-'; break;
                                    case 'phone': $val = format_phone($lead['phone'] ?? null); break;
                                    case 'whatsapp': $val = format_phone($lead['whatsapp'] ?? null); break;
                                    case 'email': $val = $lead['email'] ?: '-'; break;
                                    case 'cpf': $val = format_cpf($lead['cpf'] ?? null); break;
                                    case 'city': $val = $lead['city'] ?: '-'; break;
                                    case 'state': $val = $lead['state'] ?: '-'; break;
                                    case 'source': $val = source_label($lead['source'] ?? null); break;
                                    case 'campaign': $val = $lead['campaign'] ?: '-'; break;
                                    case 'interest': $val = interest_label($lead['interest'] ?? null); break;
                                    case 'desired_value': $val = $lead['desired_value'] !== null ? 'R$ ' . number_format((float) $lead['desired_value'], 2, ',', '.') : '-'; break;
                                    case 'income_range': $val = $lead['income_range'] ?: '-'; break;
                                    case 'profession': $val = $lead['profession'] ?: '-'; break;
                                    case 'status': $val = status_label($lead['status'] ?? null); break;
                                    case 'assigned_name': $val = $lead['assigned_name'] ?? '-'; break;
                                    case 'lead_score': $val = (string) ($lead['lead_score'] ?? 0); break;
                                    case 'temperature': $val = $lead['temperature'] ?: '-'; break;
                                    case 'priority': $val = $lead['priority'] ?: '-'; break;
                                    case 'created_at': $val = format_date($lead['created_at'] ?? null); break;
                                    case 'last_contact_at': $val = format_date($lead['last_contact_at'] ?? null); break;
                                    case 'next_contact_at': $val = format_date($lead['next_contact_at'] ?? null); break;
                                    default: $val = '-';
                                }
                            ?>
                            <td><?= e((string) $val) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($leads)): ?>
                    <tr><td colspan="<?= max(1, count($columns)) ?>" style="text-align:center;color:#6b7280;">Nenhum lead encontrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($footer): ?>
        <div class="tc-print-footer"><?= nl2br(e($footer)) ?></div>
    <?php endif; ?>
</body>
</html>
