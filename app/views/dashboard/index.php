<?php
/**
 * app/views/dashboard/index.php
 * KPIs + 4 gráficos Chart.js + insights automáticos.
 */

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;

$perDayLabels = array_map(fn($r) => date('d/m', strtotime($r['day'])), $perDay);
$perDayValues = array_map(fn($r) => (int) $r['total'], $perDay);

$sourceLabels = array_map(fn($r) => source_label($r['source']), $bySource);
$sourceValues = array_map(fn($r) => (int) $r['total'], $bySource);

$stateLabels = array_column($byState, 'state');
$stateValues = array_map('intval', array_column($byState, 'total'));

$statusLabels = array_map(fn($r) => status_label($r['status']), $byStatus);
$statusValues = array_map(fn($r) => (int) $r['total'], $byStatus);
?>

<!-- KPIs -->
<div class="row g-3 mb-4 tc-personalizable-widgets" id="tcDashboardWidgets" data-save-url="<?= e(url('workspace/preferencias')) ?>" data-csrf="<?= e(Csrf::token()) ?>">
    <div class="col-6 col-md-4 col-xl-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#3b82f6,#4f46e5);"><i class="fa-solid fa-users"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $kpis['total'] ?></div>
                <div class="tc-kpi-label">Total de Leads</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#16a34a,#22c55e);"><i class="fa-solid fa-calendar-day"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $kpis['new_today'] ?></div>
                <div class="tc-kpi-label">Novos Hoje</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#0891b2,#22d3ee);"><i class="fa-solid fa-calendar-week"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $kpis['new_week'] ?></div>
                <div class="tc-kpi-label">Novos na Semana</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#6366f1,#818cf8);"><i class="fa-solid fa-calendar"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $kpis['new_month'] ?></div>
                <div class="tc-kpi-label">Novos no Mês</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#d97706,#f59e0b);"><i class="fa-solid fa-star"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $kpis['qualified'] ?></div>
                <div class="tc-kpi-label">Leads Qualificados</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#dc2626,#f87171);"><i class="fa-solid fa-circle-xmark"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $kpis['lost'] ?></div>
                <div class="tc-kpi-label">Leads Perdidos</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#0d9488,#2dd4bf);"><i class="fa-solid fa-headset"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $kpis['in_progress'] ?></div>
                <div class="tc-kpi-label">Em Atendimento</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-3">
        <div class="tc-kpi-card">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#7c3aed,#a78bfa);"><i class="fa-solid fa-percent"></i></div>
            <div>
                <div class="tc-kpi-value"><?= e(str_replace('.', ',', (string) $kpis['conversion_rate'])) ?>%</div>
                <div class="tc-kpi-label">Taxa de Conversão</div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($kpis['without_contact'])): ?>
<div class="row g-3 mb-4">
    <div class="col-12">
        <a href="<?= e(url('leads?sem_contato_dias=5')) ?>" class="tc-kpi-card d-flex text-decoration-none text-reset" style="border-left: 4px solid var(--tc-danger); cursor: pointer;" title="Ver esses leads na listagem">
            <div class="tc-kpi-icon" style="background: linear-gradient(135deg,#dc2626,#f87171);"><i class="fa-solid fa-clock"></i></div>
            <div>
                <div class="tc-kpi-value"><?= (int) $kpis['without_contact'] ?></div>
                <div class="tc-kpi-label">Leads sem contato há mais de 5 dias <i class="fa-solid fa-arrow-right ms-1" style="font-size:0.7rem;"></i></div>
            </div>
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Insights automáticos (Fase 2: motor mais completo em app/helpers/insights.php) -->
<?php if (!empty($insights)): ?>
<div class="row g-3 mb-4">
    <?php
    $insightBorderColor = [
        'up'      => 'var(--tc-success)',
        'down'    => 'var(--tc-danger)',
        'warning' => 'var(--tc-warning)',
        'info'    => 'var(--tc-accent)',
    ];
    ?>
    <?php foreach ($insights as $insight):
        $type = is_array($insight) ? ($insight['type'] ?? 'info') : 'info';
        $icon = is_array($insight) ? ($insight['icon'] ?? 'fa-lightbulb') : 'fa-lightbulb';
        $text = is_array($insight) ? ($insight['text'] ?? '') : $insight;
        $insightUrl = is_array($insight) ? ($insight['url'] ?? null) : null;
        $borderColor = $insightBorderColor[$type] ?? $insightBorderColor['info'];
    ?>
        <div class="col-md-4">
            <?php if ($insightUrl): ?>
                <a href="<?= e($insightUrl) ?>" class="tc-insight-card d-block text-decoration-none text-reset" style="border-left-color: <?= e($borderColor) ?>; cursor: pointer;" title="Ver esses leads na listagem">
                    <i class="fa-solid <?= e($icon) ?>" style="color: <?= e($borderColor) ?>;"></i>
                    <span><?= e($text) ?> <i class="fa-solid fa-arrow-right ms-1" style="font-size:0.75rem;"></i></span>
                </a>
            <?php else: ?>
                <div class="tc-insight-card" style="border-left-color: <?= e($borderColor) ?>;">
                    <i class="fa-solid <?= e($icon) ?>" style="color: <?= e($borderColor) ?>;"></i>
                    <span><?= e($text) ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Gráficos -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="tc-card">
            <div class="tc-card-header">Leads por dia (últimos 30 dias)</div>
            <div class="tc-card-body">
                <div class="tc-chart-wrap"><canvas id="chartPerDay"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="tc-card">
            <div class="tc-card-header">Leads por origem</div>
            <div class="tc-card-body">
                <div class="tc-chart-wrap"><canvas id="chartSource"></canvas></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="tc-card">
            <div class="tc-card-header">Leads por estado</div>
            <div class="tc-card-body">
                <div class="tc-chart-wrap"><canvas id="chartState"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="tc-card">
            <div class="tc-card-header">Funil de vendas por status</div>
            <div class="tc-card-body">
                <div class="tc-chart-wrap"><canvas id="chartStatus"></canvas></div>
            </div>
        </div>
    </div>
</div>

<?php
$pageScripts = '<script>
document.addEventListener("DOMContentLoaded", function () {
    var isDark = document.body.classList.contains("dark-mode");
    var gridColor = isDark ? "rgba(255,255,255,0.08)" : "rgba(0,0,0,0.06)";
    var textColor = isDark ? "#c8d3de" : "#4b5563";
    Chart.defaults.color = textColor;
    Chart.defaults.borderColor = gridColor;

    new Chart(document.getElementById("chartPerDay"), {
        type: "line",
        data: {
            labels: ' . json_encode($perDayLabels, $jsonFlags) . ',
            datasets: [{
                label: "Leads",
                data: ' . json_encode($perDayValues, $jsonFlags) . ',
                borderColor: "#3b82f6",
                backgroundColor: "rgba(59,130,246,0.12)",
                tension: 0.35,
                fill: true,
                pointRadius: 2
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });

    new Chart(document.getElementById("chartSource"), {
        type: "doughnut",
        data: {
            labels: ' . json_encode($sourceLabels, $jsonFlags) . ',
            datasets: [{
                data: ' . json_encode($sourceValues, $jsonFlags) . ',
                backgroundColor: ["#3b82f6","#4f46e5","#0891b2","#16a34a","#d97706","#dc2626","#7c3aed","#0d9488"]
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: "bottom" } } }
    });

    new Chart(document.getElementById("chartState"), {
        type: "bar",
        data: {
            labels: ' . json_encode($stateLabels, $jsonFlags) . ',
            datasets: [{
                label: "Leads",
                data: ' . json_encode($stateValues, $jsonFlags) . ',
                backgroundColor: "#4f46e5",
                borderRadius: 6
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });

    new Chart(document.getElementById("chartStatus"), {
        type: "bar",
        data: {
            labels: ' . json_encode($statusLabels, $jsonFlags) . ',
            datasets: [{
                label: "Leads",
                data: ' . json_encode($statusValues, $jsonFlags) . ',
                backgroundColor: "#0891b2",
                borderRadius: 6
            }]
        },
        options: {
            indexAxis: "y",
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } }
        }
    });
});
</script>';
?>
