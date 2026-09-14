<?php declare(strict_types=1); ?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>Telefone · Titanium CRM</title><link rel="stylesheet" href="<?= e(asset('css/crm-phone.css')) ?>"></head><body>
<main id="crmPhone" data-base="<?= e($phoneBaseUrl) ?>" data-csrf="<?= e($phoneCsrf) ?>">
<header class="phone-header"><span class="brand-mark" aria-hidden="true">☎</span><div><small>TITANIUM CRM</small><h1>Telefone</h1></div><button id="phoneClose" type="button" title="Fechar telefone" aria-label="Fechar telefone">×</button></header>
<div class="phone-body">
<label class="field-label" for="phoneAccount">Linha e ramal</label><select id="phoneAccount" disabled><option>Carregando linhas…</option></select>
<p id="phoneSetupNotice" class="notice" role="status" hidden>Para ativar o telefone, configure o token da linha e atribua um ramal ao seu usuário. Você já pode preparar o número abaixo.<?php if(Auth::can('calls.manage')): ?> <a href="<?= e(url('configuracoes/ligacoes')) ?>" target="_blank" rel="noopener">Configurar linha e ramal ↗</a><?php endif; ?></p>
<section class="contact"><span id="phoneAvatar" aria-hidden="true">☎</span><small>LIGAR PARA</small><h2 id="phoneContactName">Discagem manual</h2><p id="phoneContactInfo">Escolha sua linha para começar.</p><a id="phoneContactLink" target="_blank" rel="noopener" hidden>Ver cadastro do lead ↗</a></section>
<section id="webphonePanel" data-phone-state="disabled" aria-label="Controles do telefone">
<div class="call-status"><span class="status-dot" aria-hidden="true"></span><strong id="webphoneStatus" role="status" aria-live="polite">Telefone desativado</strong><span id="callTimer">00:00</span></div>
<p id="phoneFinished" role="status" hidden></p>
<label class="field-label" id="phoneLeadChoiceLabel" for="phoneLeadChoice" hidden>Este número pertence a mais de um lead. Escolha o contato:</label><select id="phoneLeadChoice" hidden></select>
<label class="field-label" for="phoneContactNumber" id="phoneContactNumberLabel" hidden>Número do contato</label><select id="phoneContactNumber" hidden></select>
<label class="field-label" for="webphoneNumber">Número com DDD</label><div class="number-field"><input id="webphoneNumber" type="tel" inputmode="tel" maxlength="24" autocomplete="off" placeholder="(11) 99999-9999" aria-describedby="webphoneError"><button id="phoneBackspace" type="button" title="Apagar último dígito" aria-label="Apagar último dígito">⌫</button></div>
<p id="webphoneError" role="alert" aria-live="assertive"></p>
<button id="activatePhoneButton" type="button" class="activate">Ativar telefone e microfone</button>
<div id="dialpad" class="phone-keypad" aria-label="Teclado de discagem">
<?php foreach (['1','2','3','4','5','6','7','8','9','*','0','#'] as $digit): ?><button type="button" data-digit="<?= e($digit) ?>" aria-label="<?= e($digit) ?>" disabled><?= e($digit) ?></button><?php endforeach; ?>
</div>
<div class="primary-controls"><button id="callButton" type="button" class="dial" disabled>Ligar</button><button id="hangupButton" type="button" class="end" disabled>Encerrar</button></div>
<div class="secondary-controls"><button id="muteButton" type="button" aria-pressed="false" title="Silenciar ou ativar microfone" disabled>Silenciar</button><button id="phoneKeypadToggle" type="button" aria-expanded="true" title="Mostrar ou ocultar teclado">Teclado</button><button id="phoneHold" type="button" aria-pressed="false" title="Espera ou retomada" disabled>Espera</button><button id="phoneTransfer" type="button" title="Transferir para ramal" disabled>Transferir</button></div>
</section>
<p id="phoneNotice" class="notice" role="status" aria-live="polite"></p><button id="phoneRecover" class="recover" type="button" hidden>Conferir tentativa pendente</button><button id="phoneRecoverEnd" class="recover" type="button" hidden>Encerrar tentativa pendente</button>
<footer>Mantenha esta janela aberta durante a chamada.</footer>
<?php require __DIR__ . '/_copilot.php'; ?>
</div></main>
<dialog id="phoneTransferDialog" aria-labelledby="phoneTransferTitle"><form id="phoneTransferForm"><h2 id="phoneTransferTitle">Transferir chamada</h2><p>Informe o ramal de destino nesta linha.</p><label for="phoneExtension">Ramal</label><input id="phoneExtension" list="phoneExtensions" inputmode="numeric" pattern="[0-9]{1,10}" maxlength="10" required autocomplete="off"><datalist id="phoneExtensions"></datalist><p id="phoneTransferNotice" role="status"></p><div class="transfer-actions"><button id="phoneTransferCancel" type="button">Cancelar</button><button id="phoneTransferConfirm" type="submit" class="dial">Transferir</button></div></form></dialog>
<script src="<?= e($phoneBaseUrl.'/recurso/libwebphone.js') ?>" defer></script>
<script src="<?= e(asset('js/crm-phone-popup.js')) ?>" defer></script>
<script type="module" src="<?= e($phoneBaseUrl.'/recurso/webphone.js') ?>"></script>
<?php if (Auth::can('calls.ai')): ?><link rel="stylesheet" href="<?= e(asset('css/copilot.css')) ?>"><script type="module" src="<?= e(asset('js/crm-copilot.mjs')) ?>"></script><?php endif; ?>
</body></html>
