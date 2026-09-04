<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="h5 mb-0"><i class="fa-solid fa-phone-volume me-2"></i>Ligações e inteligência comercial</h2>
        <span class="badge text-bg-light"><?= count($leadCalls) ?> ligação(ões)</span>
    </div>
    <?php if (!$leadCalls): ?>
    <div class="card-body text-center py-4">
        <i class="fa-solid fa-phone-slash text-muted fa-2x mb-3"></i>
        <p class="text-muted mb-0">Nenhuma ligação associada a este lead. O vínculo será feito automaticamente pelo telefone ou WhatsApp na próxima sincronização.</p>
    </div>
    <?php else: ?>
    <div class="list-group list-group-flush">
        <?php foreach ($leadCalls as $leadCall): ?>
        <?php $leadAnalysis = json_decode((string) ($leadCall['payload'] ?? ''), true) ?: []; ?>
        <div class="list-group-item p-3">
            <div class="d-flex flex-wrap justify-content-between gap-3">
                <div class="flex-grow-1">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <strong><?= e(format_datetime($leadCall['started_at'])) ?></strong>
                        <?php if (isset($leadCall['overall_score'])): ?><span class="badge text-bg-primary"><?= (int) $leadCall['overall_score'] ?>/10</span><?php endif; ?>
                        <?php if (!empty($leadCall['lead_temperature'])): ?><span class="badge text-bg-warning">Lead <?= e(str_replace('_', ' ', $leadCall['lead_temperature'])) ?></span><?php endif; ?>
                        <?php if (!empty($leadCall['sales_stage'])): ?><span class="badge text-bg-light border"><?= e(str_replace('_', ' ', $leadCall['sales_stage'])) ?></span><?php endif; ?>
                    </div>
                    <div class="small text-muted mb-2"><?= e($leadCall['agent_name'] ?: 'Responsável não identificado') ?> · <?= e(gmdate('i:s', (int) $leadCall['duration'])) ?></div>
                    <?php if (!empty($leadCall['summary'])): ?><p class="mb-2"><?= e($leadCall['summary']) ?></p><?php endif; ?>
                    <?php if (!empty($leadAnalysis['next_steps']) && is_array($leadAnalysis['next_steps'])): ?><div class="small"><strong>Próximo passo:</strong> <?= e((string) $leadAnalysis['next_steps'][0]) ?></div><?php endif; ?>
                </div>
                <div><a class="btn btn-sm btn-primary" href="<?= e(url('ligacoes/' . $leadCall['id'])) ?>"><i class="fa-solid fa-play me-1"></i>Ouvir e ver análise</a></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

