(() => {
'use strict';
const root=document.getElementById('crmPhone');if(!root)return;
const base=root.dataset.base.replace(/\/$/,''),csrf=root.dataset.csrf;
const byId=id=>document.getElementById(id);
const account=byId('phoneAccount'),input=byId('webphoneNumber'),notice=byId('phoneNotice');
let context=null,loadingContext=false,contextVersion=0,attemptId=null,requestId=null,uncertain=false,sessionValid=true;
let finishing=false,startedAt=null,hadCall=false,previousState='disabled',preparedRequest=null,refreshing=false;
let registeredExtension='',registrationChanged=false;
let confirmation=null,endingAttempt=null;
function lineReady(){return account.selectedOptions[0]?.dataset.ready==='1';}
const terminal=['ended','failed','completed','cancelled'];
function notify(message,error=false){notice.textContent=message;notice.classList.toggle('is-error',error);}
function busy(){return Boolean(window.VoxPhone?.busy()||attemptId||uncertain||finishing||loadingContext||endingAttempt);}
function lock(){
 const live=window.VoxPhone?.getState();
 account.disabled=busy()||!sessionValid||account.options.length===0;
 byId('phoneContactNumber').disabled=busy();
 byId('phoneLeadChoice').disabled=busy();
 byId('phoneBackspace').disabled=busy()||!sessionValid;
 const blocked=uncertain||!sessionValid||finishing||loadingContext||Boolean(endingAttempt);
 const editable=!blocked&&!busy()&&(!live||['disabled','error','ready'].includes(live.state));
 input.disabled=!editable;
 byId('dialpad').querySelectorAll('[data-digit]').forEach(key=>key.disabled=!(editable||(live?.state==='active'&&sessionValid)));
 byId('phoneSetupNotice').hidden=lineReady();
 if(live){
  byId('activatePhoneButton').disabled=blocked||busy()||!lineReady()||!['disabled','error'].includes(live.state);
  byId('callButton').disabled=blocked||busy()||live.state!=='ready';
  input.disabled=blocked||busy()||!['disabled','error','ready'].includes(live.state);
 }
 byId('phoneRecover').hidden=!uncertain;
 byId('phoneRecoverEnd').hidden=!uncertain||!attemptId;
 byId('phoneRecoverEnd').disabled=Boolean(endingAttempt)||finishing||live?.state==='ending';
 byId('phoneHold').disabled=!live?.canHold||!sessionValid;
 byId('phoneTransfer').disabled=!live?.canTransfer||!sessionValid;
}
async function api(path,data=null){
 const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),22000);
 try{
 const response=await fetch(base+'/'+path,{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal,
 headers:data?{'Content-Type':'application/json','Accept':'application/json'}:{'Accept':'application/json'},
 ...(data?{body:JSON.stringify({...data,csrf_token:csrf}),keepalive:path==='encerrar'}:{})});
 if([401,403,419].includes(response.status)){sessionValid=false;lock();}
 let payload;try{payload=await response.json();}catch{throw Error('O servidor não conseguiu responder. Confira sua sessão e tente novamente.');}
 if(!response.ok||payload.ok!==true){const error=Error(payload?.error?.message||'Não foi possível concluir a operação.');error.code=payload?.error?.code;error.status=response.status;throw error;}
 return payload.data;
 }finally{clearTimeout(timeout);}
}
function releaseAttempt(){attemptId=null;requestId=null;uncertain=false;lock();}
function confirmAttempt(){
 if(!attemptId)return Promise.resolve({ended:false});
 const id=attemptId;
 if(confirmation?.id===id)return confirmation.promise;
 const promise=api('conferir',{attempt_id:id})
  .then(data=>({ended:id===attemptId&&data.ended===true}))
  .finally(()=>{if(confirmation?.id===id)confirmation=null;});
 confirmation={id,promise};return promise;
}
function finishConfirmedAttempt(){
 // The server has already verified the end. Replay only its idempotent local
 // endpoint so the webphone can finish the same operation and emit its event.
 if(window.VoxPhone?.busy()){byId('hangupButton').click();return;}
 releaseAttempt();notify('Encerramento confirmado pela operadora.');
}
function clearContact(){
 window.dispatchEvent(new CustomEvent('crm:phone:context'));
 context=null;byId('phoneContactName').textContent='Discagem manual';byId('phoneContactInfo').textContent='';
 byId('phoneAvatar').textContent='☎';byId('phoneContactLink').hidden=true;
 byId('phoneContactNumber').hidden=true;byId('phoneContactNumberLabel').hidden=true;
 byId('phoneLeadChoice').hidden=true;byId('phoneLeadChoiceLabel').hidden=true;
}
function renderContact(data){
 window.dispatchEvent(new CustomEvent('crm:phone:context'));
 context=data;input.value=data.phone||'';
 byId('phoneContactName').textContent=data.name||'Discagem manual';
 byId('phoneContactInfo').textContent=data.lead_id?'Lead #'+data.lead_id:'Número informado manualmente';
 byId('phoneAvatar').textContent=data.lead_id?(data.name||'?').trim().slice(0,1).toUpperCase():'☎';
 const link=byId('phoneContactLink');link.hidden=!data.lead_id;
 if(data.lead_id)link.href=base.replace(/\/telefonia$/,'')+'/leads/'+encodeURIComponent(data.lead_id);
 const select=byId('phoneContactNumber');select.replaceChildren();
 for(const phone of data.phones||[])select.add(new Option(phone.label+' · '+phone.number,phone.number));
 select.value=data.phone||'';select.hidden=select.options.length<2;byId('phoneContactNumberLabel').hidden=select.hidden;
 const choice=byId('phoneLeadChoice');choice.replaceChildren(new Option('Selecione o lead…',''));
 for(const lead of data.candidates||[])choice.add(new Option(lead.name+' · #'+lead.id,String(lead.id)));
 choice.hidden=choice.options.length<3;byId('phoneLeadChoiceLabel').hidden=choice.hidden;
}
async function prepare(message){
 if(message.requestId&&message.requestId===preparedRequest)return;
 preparedRequest=message.requestId;
 if(busy()){notify('Há uma chamada ou operação em andamento. Encerre-a antes de trocar de contato.',true);return;}
 const version=++contextVersion;loadingContext=true;lock();
 try{
 const params=new URLSearchParams();
 if(Number.isSafeInteger(Number(message.leadId))&&Number(message.leadId)>0)params.set('lead_id',String(message.leadId));
 if(message.phone)params.set('phone',String(message.phone).slice(0,40));
 if(!params.size){clearContact();input.value='';return;}
 const data=await api('contexto?'+params);
 if(version!==contextVersion)return;
 renderContact(data);notify('Confira o número. A ligação só começa ao clicar em Ligar.');
 }catch(error){clearContact();input.value='';notify(error.message,true);}
 finally{loadingContext=false;lock();}
}
window.addEventListener('message',event=>{
 if(event.origin!==location.origin||!event.source||!event.data||typeof event.data!=='object')return;
 if(event.data.type==='crm:phone:ping'){event.source.postMessage({type:'crm:phone:pong'},event.origin);return;}
 if(event.data.type==='crm:phone:prepare')void prepare(event.data);
});
window.VoxPhoneBridge={request:async(url,payload)=>{
 if(url==='api/webphone-config.php'){
  if(!sessionValid||uncertain||loadingContext)throw Error('Telefone indisponível.');
  const config=await api('config?account='+encodeURIComponent(account.value));
  registeredExtension=String(config.extension||'');return config;
 }
 if(url==='api/dial.php'){
  if(!sessionValid||uncertain||attemptId||loadingContext)throw Error('Há uma operação pendente.');
  requestId=requestId||crypto.randomUUID();
  try{
   const verified=await api('contexto?'+new URLSearchParams({phone:payload.phone,...(context?.lead_id?{lead_id:context.lead_id}:{})}));
   renderContact(verified);
   if((verified.candidates||[]).length>1){const error=Error('Escolha o lead correspondente antes de ligar.');error.status=422;throw error;}
   const result=await api('discar',{account:account.value,phone:verified.phone,lead_id:verified.lead_id,request_id:requestId,registered_extension:registeredExtension});
   attemptId=result.attempt_id;uncertain=!attemptId;
   if(!attemptId)throw Error('Tentativa sem confirmação.');
   window.dispatchEvent(new CustomEvent('crm:phone:attempt',{detail:{attemptId}}));
   return result;
  }catch(error){
   // A missing HTTP response cannot prove that the provider did not dial.
   registrationChanged=error.code==='EXTENSION_CHANGED';
   uncertain=!registrationChanged&&!(error.status>=400&&error.status<500&&error.status!==409);
   if(!uncertain)requestId=null;
   notify(error.message||'Confira a tentativa antes de tentar novamente.',true);lock();throw error;
  }
 }
 if(url==='api/hangup.php'){
  if(!attemptId){uncertain=true;lock();throw Error('Confira a tentativa pendente.');}
  const id=attemptId;
  try{
   const data=await api('encerrar',{attempt_id:id});
   if(attemptId!==id)return {ended:false};
   if(data.ended!==true)throw Error('O encerramento ainda não foi confirmado.');
   releaseAttempt();return data;
  }catch(error){if(attemptId===id){uncertain=true;notify('Conferindo o encerramento na operadora…');lock();}throw error;}
 }
 throw Error('Operação de telefonia indisponível.');
},confirmEnded:async()=>{
 const data=await confirmAttempt();
 if(data.ended){releaseAttempt();notify('Encerramento confirmado pela operadora.');}
 return data;
}};
async function refreshState(){
 if(refreshing)return;refreshing=true;
 const requestedAttempt=attemptId;
 try{
 const result=await api('estado'+(attemptId?'?attempt_id='+encodeURIComponent(attemptId):''));
 if(attemptId!==requestedAttempt)return;
 if(result.can_dial===false){sessionValid=false;notify('Sua permissão de discagem foi alterada. Você ainda pode encerrar sua tentativa.',true);}
 const saved=result.attempt||((result.attempt_id||result.state)?result:null);
 if(saved&&!terminal.includes(saved.state||saved.status)){
  if(!attemptId)attemptId=saved.attempt_id||saved.id;
  if(!window.VoxPhone?.busy()){uncertain=true;notify('Existe uma tentativa pendente. Confira ou encerre antes de ligar novamente.',true);}
  if(uncertain&&(await confirmAttempt()).ended)finishConfirmedAttempt();
 }else if(saved?.state==='ended'&&attemptId&&window.VoxPhone?.busy()){
  finishConfirmedAttempt();
 }else if(!window.VoxPhone?.busy()){
  releaseAttempt();
 }
 }catch(error){notify(error.message,true);}
 finally{refreshing=false;lock();}
}
window.addEventListener('vox:phone-state',event=>{
 const state=event.detail;byId('phoneHold').textContent=state.holdPending?'Aguarde…':state.held?'Retomar':'Espera';
 byId('phoneHold').setAttribute('aria-pressed',String(state.held));
 if(['dialing','ringing','active'].includes(state.state)){hadCall=true;byId('phoneFinished').hidden=true;}
 if(state.state==='active'&&startedAt===null)startedAt=Date.now();
 // Controller default keeps DTMF off for standalone. CRM enables only established call keys.
 if(state.state==='active')byId('dialpad').querySelectorAll('[data-digit]').forEach(key=>key.disabled=false);
 if(previousState==='active'&&state.state!=='active'&&state.state!=='ending')byId('phoneFinished').textContent='Chamada encerrada';
 previousState=state.state;lock();
 if(registrationChanged&&state.state==='ready')queueMicrotask(()=>{
  if(window.VoxPhone?.busy())return;
  const number=input.value;registrationChanged=false;registeredExtension='';
  window.VoxPhone.setAccount(account.value==='api4com_2'?'2':'1',true);input.value=number;
  notify('Seu ramal foi alterado. Ative o telefone novamente antes de ligar.',true);
 });
});
window.addEventListener('vox:call-ended',async()=>{
 if(!hadCall&&!attemptId){lock();return;}
 const seconds=startedAt===null?0:Math.floor((Date.now()-startedAt)/1000);
 byId('phoneFinished').textContent='Chamada encerrada · '+String(Math.floor(seconds/60)).padStart(2,'0')+':'+String(seconds%60).padStart(2,'0');
 byId('phoneFinished').hidden=false;startedAt=null;hadCall=false;
 if(attemptId&&!finishing){
  finishing=true;lock();
  try{await api('evento',{attempt_id:attemptId,event:'ended'});releaseAttempt();}
  catch(error){
   uncertain=true;
   try{if((await confirmAttempt()).ended)finishConfirmedAttempt();else notify(error.message,true);}
   catch(confirmationError){notify(confirmationError.message,true);}
  }
  finally{finishing=false;lock();}
 }
});
input.addEventListener('input',()=>{if(!window.VoxPhone?.busy())clearContact();});
byId('phoneBackspace').addEventListener('click',()=>{if(!busy()&&sessionValid){const end=input.selectionEnd??input.value.length;const start=input.selectionStart??end;input.setRangeText('',start===end?Math.max(0,start-1):start,end,'end');clearContact();input.focus();}});
byId('phoneContactNumber').addEventListener('change',()=>{if(!busy())input.value=byId('phoneContactNumber').value;});
byId('phoneLeadChoice').addEventListener('change',()=>{
 if(!busy()&&byId('phoneLeadChoice').value)void prepare({leadId:byId('phoneLeadChoice').value,phone:input.value});
});
byId('dialpad').addEventListener('click',event=>{
 const key=event.target.closest('[data-digit]');if(!key)return;
 if(window.VoxPhone?.getState().state==='active'){event.preventDefault();event.stopImmediatePropagation();window.VoxPhone.sendDigit(key.dataset.digit);}
 else if(!busy()&&!input.disabled){event.preventDefault();event.stopImmediatePropagation();const digit=key.dataset.digit;if(input.value.length>=input.maxLength&&input.selectionStart===input.selectionEnd)return;input.setRangeText(digit,input.selectionStart??input.value.length,input.selectionEnd??input.value.length,'end');clearContact();input.focus();}
},true);
byId('phoneKeypadToggle').addEventListener('click',()=>{
 const keypad=byId('dialpad');keypad.hidden=!keypad.hidden;byId('phoneKeypadToggle').setAttribute('aria-expanded',String(!keypad.hidden));
});
byId('phoneHold').addEventListener('click',async()=>{const result=await window.VoxPhone.toggleHold();notify(result.message,!result.success);});
const transfer=byId('phoneTransferDialog');
byId('phoneTransfer').addEventListener('click',async()=>{
 if(!window.VoxPhone?.getState().canTransfer)return;
 byId('phoneExtension').value='';byId('phoneTransferNotice').textContent='';byId('phoneExtensions').replaceChildren();transfer.showModal();
 try{const data=await api('ramais?account='+encodeURIComponent(account.value));for(const ext of data.extensions||[])byId('phoneExtensions').append(new Option(ext.name||ext.number,ext.number));}
 catch{byId('phoneTransferNotice').textContent='Lista indisponível. Digite um ramal conhecido desta linha.';}
});
function closeTransfer(){if(!window.VoxPhone?.getState().transferPending)transfer.close();}
byId('phoneTransferCancel').addEventListener('click',closeTransfer);
transfer.addEventListener('cancel',event=>{if(window.VoxPhone?.getState().transferPending)event.preventDefault();});
byId('phoneTransferForm').addEventListener('submit',async event=>{
 event.preventDefault();byId('phoneTransferConfirm').disabled=true;byId('phoneTransferNotice').textContent='Aguardando confirmação do destino…';
 try{const result=await window.VoxPhone.transfer(byId('phoneExtension').value.trim());byId('phoneTransferNotice').textContent=result.message;if(result.success)transfer.close();}
 finally{byId('phoneTransferConfirm').disabled=false;}
});
account.addEventListener('change',()=>{
 if(busy())return;
 const number=input.value;
 if(!window.VoxPhone?.setAccount(account.value==='api4com_2'?'2':'1'))return;
 registeredExtension='';
 input.value=number;notify(lineReady()?'Ative o telefone para conectar esta linha.':'Esta linha precisa de token e ramal atribuídos ao seu usuário.');lock();
});
byId('phoneRecover').addEventListener('click',async()=>{
 const button=byId('phoneRecover');button.disabled=true;notify('Conferindo o encerramento na operadora…');
 try{
  if(!attemptId)await refreshState();
  if(!attemptId){notify('Nenhuma tentativa pendente.');return;}
  const result=await confirmAttempt();
  if(result.ended){
   finishConfirmedAttempt();
  }else notify('A operadora ainda não confirmou o fim nos registros recentes. Aguarde ou sincronize o histórico antes de conferir novamente.',true);
 }catch(error){notify(error.message,true);}finally{button.disabled=false;lock();}
});
byId('phoneRecoverEnd').addEventListener('click',async()=>{
 if(!attemptId||endingAttempt)return;
 const id=attemptId;endingAttempt=id;lock();notify('Encerrando a tentativa pendente…');
 try{
  const handled=await window.VoxPhone?.hangup();
  if(!handled&&attemptId===id){
   // After a reload only the CRM reservation remains; use the same bridge
   // validation and provider confirmation as a live controller operation.
   try{await window.VoxPhoneBridge.request('api/hangup.php');}
   catch(error){if(attemptId===id&&!(await window.VoxPhoneBridge.confirmEnded()).ended)throw error;}
  }
  if(!attemptId)notify('Tentativa encerrada.');
 }catch(error){if(attemptId===id)notify(error.message,true);}
 finally{endingAttempt=null;lock();}
});
function closePhone(){if(window.VoxPhone?.busy()||attemptId||uncertain){notify('Encerre a chamada antes de fechar o telefone.',true);return;}window.close();}
byId('phoneClose').addEventListener('click',closePhone);
window.addEventListener('beforeunload',event=>{if(window.VoxPhone?.busy()||attemptId||uncertain){event.preventDefault();event.returnValue='';}});
async function initialize(){
 try{
 const data=await api('contas');account.replaceChildren();
 for(const line of data.accounts||[]){
  const option=new Option(line.name+' · '+(line.extension?'Ramal '+line.extension:'Sem ramal'),line.key);
  option.dataset.ready=line.configured&&line.extension?'1':'0';account.add(option);
 }
 const eligible=[...account.options].find(option=>option.dataset.ready==='1');
 if(eligible){account.value=eligible.value;window.VoxPhone?.setAccount(account.value==='api4com_2'?'2':'1');}
 else{notify('Solicite ao administrador a configuração da conta e do seu ramal.',true);}
 await refreshState();
 }catch(error){sessionValid=false;notify(error.message,true);}
 finally{lock();}
}
window.addEventListener('DOMContentLoaded',()=>void initialize(),{once:true});
setInterval(()=>{if(!finishing)void refreshState();},15000);
})();
