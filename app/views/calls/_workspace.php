<?php
$workspaceHydrated=isset($callAccounts);
$callAccounts=$callAccounts??[
    'api4com'=>['key'=>'api4com','name'=>'Linha 1','color'=>'#2563eb','configured'=>false],
    'api4com_2'=>['key'=>'api4com_2','name'=>'Linha 2','color'=>'#7c3aed','configured'=>false],
];
$workspaceLeadId=(int)($workspaceLeadId??$lead['id']??0);
?>
<link rel="stylesheet" href="<?= e(asset('css/calls-workspace.css')) ?>">
<?php if(Auth::can('calls.dial')): ?><button type="button" class="cw-dial-button" data-crm-phone data-lead-id="<?= $workspaceLeadId ?>"><i class="fa-solid fa-phone" aria-hidden="true"></i> Discar número</button><?php endif; ?>
<section class="cw-launchers" aria-label="Contas de telefonia">
    <?php foreach ($callAccounts as $line): $color=preg_match('/^#[a-f0-9]{6}$/i',(string)$line['color'])?$line['color']:'#2563eb'; ?>
    <button type="button" class="cw-line-button" data-cw-account="<?= e($line['key']) ?>" data-cw-lead="<?= $workspaceLeadId ?>" style="--cw-line:<?= e($color) ?>" <?= !$line['configured']?'disabled':'' ?>>
        <span class="cw-line-icon" aria-hidden="true"><i class="fa-solid fa-phone-volume"></i></span>
        <span class="cw-line-copy"><strong data-cw-line-name><?= e($line['name']) ?></strong><small data-cw-line-status><?= $line['configured']?'Abrir central de ligações':($workspaceHydrated?'Não configurada':'Verificando configuração…') ?></small></span>
        <span class="cw-line-arrow" aria-hidden="true">↗</span>
    </button>
    <?php endforeach; ?>
</section>
<?php if(Auth::can('calls.manage')): ?><p class="cw-account-hint">Dois espaços independentes para sua equipe. <a href="<?= e(url('configuracoes/ligacoes')) ?>">Configurar linhas e tokens</a></p><?php endif; ?>
<dialog id="cw-dialog" class="cw-dialog" aria-labelledby="cw-title" data-api="<?= e(url('ligacoes')) ?>" data-hydrated="<?= $workspaceHydrated?'1':'0' ?>">
    <div class="cw-shell">
        <header class="cw-topbar">
            <div class="cw-brand-mark" aria-hidden="true"><i class="fa-solid fa-headset"></i></div>
            <div class="cw-brand-copy"><span class="cw-eyebrow">TITANIUM · CONVERSAS</span><h2 id="cw-title">Central de ligações</h2></div>
            <?php if(Auth::can('calls.dial')): ?><button type="button" class="cw-dial-button" data-crm-phone data-lead-id="<?= $workspaceLeadId ?>"><i class="fa-solid fa-phone" aria-hidden="true"></i> Discar número</button><?php endif; ?>
            <label class="cw-account-select"><span class="cw-sr-only">Conta de telefonia</span><select data-cw-switch><?php foreach($callAccounts as $line): ?><option value="<?= e($line['key']) ?>" <?= !$line['configured']?'disabled':'' ?>><?= e($line['name']) ?></option><?php endforeach; ?></select></label>
            <button class="cw-icon-button cw-mobile-history" type="button" data-cw-history-toggle aria-expanded="false" aria-label="Mostrar histórico">☰</button>
            <button class="cw-icon-button" type="button" data-cw-close aria-label="Fechar central de ligações">✕</button>
        </header>
        <div class="cw-body">
            <aside class="cw-sidebar" aria-label="Histórico de chamadas">
                <div class="cw-sidebar-heading"><h3>Histórico</h3><span data-cw-count class="cw-count">0</span></div>
                <p class="cw-muted cw-history-context" data-cw-context>Chamadas recentes desta linha</p>
                <form class="cw-search" data-cw-search><label class="cw-sr-only" for="cw-search">Buscar histórico pelo telefone</label><input id="cw-search" type="search" name="number" placeholder="Buscar por telefone" autocomplete="off"><button type="submit" aria-label="Buscar chamadas"><i class="fa-solid fa-magnifying-glass"></i></button></form>
                <div class="cw-sidebar-tools"><button type="button" class="cw-text-button" data-cw-reset>Ver histórico da linha</button><button type="button" class="cw-text-button" data-cw-refresh aria-label="Atualizar histórico">↻ Atualizar</button></div>
                <div class="cw-history-list" data-cw-history aria-live="polite"></div>
                <div class="cw-sidebar-footer"><span class="cw-status-dot"></span> Até 100 chamadas mais recentes</div>
            </aside>
            <main class="cw-main" data-cw-detail aria-label="Detalhes da ligação"><div class="cw-empty"><span class="cw-empty-icon">☎</span><h3>Uma conversa, todo o contexto</h3><p>Selecione uma ligação para ouvir, analisar e acompanhar os próximos passos.</p></div></main>
        </div>
        <div class="cw-toast" data-cw-notice role="status" aria-live="polite" hidden></div>
    </div>
</dialog>
<script src="<?= e(asset('js/calls-workspace.js')) ?>" defer></script>
