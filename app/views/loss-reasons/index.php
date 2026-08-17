<?php
/**
 * app/views/loss-reasons/index.php
 * CRUD de motivos de perda + gráfico Chart.js com ranking de uso.
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
$chartLabels = array_column($chartRanking, 'name');
$chartValues = array_map('intval', array_column($chartRanking, 'total'));
?>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="tc-card h-100">
            <div class="tc-card-header">Ranking de motivos de perda mais usados</div>
            <div class="tc-card-body">
                <div class="tc-chart-wrap"><canvas id="chartLossReasons"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="tc-card h-100">
            <div class="tc-card-header">Novo motivo de perda</div>
            <div class="tc-card-body">
                <form method="POST" action="<?= e(url('motivos-perda/store')) ?>">
                    <?= Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label">Nome do motivo</label>
                        <input type="text" name="name" class="form-control" placeholder="Ex: Documentação incompleta" required>
                    </div>
                    <button type="submit" class="btn btn-tc-primary w-100"><i class="fa-solid fa-plus me-1"></i> Adicionar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="tc-table-card">
    <div class="table-responsive">
        <table class="table tc-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Leads perdidos com este motivo</th>
                    <th>Status</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ranking)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Nenhum motivo de perda cadastrado.</td></tr>
                <?php endif; ?>
                <?php foreach ($ranking as $reason): ?>
                    <tr>
                        <form method="POST" action="<?= e(url('motivos-perda/' . $reason['id'] . '/update')) ?>" class="d-none" id="reasonForm<?= (int) $reason['id'] ?>">
                            <?= Csrf::field() ?>
                        </form>
                        <td>
                            <input type="text" form="reasonForm<?= (int) $reason['id'] ?>" name="name" class="form-control form-control-sm" value="<?= e($reason['name']) ?>" style="max-width:260px;">
                        </td>
                        <td><span class="badge bg-secondary"><?= (int) $reason['total'] ?></span></td>
                        <td>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" form="reasonForm<?= (int) $reason['id'] ?>" name="active" value="1" <?= (int) $reason['active'] === 1 ? 'checked' : '' ?>>
                            </div>
                        </td>
                        <td class="text-end">
                            <button type="submit" form="reasonForm<?= (int) $reason['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Salvar">
                                <i class="fa-solid fa-floppy-disk"></i>
                            </button>
                            <form method="POST" action="<?= e(url('motivos-perda/' . $reason['id'] . '/delete')) ?>" class="d-inline tc-delete-form" data-confirm-text="O motivo de perda será excluído permanentemente.">
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

<?= render_pagination($page, $totalPages, url('motivos-perda'), []) ?>

<?php
$pageScripts = '<script>
document.addEventListener("DOMContentLoaded", function () {
    var isDark = document.body.classList.contains("dark-mode");
    Chart.defaults.color = isDark ? "#c8d3de" : "#4b5563";
    Chart.defaults.borderColor = isDark ? "rgba(255,255,255,0.08)" : "rgba(0,0,0,0.06)";

    new Chart(document.getElementById("chartLossReasons"), {
        type: "bar",
        data: {
            labels: ' . json_encode($chartLabels, $jsonFlags) . ',
            datasets: [{
                label: "Leads perdidos",
                data: ' . json_encode($chartValues, $jsonFlags) . ',
                backgroundColor: "#dc2626",
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
