<?php
$profilesByProvider = [];
foreach ($profiles as $profile) {
    $profilesByProvider[$profile['provider']] = $profile;
}
$syncCounts = is_array($syncState['last_counts'] ?? null) ? $syncState['last_counts'] : [];
$accounts = $accounts ?? ['api4com'=>['key'=>'api4com','name'=>'Linha 1','color'=>'#2563eb','configured'=>!empty($configured['api4com'])], 'api4com_2'=>['key'=>'api4com_2','name'=>'Linha 2','color'=>'#7c3aed','configured'=>false]];
foreach ($accounts as $key=>$account) $configured[$key]=$account['configured'];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Integrações de Ligações</h1>
        <p class="text-muted mb-0">Api4Com, armazenamento privado e análises automáticas associadas aos leads.</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('configuracoes/copiloto')) ?>"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Copiloto de IA</a>
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

<div class="card mb-4"><div class="card-body">
    <h2 class="h5">Ramais do telefone do CRM</h2>
    <p class="small text-muted">Atribua um ramal existente por usuário e conta. A permissão “Realizar ligações pelo telefone do CRM” deve ser concedida nas permissões do usuário. O ramal é validado na conta ao salvar.</p>
    <?php if (empty($phoneReady)): ?>
    <div class="alert alert-warning">Telefonia indisponível: aplique a migração <code>database/sql/migration_native_phone.sql</code> após o backup.</div>
    <?php else: ?>
    <form id="nativePhoneMapping" action="<?= e(url('telefonia/ramal')) ?>" method="post" class="row g-2 align-items-end">
        <?= Csrf::field() ?>
        <div class="col-md-4"><label for="phoneMappingUser" class="form-label">Usuário</label><select id="phoneMappingUser" name="user_id" class="form-select" required><option value="">Selecione</option><?php foreach (($phoneUsers??[]) as $phoneUser): ?><option value="<?= (int)$phoneUser['id'] ?>"><?= e($phoneUser['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label for="phoneMappingAccount" class="form-label">Conta</label><select id="phoneMappingAccount" name="account" class="form-select" required><?php foreach ($accounts as $phoneAccount): ?><option value="<?= e($phoneAccount['key']) ?>"><?= e($phoneAccount['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label for="phoneMappingExtension" class="form-label">Ramal</label><input id="phoneMappingExtension" name="extension" class="form-control" inputmode="numeric" pattern="[0-9]{1,10}" maxlength="10" required></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Atribuir</button></div>
        <div class="col-12 small" id="nativePhoneMappingStatus" role="status" aria-live="polite"></div>
    </form>
    <?php if (!empty($phoneMappings)): ?><ul class="mt-3 mb-0 small"><?php foreach($phoneMappings as $mapping): ?><li><?= e($mapping['name']) ?> — <?= e($accounts[$mapping['provider']]['name']??$mapping['provider']) ?> — ramal <?= e(substr($mapping['external_key'],4)) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <script>
    document.getElementById('nativePhoneMapping').addEventListener('submit',async function(event){
        event.preventDefault();const button=this.querySelector('button');const status=document.getElementById('nativePhoneMappingStatus');button.disabled=true;status.textContent='Validando ramal…';
        try{const response=await fetch(this.action,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json'},body:new FormData(this)});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error?.message||'Não foi possível atribuir o ramal.');status.textContent='Ramal atribuído. Atualizando…';window.location.reload();}
        catch(error){status.textContent=error.message||'Falha ao atribuir ramal.';button.disabled=false;}
    });
    </script>
    <?php endif; ?>
</div></div>

<?php foreach ($accounts as $accountKey=>$account): ?>
<?php $syncState=$syncStates[$accountKey] ?? ($accountKey==='api4com'?$syncState:[]); $syncCounts=is_array($syncState['last_counts']??null)?$syncState['last_counts']:(json_decode((string)($syncState['last_counts']??''),true)?:[]); ?>
<div class="card mb-4 border-primary-subtle">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong><i class="fa-solid fa-arrows-rotate me-2"></i>Sincronização automática — <?= e($account['name']) ?></strong>
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
                    <input type="hidden" name="account" value="<?= e($accountKey) ?>">
                    <input type="hidden" name="pages" value="50"><input type="hidden" name="jobs" value="0"><input type="hidden" name="page" value="1">
                    <button class="btn btn-primary" <?= !$account['configured'] ? 'disabled' : '' ?>><i class="fa-solid fa-rotate me-1"></i>Sincronizar agora</button>
                </form>
                <a class="btn btn-outline-success" href="<?= e(url('ligacoes?status=pending')) ?>"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Ver análises pendentes</a>
                <?php if ($accountKey==='api4com'): ?><form method="post" action="<?= e(url('configuracoes/ligacoes/importar-antigas')) ?>" onsubmit="return confirm('Importar análises existentes da pasta ligacao e associá-las aos leads?');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="pages" value="50">
                    <button class="btn btn-outline-primary" <?= !$account['configured'] ? 'disabled' : '' ?>><i class="fa-solid fa-clock-rotate-left me-1"></i>Importar análises antigas</button>
                </form><?php endif; ?>
            </div>
        </div>
        <?php if (!$account['configured']): ?><div class="alert alert-warning mt-3 mb-0">Salve o token desta conta abaixo para habilitar a sincronização.</div><?php endif; ?>
        <?php if (($metrics['pending_calls'] ?? 0) > 0 || ($metrics['failed_calls'] ?? 0) > 0): ?>
        <div class="small mt-3">Total de todas as linhas: <span class="badge text-bg-warning"><?= (int) ($metrics['pending_calls'] ?? 0) ?> pendente(s)</span> <span class="badge text-bg-danger"><?= (int) ($metrics['failed_calls'] ?? 0) ?> com falha</span></div>
        <?php endif; ?>
    </div>
</div>

<?php endforeach; ?>
<?php foreach ([
    'api4com' => [$accounts['api4com']['name'], 'https://api.api4com.com/api/v1'],
    'api4com_2' => [$accounts['api4com_2']['name'], 'https://api.api4com.com/api/v1'],
    'gemini' => ['Gemini', 'https://generativelanguage.googleapis.com/v1beta'],
    'openrouter' => ['OpenRouter', 'https://openrouter.ai/api/v1'],
] as $provider => $meta): ?>
<?php $isCall=isset($accounts[$provider]); $profile = $profilesByProvider[$provider] ?? []; if ($isCall && isset($accountConfig)) $profile['base_url']=CallAccounts::forAccount($accountConfig,$provider)->get('api4com','base_url',$meta[1]); ?>
<form method="post" action="<?= e(url('configuracoes/ligacoes/salvar')) ?>" class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong><?= e($meta[0]) ?></strong>
        <span class="badge text-bg-<?= !empty($configured[$provider]) ? 'success' : 'secondary' ?>"><?= !empty($configured[$provider]) ? 'Configurado' : 'Não configurado' ?></span>
    </div>
    <div class="card-body">
        <?= Csrf::field() ?><input type="hidden" name="provider" value="<?= e($provider) ?>">
        <?php if ($isCall): ?><input type="hidden" name="account" value="<?= e($provider) ?>"><?php endif; ?>
        <div class="row g-3">
            <?php if ($isCall): ?>
            <div class="col-md-6"><label class="form-label">Nome da linha</label><input class="form-control" name="name" maxlength="60" required value="<?= e($accounts[$provider]['name']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Cor da linha</label><input class="form-control form-control-color" type="color" name="color" value="<?= e($accounts[$provider]['color']) ?>"></div>
            <?php endif; ?>
            <div class="col-md-6"><label class="form-label">URL HTTPS</label><input class="form-control" name="base_url" value="<?= e($profile['base_url'] ?? $meta[1]) ?>" required></div>
            <?php if (!$isCall): ?>
            <div class="col-md-6"><label class="form-label">Modelo com entrada de áudio</label><input class="form-control" name="model" value="<?= e($profile['model'] ?? '') ?>" required></div>
            <?php endif; ?>
            <div class="col-md-6"><label class="form-label">Token / chave</label><input type="password" autocomplete="new-password" class="form-control" name="secret" placeholder="Vazio mantém o token atual"></div>
            <?php if (!$isCall): ?>
            <div class="col-md-3"><label class="form-label">Limite do áudio (bytes)</label><input type="number" class="form-control" name="max_audio_bytes" min="1048576" max="104857600" value="<?= e($profile['max_audio_bytes'] ?? 14680064) ?>"></div>
            <div class="col-md-3 d-flex align-items-end pb-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="active-<?= e($provider) ?>" <?= !empty($profile['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="active-<?= e($provider) ?>">Usar este provedor</label></div></div>
            <?php else: ?>
            <div class="col-md-3"><label class="form-label">Retenção em dias (compartilhada)</label><input type="number" class="form-control" name="retention_days" min="0" max="3650" value="<?= e($settings['calls_audio_retention_days'] ?? 365) ?>"><div class="form-text">0 mantém permanentemente.</div></div>
            <?php endif; ?>
            <div class="col-md-6"><label class="form-label">Sua senha atual</label><input type="password" class="form-control" name="current_password" required></div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between gap-2">
        <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Salvar <?= e($meta[0]) ?></button>
        <button class="btn btn-outline-secondary" formaction="<?= e(url('configuracoes/ligacoes/testar/' . ($isCall?'api4com':$provider))) ?>" formmethod="post" formnovalidate <?= $isCall && empty($configured[$provider])?'disabled':'' ?>><i class="fa-solid fa-plug-circle-check me-1"></i><?= $isCall ? 'Testar API e armazenamento' : 'Testar' ?></button>
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
