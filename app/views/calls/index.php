<?php
$filters = $filters ?? ['number' => '', 'status' => ''];
$pagination = $pagination ?? ['page' => 1, 'pages' => 1, 'total' => count($calls ?? [])];
$metrics = $metrics ?? ['visible_calls' => count($calls ?? []), 'matched_calls' => 0, 'analyzed_calls' => 0, 'pending_calls' => 0, 'failed_calls' => 0, 'average_score' => 0];
$syncState = $syncState ?? [];
$pageUrl = static fn (int $page): string => url('ligacoes?' . http_build_query(array_filter([
    'number' => $filters['number'] ?? '',
    'status' => $filters['status'] ?? '',
    'page' => $page,
], static fn ($value) => $value !== '')));
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">Histórico de ligações</h1><p class="text-muted mb-0">Consulte chamadas, ouça gravações e transforme conversas em insights acionáveis.</p></div>
    <div class="d-flex gap-2">
        <?php if (!empty($syncState['last_success_at'])): ?><span class="badge text-bg-light border align-self-center">Atualizado <?= e(format_datetime($syncState['last_success_at'])) ?></span><?php endif; ?>
        <?php if (Auth::can('calls.reanalyze')): ?><button type="button" class="btn btn-primary js-analyze-page"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Analisar pendentes desta página</button><?php endif; ?>
        <?php if (Auth::can('calls.manage')): ?><a class="btn btn-outline-primary" href="<?= e(url('configuracoes/ligacoes')) ?>"><i class="fa-solid fa-gear me-1"></i>Configurar e sincronizar</a><?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Total útil', $metrics['visible_calls'], 'fa-phone-volume', 'primary'],
        ['Associadas', $metrics['matched_calls'], 'fa-link', 'success'],
        ['Analisadas', $metrics['analyzed_calls'], 'fa-wand-magic-sparkles', 'info'],
        ['Nota média', number_format((float) $metrics['average_score'], 1, ',', '.') . '/10', 'fa-star', 'warning'],
    ] as [$label, $value, $icon, $color]): ?>
    <div class="col-6 col-xl-3"><div class="card h-100 border-0 shadow-sm"><div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle bg-<?= e($color) ?>-subtle text-<?= e($color) ?> p-3"><i class="fa-solid <?= e($icon) ?>"></i></div>
        <div><div class="h4 mb-0"><?= e($value) ?></div><div class="small text-muted"><?= e($label) ?></div></div>
    </div></div></div>
    <?php endforeach; ?>
</div>

<form method="get" action="<?= e(url('ligacoes')) ?>" class="card card-body mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-7"><label class="form-label">Buscar por número</label><div class="input-group"><span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span><input class="form-control" name="number" value="<?= e($filters['number'] ?? '') ?>" placeholder="Origem, destino, telefone ou WhatsApp do lead"></div></div>
        <div class="col-md-3"><label class="form-label">Situação</label><select class="form-select" name="status"><option value="">Todas</option><?php foreach (['analyzed'=>'Analisadas','pending'=>'Pendentes','failed'=>'Com falha','unmatched'=>'Sem lead associado','unanswered'=>'Não atendidas','voicemail'=>'Caixa postal'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary"><i class="fa-solid fa-search me-1"></i>Buscar</button></div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><strong>Chamadas recentes</strong><span class="badge text-bg-light">Página <?= (int) $pagination['page'] ?> de <?= (int) $pagination['pages'] ?></span></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Data e hora</th><th>Lead / número</th><th>Atendente</th><th>Duração</th><th>Status</th><th>Análise</th><th class="text-end">Ações</th></tr></thead>
        <tbody>
        <?php foreach ($calls as $call): ?>
        <tr>
            <td><strong><?= e(format_datetime($call['started_at'])) ?></strong><div class="small text-muted"><?= ($call['direction'] ?? '') === 'inbound' ? 'Recebida' : (($call['direction'] ?? '') === 'outbound' ? 'Realizada' : 'Direção não identificada') ?></div></td>
            <td><?php if (!empty($call['lead_id'])): ?><a class="fw-semibold text-decoration-none" href="<?= e(url('leads/' . $call['lead_id'])) ?>"><?= e($call['lead_name']) ?></a><?php else: ?><span class="text-warning fw-semibold">Não associado</span><?php endif; ?><div class="small text-muted"><?= e(!empty($call['normalized_phone']) ? format_phone($call['normalized_phone']) : ($call['contact_phone'] ?: 'Número indisponível')) ?></div></td>
            <td><?= e($call['agent_name'] ?: $call['agent_external_key'] ?: 'Responsável não identificado') ?></td>
            <td><?= e(gmdate('i:s', (int) $call['duration'])) ?></td>
            <td><span class="badge text-bg-<?= in_array(strtoupper((string)($call['hangup_cause'] ?? '')), ['NORMAL_CLEARING','SUCCESS','ANSWER'], true) ? 'success' : 'secondary' ?>"><?= e(call_status_label($call['hangup_cause'] ?? null)) ?></span></td>
            <td><?php if (($call['analysis_status'] ?? '') === 'completed'): ?><span class="badge text-bg-success"><?= isset($call['overall_score']) ? (int) $call['overall_score'] . '/10' : 'Concluída' ?></span><?php elseif (($call['analysis_status'] ?? '') === 'failed'): ?><span class="badge text-bg-danger">Falhou</span><?php elseif (($call['analysis_status'] ?? '') === 'processing'): ?><span class="badge text-bg-info">Processando</span><?php else: ?><span class="badge text-bg-warning">Pendente</span><?php endif; ?></td>
            <td class="text-end text-nowrap"><?php if (($call['recording_status'] ?? '') !== 'discarded' && ($call['recording_status'] ?? '') !== 'unavailable'): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('ligacoes/' . $call['id'])) ?>"><i class="fa-solid fa-play me-1"></i>Ouvir</a><?php endif; ?><?php if (($call['analysis_status'] ?? '') === 'completed'): ?><a class="btn btn-sm btn-primary" href="<?= e(url('ligacoes/' . $call['id'])) ?>"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Insights</a><?php elseif (Auth::can('calls.reanalyze')): ?><form class="d-inline js-call-analysis-form" method="post" action="<?= e(url('ligacoes/' . $call['id'] . '/analisar')) ?>" data-external-id="<?= e($call['external_id'] ?? '') ?>"><?= Csrf::field() ?><input type="hidden" name="force" value="0"><button type="submit" class="btn btn-sm btn-primary"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Analisar</button></form><?php else: ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('ligacoes/' . $call['id'])) ?>">Detalhes</a><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$calls): ?><tr><td colspan="7" class="text-center py-5"><div class="text-muted mb-3"><i class="fa-solid fa-phone-slash fa-2x mb-3 d-block"></i>Nenhuma ligação sincronizada com estes filtros.</div><?php if (Auth::can('calls.manage')): ?><a class="btn btn-primary" href="<?= e(url('configuracoes/ligacoes')) ?>">Configurar ou sincronizar agora</a><?php endif; ?></td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <?php if ((int) $pagination['pages'] > 1): ?><div class="card-footer d-flex justify-content-between align-items-center"><span class="small text-muted"><?= (int) $pagination['total'] ?> ligação(ões)</span><div class="btn-group"><a class="btn btn-outline-secondary <?= (int)$pagination['page'] <= 1 ? 'disabled' : '' ?>" href="<?= e($pageUrl(max(1,(int)$pagination['page']-1))) ?>">Anterior</a><a class="btn btn-outline-secondary <?= (int)$pagination['page'] >= (int)$pagination['pages'] ? 'disabled' : '' ?>" href="<?= e($pageUrl(min((int)$pagination['pages'],(int)$pagination['page']+1))) ?>">Próxima</a></div></div><?php endif; ?>
</div>
<?php require __DIR__ . '/_analysis_script.php'; ?>
