<?php if (Auth::can('calls.dial')): ?>
<button id="crmPhoneLauncher" type="button" data-crm-phone data-popup-url="<?= e(url('telefonia')) ?>" aria-label="Abrir telefone do CRM"><i class="fa-solid fa-phone" aria-hidden="true"></i><span aria-hidden="true">Telefone</span></button>
<p id="crmPhoneNotice" role="status" aria-live="polite" hidden></p>
<style>
/* One compact action column: phone above AI, including mobile safe areas. */
#crmPhoneLauncher,#crmPhoneNotice,#tcAiFab,#tcAiPanel{
    --crm-action-edge:24px;
    --crm-safe-right:env(safe-area-inset-right,0px);
    --crm-safe-bottom:env(safe-area-inset-bottom,0px);
}
#crmPhoneLauncher{
    position:fixed;right:calc(var(--crm-action-edge) + 4px + var(--crm-safe-right));
    bottom:calc(var(--crm-action-edge) + 68px + var(--crm-safe-bottom));z-index:1035;
    display:flex;align-items:center;justify-content:center;width:48px;height:48px;padding:0;
    border:1px solid #ffffff30;border-radius:50%;background:#0f766e;color:#fff;
    box-shadow:0 3px 10px #0f766e26;font:600 18px system-ui;cursor:pointer;touch-action:manipulation;
    transition:background .15s ease,box-shadow .15s ease,transform .15s ease;
}
#crmPhoneLauncher span{
    position:absolute;right:calc(100% + 10px);padding:6px 10px;border-radius:7px;
    background:#1e293b;color:#fff;font:500 12px/1.4 system-ui;white-space:nowrap;
    opacity:0;visibility:hidden;pointer-events:none;transition:opacity .15s ease;
}
#crmPhoneLauncher:focus-visible{outline:2px solid #0f766e;outline-offset:3px}
#crmPhoneLauncher:focus-visible span{opacity:1;visibility:visible}
@media(hover:hover){
    #crmPhoneLauncher:hover{background:#115e59;box-shadow:0 4px 12px #0f766e30;transform:translateY(-1px)}
    #crmPhoneLauncher:hover span{opacity:1;visibility:visible}
}
#crmPhoneLauncher:active{background:#134e4a;transform:scale(.96)}
#crmPhoneNotice{
    position:fixed;right:calc(var(--crm-action-edge) + var(--crm-safe-right));
    bottom:calc(var(--crm-action-edge) + 128px + var(--crm-safe-bottom));z-index:1090;
    max-width:min(340px,calc(100vw - 48px - var(--crm-safe-right)));margin:0;padding:12px;
    background:#fff7e8;border:1px solid #ead7b1;border-radius:9px;color:#7b5726;font:12px/1.5 system-ui;
}
#tcAiFab{
    right:calc(var(--crm-action-edge) + var(--crm-safe-right));
    bottom:calc(var(--crm-action-edge) + var(--crm-safe-bottom));
}
/* Keep the assistant usable without covering either action. */
#tcAiPanel{
    right:calc(var(--crm-action-edge) + var(--crm-safe-right));
    bottom:calc(var(--crm-action-edge) + 128px + var(--crm-safe-bottom));
    width:min(390px,calc(100vw - 32px - var(--crm-safe-right) - env(safe-area-inset-left,0px)));
    max-height:calc(100vh - var(--crm-action-edge) - 148px - var(--crm-safe-bottom) - env(safe-area-inset-top,0px));
    max-height:calc(100dvh - var(--crm-action-edge) - 148px - var(--crm-safe-bottom) - env(safe-area-inset-top,0px));
}
#tcAiPanel .tc-ai-messages{min-height:0}
#tcAiPanel textarea{min-width:0}
.crm-phone-action{white-space:nowrap}
.tc-global-search-phone{margin-left:auto;border:1px solid #dbe4f0;border-radius:7px;padding:5px 8px;background:#f5f9ff;color:#2864ae;font-size:11px}
@media(max-width:767px){
    #crmPhoneLauncher,#crmPhoneNotice,#tcAiFab,#tcAiPanel{--crm-action-edge:16px}
}
@media(prefers-reduced-motion:reduce){
    #crmPhoneLauncher,#crmPhoneLauncher span{transition:none}
    #crmPhoneLauncher:hover,#crmPhoneLauncher:active{transform:none}
}
</style>
<script src="<?= e(asset('js/crm-phone-launcher.js')) ?>" defer></script>
<?php endif; ?>
