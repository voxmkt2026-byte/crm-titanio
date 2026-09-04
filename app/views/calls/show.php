<?php
$analysis = $call['analysis_payload'] ?? [];
$scores = is_array($analysis['scores'] ?? null) ? $analysis['scores'] : [];
$labels = ['overall'=>'Desempenho geral','opening'=>'Abertura','discovery'=>'Descoberta','argumentation'=>'Argumentação','value_proposition'=>'Proposta de valor','objection_handling'=>'Quebra de objeções','closing'=>'Fechamento','follow_up'=>'Follow-up'];
$human = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value ?: 'indefinido'));
$renderList = static function (mixed $values, string $empty = 'Nenhum item identificado.'): void {
    $items = is_array($values) ? $values : [];
    if (!$items) { echo '<p class="text-muted mb-0">' . e($empty) . '</p>'; return; }
    echo '<ul class="mb-0">';
    foreach ($items as $item) {
        if (is_array($item)) {
            $title = $item['point'] ?? $item['objection'] ?? $item['title'] ?? 'Item';
            $detail = $item['recommended_action'] ?? $item['suggested_response'] ?? $item['evidence'] ?? '';
            echo '<li class="mb-2"><strong>' . e($title) . '</strong>' . ($detail !== '' ? '<div class="text-muted">' . e($detail) . '</div>' : '') . '</li>';
        } else {
            echo '<li>' . e($item) . '</li>';
        }
    }
    echo '</ul>';
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <a href="<?= e(url('ligacoes')) ?>" class="text-decoration-none"><i class="fa-solid fa-arrow-left me-1"></i>Voltar às ligações</a>
    <div class="d-flex gap-2">
        <?php if (!empty($call['lead_id'])): ?><a class="btn btn-outline-primary" href="<?= e(url('leads/' . $call['lead_id'])) ?>"><i class="fa-solid fa-user me-1"></i>Abrir lead</a><?php endif; ?>
        <?php if (Auth::can('calls.reanalyze')): ?><form class="js-call-analysis-form" method="post" action="<?= e(url('ligacoes/' . $call['id'] . '/analisar')) ?>" data-external-id="<?= e($call['external_id'] ?? '') ?>"><?= Csrf::field() ?><input type="hidden" name="force" value="<?= $analysis ? '1' : '0' ?>"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-wand-magic-sparkles me-1"></i><?= $analysis ? 'Analisar novamente' : 'Analisar agora' ?></button></form><?php endif; ?>
    </div>
</div>

<div class="card mb-4"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-3"><div><div class="text-uppercase text-success small fw-bold mb-1">Inteligência da conversa</div><h1 class="h3 mb-1">Análise da ligação</h1><div class="text-muted"><?= e($call['lead_name'] ?: $call['contact_phone'] ?: 'Ligação sem lead') ?> · <?= e(format_datetime($call['started_at'])) ?> · <?= e(gmdate('i:s',(int)$call['duration'])) ?></div></div><div class="text-end"><div class="display-6 fw-bold"><?= isset($call['overall_score']) ? (int)$call['overall_score'] . '/10' : '—' ?></div><small class="text-muted">Nota geral</small></div></div>
    <?php if (($call['recording_status'] ?? '') !== 'discarded' && ($call['recording_status'] ?? '') !== 'unavailable'): ?><audio class="w-100 mt-4" controls preload="metadata" src="<?= e(url('ligacoes/' . $call['id'] . '/audio')) ?>"></audio><div class="form-text">A gravação é carregada da Api4Com e armazenada com segurança, sem usar IA.</div><?php else: ?><div class="alert alert-light border mt-4 mb-0">Esta ligação não possui gravação disponível.</div><?php endif; ?>
</div></div>

<?php if ($analysis): ?>
<div class="card mb-3"><div class="card-body"><h2 class="h5">Resumo executivo</h2><p class="mb-0"><?= nl2br(e($call['summary'])) ?></p></div></div>
<div class="card mb-3"><div class="card-body"><h2 class="h6 text-uppercase text-muted">Diagnóstico comercial</h2><div class="d-flex flex-wrap gap-2"><span class="badge rounded-pill text-bg-warning">Lead <?= e($human((string)$call['lead_temperature'])) ?></span><span class="badge rounded-pill text-bg-primary">Etapa: <?= e($human((string)$call['sales_stage'])) ?></span><span class="badge rounded-pill text-bg-info">Sentimento: <?= e($human((string)$call['sentiment'])) ?></span></div></div></div>

<div class="card mb-3"><div class="card-body"><h2 class="h5">Pontuação da abordagem</h2><div class="row g-3"><?php foreach ($labels as $key=>$label): $score=max(0,min(10,(int)($scores[$key]??($key==='overall'?$call['overall_score']:0)))); ?><div class="col-md-6"><div class="d-flex justify-content-between small mb-1"><span><?= e($label) ?></span><strong><?= $score ?>/10</strong></div><div class="progress" style="height:7px"><div class="progress-bar bg-<?= $score>=7?'success':($score>=5?'warning':'danger') ?>" style="width:<?= $score*10 ?>%"></div></div></div><?php endforeach; ?></div></div></div>

<div class="row g-3">
    <?php foreach ([
        ['Necessidades do cliente','customer_needs'],
        ['Sinais de compra','buying_signals'],
        ['Pontos fortes do vendedor','strengths'],
        ['Oportunidades de melhoria','improvements'],
        ['Objeções e respostas sugeridas','objections'],
        ['Perguntas para aprofundar','discovery_questions'],
        ['Próximos passos','next_steps'],
        ['Riscos comerciais','risks'],
        ['Alertas de conformidade','compliance_alerts'],
    ] as [$title,$key]): ?><div class="col-lg-6"><div class="card h-100"><div class="card-body"><h2 class="h6"><?= e($title) ?></h2><?php $renderList($analysis[$key]??[]); ?></div></div></div><?php endforeach; ?>
</div>

<div class="card my-3 border-primary-subtle"><div class="card-body"><h2 class="h5"><i class="fa-solid fa-bullseye me-2 text-primary"></i>Abordagem recomendada</h2><p class="mb-0"><?= nl2br(e($analysis['recommended_approach'] ?? 'Nenhuma abordagem adicional sugerida.')) ?></p></div></div>

<?php if (!empty($analysis['sales_script']) && is_array($analysis['sales_script'])): ?><div class="card mb-3"><div class="card-body"><h2 class="h5">Script sugerido para a próxima conversa</h2><div class="row g-3"><?php foreach (['opening'=>'Abertura','value_pitch'=>'Proposta de valor','objection_handling'=>'Quebra de objeções','closing'=>'Fechamento'] as $key=>$label): ?><div class="col-md-6"><strong><?= e($label) ?></strong><p class="mb-0"><?= e($analysis['sales_script'][$key]??'Não informado.') ?></p></div><?php endforeach; ?></div></div></div><?php endif; ?>

<div class="card"><div class="card-body"><details><summary class="fw-bold">Ver transcrição completa</summary><p class="mt-3 mb-0" style="white-space:pre-wrap"><?= e($call['transcript']) ?></p></details><?php if (!empty($call['analyzed_at'])): ?><div class="small text-muted mt-3">Analisado em <?= e(format_datetime($call['analyzed_at'])) ?> · <?= e($call['analysis_provider'] ?? '') ?> / <?= e($call['provider_model'] ?? '') ?></div><?php endif; ?></div></div>
<?php else: ?>
<div class="card"><div class="card-body text-center py-5"><?php if (($call['analysis_status'] ?? '') === 'failed'): ?><i class="fa-solid fa-triangle-exclamation fa-2x text-danger mb-3"></i><h2 class="h5">A análise encontrou um erro</h2><p class="text-muted mb-1">Clique em “Analisar novamente”. Se persistir, confira o código abaixo nas configurações.</p><code><?= e($call['last_error_code'] ?? 'AI_ANALYSIS_FAILED') ?></code><?php else: ?><i class="fa-solid fa-wand-magic-sparkles fa-2x text-primary mb-3"></i><h2 class="h5">Análise disponível</h2><p class="text-muted mb-0">Clique em “Analisar agora” e aguarde nesta página até o relatório ser concluído.</p><?php endif; ?></div></div>
<?php endif; ?>
<?php require __DIR__ . '/_analysis_script.php'; ?>
