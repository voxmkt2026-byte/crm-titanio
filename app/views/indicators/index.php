<?php
/** Painel de indicadores comerciais com KPIs acionáveis e filtros de período. */
$dayNames = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
$stateCentroids = [
    'AC' => [-9.02, -70.81], 'AL' => [-9.57, -36.78], 'AP' => [1.41, -51.77], 'AM' => [-4.14, -63.06], 'BA' => [-12.58, -41.70], 'CE' => [-5.20, -39.53], 'DF' => [-15.83, -47.86], 'ES' => [-19.19, -40.34], 'GO' => [-15.98, -49.86], 'MA' => [-5.42, -45.44], 'MT' => [-12.64, -55.42], 'MS' => [-20.51, -54.54], 'MG' => [-18.51, -44.55], 'PA' => [-3.79, -52.48], 'PB' => [-7.28, -36.72], 'PR' => [-24.89, -51.55], 'PE' => [-8.38, -37.86], 'PI' => [-7.72, -42.73], 'RJ' => [-22.25, -42.66], 'RN' => [-5.81, -36.59], 'RS' => [-30.03, -53.30], 'RO' => [-10.94, -62.83], 'RR' => [1.99, -61.33], 'SC' => [-27.33, -49.44], 'SP' => [-22.19, -48.79], 'SE' => [-10.57, -37.45], 'TO' => [-10.25, -48.25],
];
$sortedStates = $byState;
uasort($sortedStates, static fn($a, $b) => $b['total'] <=> $a['total']);
$trendDays = array_map(static fn($row) => $row['day'], $captureTrend);
$indicatorPayload = [
    'stateData' => $byState,
    'maxTotal' => $maxTotal,
    'centroids' => $stateCentroids,
    'geoJsonUrl' => $geoJsonUrl,
    'leadMapBaseUrl' => $leadMapBaseUrl,
    'trend' => [
        'labels' => array_map(static fn($row) => format_date($row['day']), $captureTrend),
        'days' => $trendDays,
        'data' => array_map(static fn($row) => $row['total'], $captureTrend),
    ],
    'sources' => [
        'keys' => array_map(static fn($row) => $row['source'], $sourcePerformance),
        'labels' => array_map(static fn($row) => source_label($row['source']), $sourcePerformance),
        'data' => array_map(static fn($row) => $row['conversion'], $sourcePerformance),
    ],
];
$previous = $kpis['previous_captured'];
$captureDelta = $previous === null ? null : ($previous > 0 ? round((($kpis['captured'] - $previous) / $previous) * 100, 1) : null);
?>

<div id="tcIndicatorsApp" data-indicators="<?= e((string) json_encode($indicatorPayload, $jsonFlags)) ?>">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div><h4 class="mb-1">Indicadores comerciais</h4><p class="text-muted mb-0">Clique em um KPI, estado ou origem para abrir a base que explica o número.</p></div>
        <span class="badge text-bg-light border px-3 py-2"><i class="fa-regular fa-calendar me-1"></i> <?= e($range['label']) ?></span>
    </div>

    <div class="tc-card mb-3">
        <div class="tc-card-body">
            <form method="GET" action="<?= e(url('indicadores')) ?>" id="tcIndicatorFilters" class="row g-2 align-items-end">
                <div class="col-sm-6 col-lg-3"><label class="form-label">Período de captação</label><select name="period" id="tcIndicatorPeriod" class="form-select"><option value="all" <?= $range['period'] === 'all' ? 'selected' : '' ?>>Todo o histórico</option><option value="today" <?= $range['period'] === 'today' ? 'selected' : '' ?>>Hoje</option><option value="7d" <?= $range['period'] === '7d' ? 'selected' : '' ?>>Últimos 7 dias</option><option value="30d" <?= $range['period'] === '30d' ? 'selected' : '' ?>>Últimos 30 dias</option><option value="month" <?= $range['period'] === 'month' ? 'selected' : '' ?>>Este mês</option><option value="quarter" <?= $range['period'] === 'quarter' ? 'selected' : '' ?>>Este trimestre</option><option value="custom" <?= $range['period'] === 'custom' ? 'selected' : '' ?>>Personalizado</option></select></div>
                <div class="col-6 col-lg-3"><label class="form-label">Cadastro: de</label><input type="date" name="date_from" class="form-control" value="<?= e($range['date_from']) ?>"></div>
                <div class="col-6 col-lg-3"><label class="form-label">Cadastro: até</label><input type="date" name="date_to" class="form-control" value="<?= e($range['date_to']) ?>"></div>
                <div class="col-lg-3 d-flex gap-2"><button type="submit" class="btn btn-tc-primary flex-grow-1"><i class="fa-solid fa-chart-line me-1"></i> Atualizar</button><a href="<?= e(url('indicadores')) ?>" class="btn btn-outline-secondary">Limpar</a></div>
                <div class="col-12"><small class="text-muted">Captação, conversão e SLA consideram os leads cadastrados no período. Receita considera a data de fechamento e o valor efetivamente registrado.</small></div>
            </form>
        </div>
    </div>

    <section class="mb-4" aria-label="KPIs acionáveis">
        <div class="d-flex justify-content-between align-items-center mb-2"><h5 class="mb-0">Pulso do negócio</h5><small class="text-muted">Indicadores atualizados ao carregar a página</small></div>
        <div class="row g-3">
            <div class="col-6 col-md-4 col-xl-3"><a href="<?= e($kpiLinks['captured']) ?>" class="tc-indicator-kpi"><div class="tc-kpi-card h-100"><div class="tc-kpi-icon bg-primary-subtle text-primary"><i class="fa-solid fa-user-plus"></i></div><div><div class="tc-kpi-value"><?= (int) $kpis['captured'] ?></div><div class="tc-kpi-label">Leads captados</div><small class="<?= $captureDelta !== null && $captureDelta < 0 ? 'text-danger' : 'text-muted' ?>"><?php if ($captureDelta === null): ?>Histórico completo<?php elseif ($previous === 0): ?>Sem base anterior<?php else: ?><i class="fa-solid fa-arrow-<?= $captureDelta >= 0 ? 'trend-up' : 'trend-down' ?> me-1"></i><?= ($captureDelta >= 0 ? '+' : '') . str_replace('.', ',', (string) $captureDelta) ?>% vs. período anterior<?php endif; ?></small></div></div></a></div>
            <div class="col-6 col-md-4 col-xl-3"><a href="<?= e($kpiLinks['negotiation']) ?>" class="tc-indicator-kpi"><div class="tc-kpi-card h-100"><div class="tc-kpi-icon bg-warning-subtle text-warning"><i class="fa-solid fa-handshake"></i></div><div><div class="tc-kpi-value"><?= (int) $kpis['negotiation'] ?></div><div class="tc-kpi-label">Em negociação</div><small class="text-muted">Priorizar próximos passos</small></div></div></a></div>
            <div class="col-6 col-md-4 col-xl-3"><a href="<?= e($kpiLinks['conversion']) ?>" class="tc-indicator-kpi"><div class="tc-kpi-card h-100"><div class="tc-kpi-icon bg-success-subtle text-success"><i class="fa-solid fa-bullseye"></i></div><div><div class="tc-kpi-value"><?= number_format($kpis['conversion_rate'], 1, ',', '.') ?>%</div><div class="tc-kpi-label">Conversão do funil</div><small class="text-muted">Coorte de captação</small></div></div></a></div>
            <div class="col-6 col-md-4 col-xl-3"><a href="<?= e($kpiLinks['revenue']) ?>" class="tc-indicator-kpi"><div class="tc-kpi-card h-100"><div class="tc-kpi-icon bg-success-subtle text-success"><i class="fa-solid fa-sack-dollar"></i></div><div><div class="tc-kpi-value tc-indicator-money"><?= e(format_money($kpis['revenue'])) ?></div><div class="tc-kpi-label">Receita efetivada</div><small class="text-muted"><?= (int) $kpis['deals_closed'] ?> fechamento(s)</small></div></div></a></div>
            <div class="col-6 col-md-4 col-xl-3"><a href="<?= e($kpiLinks['first_contact']) ?>" class="tc-indicator-kpi"><div class="tc-kpi-card h-100"><div class="tc-kpi-icon bg-info-subtle text-info"><i class="fa-solid fa-stopwatch"></i></div><div><div class="tc-kpi-value"><?= $kpis['average_first_contact'] === null ? '—' : e($kpis['average_first_contact'] < 60 ? number_format($kpis['average_first_contact'], 0, ',', '.') . ' min' : number_format($kpis['average_first_contact'] / 60, 1, ',', '.') . ' h') ?></div><div class="tc-kpi-label">1º contato médio</div><small class="text-muted">Ligação, WhatsApp ou contato</small></div></div></a></div>
            <div class="col-6 col-md-4 col-xl-3"><a href="<?= e($kpiLinks['fast_sla']) ?>" class="tc-indicator-kpi"><div class="tc-kpi-card h-100"><div class="tc-kpi-icon bg-info-subtle text-info"><i class="fa-solid fa-bolt"></i></div><div><div class="tc-kpi-value"><?= number_format($kpis['within_15_rate'], 1, ',', '.') ?>%</div><div class="tc-kpi-label">SLA em até 15 min</div><small class="text-muted">Primeiros contatos rápidos</small></div></div></a></div>
            <div class="col-6 col-md-4 col-xl-3"><a href="<?= e($kpiLinks['waiting']) ?>" class="tc-indicator-kpi"><div class="tc-kpi-card h-100"><div class="tc-kpi-icon bg-danger-subtle text-danger"><i class="fa-solid fa-user-clock"></i></div><div><div class="tc-kpi-value"><?= (int) $kpis['waiting_over_24h'] ?></div><div class="tc-kpi-label">Sem 1º contato &gt; 24h</div><small class="text-danger">Ação imediata necessária</small></div></div></a></div>
            <div class="col-6 col-md-4 col-xl-3"><a href="<?= e($kpiLinks['followups']) ?>" class="tc-indicator-kpi"><div class="tc-kpi-card h-100"><div class="tc-kpi-icon bg-danger-subtle text-danger"><i class="fa-solid fa-calendar-xmark"></i></div><div><div class="tc-kpi-value"><?= (int) $kpis['overdue_followups'] ?></div><div class="tc-kpi-label">Retornos vencidos</div><small class="text-danger">Reativar contato</small></div></div></a></div>
        </div>
        <?php if ($kpis['overdue_tasks'] !== null): ?><a href="<?= e($kpiLinks['tasks']) ?>" class="tc-indicator-operation mt-3"><i class="fa-solid fa-list-check"></i><span><strong><?= (int) $kpis['overdue_tasks'] ?> tarefa(s) atrasada(s)</strong> na operação. Abra o Kanban para priorizar responsáveis e prazos.</span><i class="fa-solid fa-arrow-right ms-auto"></i></a><?php endif; ?>
    </section>

    <div class="row g-3 mb-4">
        <div class="col-xl-7"><div class="tc-card h-100"><div class="tc-card-header">Evolução da captação <span class="text-muted fw-normal" style="font-size:.75rem">(clique em um ponto para abrir o dia)</span></div><div class="tc-card-body"><div class="tc-chart-wrap"><canvas id="chartIndicatorTrend"></canvas></div></div></div></div>
        <div class="col-xl-5"><div class="tc-card h-100"><div class="tc-card-header">Conversão por origem <span class="text-muted fw-normal" style="font-size:.75rem">(clique para filtrar)</span></div><div class="tc-card-body"><?php if (empty($sourcePerformance)): ?><div class="text-muted text-center py-5">Sem origens no recorte.</div><?php else: ?><div class="tc-chart-wrap"><canvas id="chartIndicatorSource"></canvas></div><?php endif; ?></div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7"><div class="tc-card h-100"><div class="tc-card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"><span><i class="fa-solid fa-map-location-dot me-1"></i> Mapa de oportunidade por estado</span><span class="btn-group btn-group-sm" role="group" aria-label="Métrica do mapa"><button type="button" class="btn btn-outline-primary active" data-map-mode="volume">Volume</button><button type="button" class="btn btn-outline-primary" data-map-mode="conversion">Conversão</button></span></div><div class="tc-card-body"><div id="tcLeafletMap"></div><div id="tcMapUnavailable" class="tc-map-unavailable d-none"><i class="fa-solid fa-map-location-dot"></i><div>Mapa indisponível no momento.</div><small>Não foi possível carregar os dados geográficos dos estados.</small></div><div class="d-flex justify-content-center gap-3 mt-2 tc-map-legend"><span><span class="tc-legend-dot" style="background:#dbeafe"></span> Menor</span><span><span class="tc-legend-dot" style="background:#3b82f6"></span> Médio</span><span><span class="tc-legend-dot" style="background:#1e3a5f"></span> Maior</span></div></div></div></div>
        <div class="col-lg-5"><div class="tc-card h-100"><div class="tc-card-header">Ranking de estados <span class="text-muted fw-normal" style="font-size:.75rem">(clique para abrir)</span></div><div class="tc-table-card tc-indicator-state-table"><table class="table tc-table mb-0"><thead><tr><th>UF</th><th>Leads</th><th>Conversão</th></tr></thead><tbody><?php if (empty($sortedStates)): ?><tr><td colspan="3" class="text-center text-muted py-4">Sem dados de estado ainda.</td></tr><?php endif; ?><?php foreach ($sortedStates as $uf => $data): ?><tr class="tc-clickable-row" data-state="<?= e($uf) ?>"><td class="fw-semibold"><a href="<?= e($leadMapBaseUrl . (strpos($leadMapBaseUrl, '?') !== false ? '&' : '?') . 'state=' . rawurlencode($uf)) ?>"><?= e($uf) ?></a></td><td><?= (int) $data['total'] ?></td><td><?= e(str_replace('.', ',', (string) $data['conversion'])) ?>%</td></tr><?php endforeach; ?></tbody></table></div></div></div>
    </div>

    <div class="tc-card">
        <div class="tc-card-header"><i class="fa-solid fa-fire me-1"></i> Heatmap de horários <span class="text-muted fw-normal" style="font-size:.75rem">(dia da semana × hora da captação; passe o mouse para detalhes)</span></div>
        <div class="tc-card-body"><div class="table-responsive"><table class="tc-heatmap-table"><thead><tr><th></th><?php for ($hour = 0; $hour < 24; $hour++): ?><th><?= sprintf('%02d', $hour) ?></th><?php endfor; ?></tr></thead><tbody><?php foreach ($dayNames as $dow => $label): ?><tr><th><?= e($label) ?></th><?php for ($hour = 0; $hour < 24; $hour++): $count = $heatmap[$dow][$hour] ?? 0; $intensity = $maxHeat > 0 ? $count / $maxHeat : 0; $alpha = $count > 0 ? max(0.12, min(1, $intensity)) : 0; ?><td style="background:rgba(59,130,246,<?= $alpha ?>)" title="<?= e($label . ' ' . sprintf('%02d:00', $hour) . ' — ' . $count . ' lead(s)') ?>"><?= $count > 0 ? (int) $count : '' ?></td><?php endfor; ?></tr><?php endforeach; ?></tbody></table></div></div>
    </div>
</div>

<?php
$pageScripts = '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>'
    . '<script src="https://cdn.jsdelivr.net/npm/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>'
    . '<script src="' . e(asset('js/indicators.js')) . '"></script>';
?>
