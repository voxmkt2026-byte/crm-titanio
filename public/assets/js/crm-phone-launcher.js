(() => {
'use strict';
const launcher=document.getElementById('crmPhoneLauncher');if(!launcher)return;
const popupUrl=new URL(launcher.dataset.popupUrl,location.href);if(popupUrl.origin!==location.origin)return;
const notice=document.getElementById('crmPhoneNotice');let popup=null,timer=null,request=null,timeout=null;
const windowName='titanium-phone-'+popupUrl.pathname.replace(/[^a-z0-9]/gi,'_');
function notify(message){notice.textContent=message;notice.hidden=false;}
function openPhone(context={}){
 clearInterval(timer);clearTimeout(timeout);notice.hidden=true;
 try{
 popup=window.open('',windowName,'popup=yes,width=420,height=780,resizable=yes,scrollbars=yes');
 if(!popup){notify('Permita o popup deste site para abrir o telefone.');return;}
 if(popup.location.href==='about:blank')popup.location.replace(popupUrl.href);
 else if(popup.location.origin!==location.origin){notify('Feche a janela de telefone e tente novamente.');return;}
 popup.focus();
 request={type:'crm:phone:prepare',requestId:crypto.randomUUID(),leadId:Number(context.leadId)||null,phone:String(context.phone||'').slice(0,40)};
 const ping=()=>{if(popup&&!popup.closed)popup.postMessage({type:'crm:phone:ping'},location.origin);};
 timer=setInterval(ping,250);ping();timeout=setTimeout(()=>{clearInterval(timer);notify('O telefone está aberto. Se não carregar, confira sua sessão nessa janela.');},7000);
 }catch{notify('Não foi possível abrir o popup. Permita janelas deste site e tente novamente.');}
}
window.addEventListener('message',event=>{
 if(event.origin!==location.origin||event.source!==popup||event.data?.type!=='crm:phone:pong'||!request)return;
 clearInterval(timer);clearTimeout(timeout);popup.postMessage(request,location.origin);request=null;
});
document.addEventListener('click',event=>{
 const button=event.target.closest('[data-crm-phone]');if(!button)return;
 event.preventDefault();event.stopImmediatePropagation();
 openPhone({leadId:button.dataset.leadId,phone:button.dataset.phone});
},true);
window.CrmPhone={open:openPhone};
})();
