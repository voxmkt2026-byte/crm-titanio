<?php
$profilesByProvider = [];
foreach ($profiles as $profile) {
    $profilesByProvider[$profile['provider']] = $profile;
}
$syncCounts = is_array($syncState['last_counts'] ?? null) ? $syncState['last_counts'] : [];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Integrações de Ligações</h1>
        <p class="text-muted mb-0">Api4Com, armazenamento privado e análises automáticas associadas aos leads.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?= e(url('configuracoes')) ?>"><i class="fa-solid fa-arrow-left me-1"></i>Configurações</a>
        <a class="btn btn-outline-primary" href="<?= e(url('ligacoes')) ?>"><i class="fa-solid fa-list me-1"></i>Ver ligações</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([
        ['Ligações úteis', $metrics['visible_calls'] ?? 0, 'fa-phone-volume', 'primary'],
        ['Associadas a leads', $metrics['matched_calls'] ?? 0, 'fa-link', 'success'],
        ['Analisadas pela IA', $metrics['analyzed_calls'] ?? 0, 'fa-wand-magic-sparkles', 'info'],
        ['Nota média', number_format((float) ($metrics['average_score'] ?? 0), 1, ',', '.') . '/10', 'fa-star', 'warning'],
    ] as [$label, $value, $icon, $color]): ?>
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm"><div class="card-body">
            <div class="text-<?= e($color) ?> mb-2"><i class="fa-solid <?= e($icon) ?>"></i></div>
            <div class="h4 mb-1"><?= e($value) ?></div><div class="small text-muted"><?= e($label) ?></div>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card mb-4 border-primary-subtle">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong><i class="fa-solid fa-arrows-rotate me-2"></i>Sincronização automática</strong>
        <?php $state = (string) ($syncState['status'] ?? 'idle'); ?>
        <span class="badge text-bg-<?= $state === 'failed' ? 'danger' : ($state === 'running' ? 'warning' : 'success') ?>">
            <?= e($state === 'failed' ? 'Com falha' : ($state === 'running' ? 'Em execução' : 'Disponível')) ?>
        </span>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-lg-5">
                <div class="small text-muted">Última sincronização</div>
                <strong><?= !empty($syncState['last_success_at']) ? e(format_datetime($syncState['last_success_at'])) : 'Ainda não executada' ?></strong>
                <?php if ($syncCounts): ?><div class="small text-muted mt-1"><?= (int) ($syncCounts['synced'] ?? 0) ?> localizada(s) · <?= (int) ($syncCounts['processed'] ?? 0) ?> processada(s)</div><?php endif; ?>
                <?php if (!empty($syncState['last_error_code'])): ?><div class="small text-danger mt-1">Código: <?= e($syncState['last_error_code']) ?></div><?php endif; ?>
            </div>
            <div class="col-lg-7 d-flex flex-wrap gap-2 justify-content-lg-end">
                <form method="post" action="<?= e(url('configuracoes/ligacoes/sincronizar')) ?>" class="js-call-sync-form">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="pages" value="50"><input type="hidden" name="jobs" value="0"><input type="hidden" name="page" value="1">
                    <button class="btn btn-primary" <?= empty($configured['api4com']) ? 'disabled' : '' ?>><i class="fa-solid fa-rotate me-1"></i>Sincronizar agora</button>
                </form>
                <a class="btn btn-outline-success" href="<?= e(url('ligacoes?status=pending')) ?>"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Ver análises pendentes</a>
                <form method="post" action="<?= e(url('configuracoes/ligacoes/importar-antigas')) ?>" onsubmit="return confirm('Importar análises existentes da pasta ligacao e associá-las aos leads?');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="pages" value="50">
                    <button class="btn btn-outline-primary" <?= empty($configured['api4com']) ? 'disabled' : '' ?>><i class="fa-solid fa-clock-rotate-left me-1"></i>Importar análises antigas</button>
                </form>
            </div>
        </div>
        <?php if (empty($configured['api4com'])): ?><div class="alert alert-warning mt-3 mb-0">Salve o token da Api4Com abaixo para habilitar a sincronização.</div><?php endif; ?>
        <?php if (($metrics['pending_calls'] ?? 0) > 0 || ($metrics['failed_calls'] ?? 0) > 0): ?>
        <div class="small mt-3"><span class="badge text-bg-warning"><?= (int) ($metrics['pending_calls'] ?? 0) ?> pendente(s)</span> <span class="badge text-bg-danger"><?= (int) ($metrics['failed_calls'] ?? 0) ?> com falha</span></div>
        <?php endif; ?>
    </div>
</div>

<?php foreach ([
    'api4com' => ['Api4Com', 'https://api.api4com.com/api/v1'],
    'gemini' => ['Gemini', 'https://generativelanguage.googleapis.com/v1beta'],
    'openrouter' => ['OpenRouter', 'https://openrouter.ai/api/v1'],
] as $provider => $meta): ?>
<?php $profile = $profilesByProvider[$provider] ?? []; ?>
<form method="post" action="<?= e(url('configuracoes/ligacoes/salvar')) ?>" class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong><?= e($meta[0]) ?></strong>
        <span class="badge text-bg-<?= !empty($configured[$provider]) ? 'success' : 'secondary' ?>"><?= !empty($configured[$provider]) ? 'Configurado' : 'Não configurado' ?></span>
    </div>
    <div class="card-body">
        <?= Csrf::field() ?><input type="hidden" name="provider" value="<?= e($provider) ?>">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">URL HTTPS</label><input class="form-control" name="base_url" value="<?= e($profile['base_url'] ?? $meta[1]) ?>" required></div>
            <?php if ($provider !== 'api4com'): ?>
            <div class="col-md-6"><label class="form-label">Modelo com entrada de áudio</label><input class="form-control" name="model" value="<?= e($profile['model'] ?? '') ?>" required></div>
            <?php endif; ?>
            <div class="col-md-6"><label class="form-label">Token / chave</label><input type="password" autocomplete="new-password" class="form-control" name="secret" placeholder="Vazio mantém o token atual"></div>
            <?php if ($provider !== 'api4com'): ?>
            <div class="col-md-3"><label class="form-label">Limite do áudio (bytes)</label><input type="number" class="form-control" name="max_audio_bytes" min="1048576" max="104857600" value="<?= e($profile['max_audio_bytes'] ?? 14680064) ?>"></div>
            <div class="col-md-3 d-flex align-items-end pb-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="active-<?= e($provider) ?>" <?= !empty($profile['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="active-<?= e($provider) ?>">Usar este provedor</label></div></div>
            <?php else: ?>
            <div class="col-md-3"><label class="form-label">Retenção em dias</label><input type="number" class="form-control" name="retention_days" min="0" max="3650" value="<?= e($settings['calls_audio_retention_days'] ?? 365) ?>"><div class="form-text">0 mantém permanentemente.</div></div>
            <?php endif; ?>
            <div class="col-md-6"><label class="form-label">Sua senha atual</label><input type="password" class="form-control" name="current_password" required></div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between gap-2">
        <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Salvar <?= e($meta[0]) ?></button>
        <button class="btn btn-outline-secondary" formaction="<?= e(url('configuracoes/ligacoes/testar/' . $provider)) ?>" formmethod="post" formnovalidate><i class="fa-solid fa-plug-circle-check me-1"></i>Testar</button>
    </div>
</form>
<?php endforeach; ?>

<script>
document.querySelectorAll('.js-call-sync-form').forEach(function (form) {
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        const button = form.querySelector('button');
        const original = button.innerHTML;
        const maximum = Math.max(1, Math.min(100, Number(form.querySelector('[name="pages"]')?.value || 50)));
        let total = 0;
        button.disabled = true;
        try {
            for (let page = 1; page <= maximum; page++) {
                button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Página ' + page + ' de ' + maximum;
                const data = new FormData(form);
                data.set('page', String(page));
                const response = await fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
                const text = await response.text();
                let payload;
                try { payload = JSON.parse(text); } catch (_) { throw new Error('Resposta inválida do servidor na página ' + page + '.'); }
                if (!response.ok || !payload.success) throw new Error(payload.message || 'Falha ao sincronizar a página ' + page + '.');
                total += Number(payload.synced || 0);
                if (!payload.has_more) break;
            }
            button.innerHTML = '<i class="fa-solid fa-check me-1"></i>' + total + ' ligação(ões) atualizada(s)';
            window.setTimeout(function () { window.location.reload(); }, 900);
        } catch (error) {
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger mt-3 mb-0';
            alert.textContent = error.message || 'A sincronização não pôde ser concluída.';
            form.closest('.card-body')?.appendChild(alert);
            button.disabled = false;
            button.innerHTML = original;
        }
    });
});
</script>
