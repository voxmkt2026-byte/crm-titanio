const panel=document.getElementById('copilotPanel');
if(panel)initializeCopilot();
function initializeCopilot(){
 const root=document.getElementById('crmPhone'),base=root.dataset.base.replace(/\/$/,'')+'/ia',csrf=root.dataset.csrf;
 const el=id=>document.getElementById(id),start=el('copilotStart'),stop=el('copilotStop'),consent=el('copilotConsent');
 let settings=null,attemptId=null,run=null,lastSuggestion='',dead=false,usedAttempt=null;
 const label={alto:'Alto',alta:'Alta',baixo:'Baixo',baixa:'Baixa',medio:'Médio',media:'Média',positivo:'Positivo',negativo:'Negativo',neutro:'Neutro',misto:'Misto',indefinido:'Sem evidência',unknown:'Sem evidência',muito_alta:'Muito alta',muito_baixa:'Muito baixa'};
 function status(text,error=false){el('copilotStatus').textContent=text;el('copilotStatus').classList.toggle('error',error);}
 function controls(){
  start.disabled=dead||!settings?.enabled||!settings?.configured||settings?.can_use===false||!consent.checked||!attemptId||usedAttempt===attemptId||window.VoxPhone?.getState().state!=='active'||!!run;
  stop.disabled=!run||run.stopping;consent.disabled=!!run;
  el('copilotBadge').textContent=run?(run.stopping?'Salvando…':'Ativa'):usedAttempt===attemptId&&attemptId?'Finalizada':settings?.enabled?'Disponível':'Desativada';
 }
 async function api(path,data=null){
  const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),path==='iniciar'?40000:22000);
  try{
   const response=await fetch(base+'/'+path,{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'application/json',...(data?{'Content-Type':'application/json'}:{})},...(data?{body:JSON.stringify({...data,csrf_token:csrf})}:{})});
   if([401,403,419].includes(response.status)){dead=true;void finish('Permissão ou sessão expirada. A ligação continua normalmente.');}
   const result=await response.json().catch(()=>null);
   if(!response.ok||result?.ok!==true){const error=Error(result?.error?.message||'IA temporariamente indisponível. A ligação continua normalmente.');error.status=response.status;error.publicMessage=typeof result?.error?.message==='string'?result.error.message.slice(0,500):'O servidor não retornou uma resposta válida para a IA.';throw error;}
   return result.data;
  }finally{clearTimeout(timer);}
 }
 function textNode(tag,text,className=''){const node=document.createElement(tag);node.textContent=String(text??'').slice(0,4000);if(className)node.className=className;return node;}
 function list(parent,title,items){
  if(!Array.isArray(items)||!items.length)return;parent.append(textNode('h3',title));const ul=document.createElement('ul');
  for(const item of items.slice(0,12))if(typeof item==='string')ul.append(textNode('li',item));parent.append(ul);
 }
 function render(insight){
  if(!insight||typeof insight!=='object')return;
  const metrics=el('copilotMetrics');metrics.replaceChildren();
  for(const [key,title] of [['interest','Interesse'],['purchase_intent','Intenção'],['sentiment','Sentimento'],['priority','Prioridade'],['risk','Risco de perda'],['score','Score estimado']]){
   const value=insight[key];const box=document.createElement('div');box.className='copilot-metric';
   const translated={low:'Baixo',medium:'Médio',high:'Alto',very_low:'Muito baixa',very_high:'Muito alta',negative:'Negativo',neutral:'Neutro',positive:'Positivo',mixed:'Misto'};
   const display=key==='score'?(typeof value==='number'&&Number.isFinite(value)?Math.max(0,Math.min(100,value))+'/100':'Sem evidência'):(label[value]||translated[value]||value||'Sem evidência');
   box.append(textNode('span',title),textNode('strong',display));metrics.append(box);
  }
  lastSuggestion=typeof insight.suggested_response==='string'?insight.suggested_response.slice(0,4000):'';
  el('copilotSuggestion').textContent=lastSuggestion||'Nenhuma sugestão nova.';el('copilotCopy').disabled=!lastSuggestion;
  el('copilotQuestion').textContent=insight.next_question||'Ainda sem pergunta sugerida.';
  el('copilotSummary').textContent=insight.summary||'Sem conteúdo suficiente para resumir.';
  el('copilotEstimate').textContent='Inferências da conversa, não garantias de conversão. '+(typeof insight.confidence==='string'?insight.confidence:'');
  const context=el('copilotContext');context.replaceChildren();
  for(const [key,title] of [['interests','Interesses'],['pains','Dores'],['needs','Necessidades'],['desires','Desejos'],['urgencies','Urgência'],['restrictions','Restrições'],['risks','Riscos'],['next_steps','Próximos passos'],['strengths','Pontos fortes'],['improvements','Pontos a desenvolver']])list(context,title,insight[key]);
  if(typeof insight.closing_signal==='string'&&insight.closing_signal.trim())context.prepend(textNode('p','Sinal de fechamento: '+insight.closing_signal));
  if(typeof insight.conversion_estimate==='number')context.append(textNode('p','Conversão estimada pela IA: '+Math.max(0,Math.min(100,insight.conversion_estimate))+'%. Não é uma probabilidade estatisticamente validada.','copilot-muted'));
  const objections=el('copilotObjections');objections.replaceChildren();
  for(const objection of (Array.isArray(insight.objections)?insight.objections:[]).slice(0,8)){
   if(!objection||typeof objection!=='object')continue;const box=document.createElement('div');box.className='copilot-objection';
   box.append(textNode('strong',objection.category),textNode('p',objection.evidence),textNode('p',objection.suggestion));objections.append(box);
  }
 }
 function transcript(segment){
  const log=el('copilotTranscript'),entry=document.createElement('div');
  entry.append(textNode('strong',segment.speaker==='seller'?'Vendedor':'Cliente'),textNode('p',segment.text));log.append(entry);
  while(log.children.length>100)log.firstElementChild.remove();
  log.scrollTop=log.scrollHeight;el('copilotPartial').textContent='';
 }
 function clearReport(){
  lastSuggestion='';el('copilotCopy').disabled=true;el('copilotSuggestion').textContent='As sugestões aparecerão após trechos relevantes da conversa.';
  el('copilotSummary').textContent='Aguardando conversa.';el('copilotQuestion').textContent='—';
  for(const id of ['copilotMetrics','copilotContext','copilotObjections','copilotTranscript','copilotPartial'])el(id).replaceChildren();
  el('copilotEstimate').textContent='Indicadores são inferências da conversa, não garantias de conversão.';
  consent.checked=false;
 }
 function isCurrent(current){return run===current&&(!attemptId||attemptId===current.attemptId);}
 async function flush(current){
  if(!current.session)return;
  if(current.pending)return current.pending;
  if(!current.batch&&current.queue.length){
   const segments=[];let bytes=0;
   while(current.queue.length&&segments.length<24){const size=new TextEncoder().encode(JSON.stringify(current.queue[0])).length;if(bytes+size>20000)break;segments.push(current.queue.shift());bytes+=size;}
   current.batch={batch_id:crypto.randomUUID(),segments};
  }
  if(!current.batch)return;
  const batch=current.batch;
  current.pending=api('segmentos',{session_id:current.session,...batch}).then(data=>{
   if(current.batch===batch)current.batch=null;
   if(isCurrent(current)){
    render(data.insight);
    if(data.warning)status(data.warning_message||'A IA não atualizou este trecho. A transcrição foi recebida; a ligação continua.',true);
    else if(data.insight&&data.analysis_updated===true)status('Análise atualizada. Acompanhando a conversa.');
   }
  }).finally(()=>{current.pending=null;});
  return current.pending;
 }
 async function finish(message='IA pausada. Relatório salvo quando houver conteúdo suficiente.'){
  const current=run;if(!current||current.stopping)return;current.stopping=true;controls();
  clearInterval(current.timer);clearTimeout(current.expiry);current.audio?.stop();current.audio=null;
  try{
   await current.boot.catch(()=>{});current.audio?.stop();current.audio=null;
   if(current.session&&!dead){
    let incomplete=current.incomplete||current.partials.size>0;
    try{await flush(current);while(current.queue.length)await flush(current);}catch{incomplete=true;}
    const result=await api('finalizar',{session_id:current.session,incomplete});
    if(isCurrent(current)){
     render(result.insight);
     if(result.warning)status(result.warning_message||'A análise não foi concluída. Confira a configuração do copiloto.',true);
     else status(result.status==='unavailable'?'A análise não gerou relatório. Confira a configuração do copiloto.':incomplete||result.status==='partial'?'Relatório parcial salvo. Alguns trechos não puderam ser analisados; confira a gravação depois.':message,result.status==='unavailable');
    }
   }else status(message,true);
  }catch{status('IA pausada. Não foi possível confirmar o relatório final; confira o histórico depois. A ligação continua normalmente.',true);}
  finally{if(run===current)run=null;controls();}
 }
 async function begin(){
  if(start.disabled)return;
  const tracks=window.VoxPhone?.getAudioTracks?.();
  if(!tracks?.seller||!tracks?.customer){status('Sem áudio das duas pontas disponível. Use a análise da gravação após a chamada.',true);return;}
  const current={attemptId,session:null,audio:null,queue:[],batch:null,pending:null,stopping:false,incomplete:false,partials:new Set(),timer:null,expiry:null,boot:null};run=current;controls();
  current.boot=(async()=>{
   let audioModule;try{audioModule=await import('./copilot-audio.mjs');}catch{const error=Error();error.publicMessage='Não foi possível carregar o módulo de áudio. Feche o discador e atualize a página; confira o arquivo copilot-audio.mjs no servidor.';throw error;}
   if(current.stopping)return;
   const result=await api('iniciar',{attempt_id:current.attemptId,consent:true,request_id:crypto.randomUUID()});
   current.session=result.session_id;usedAttempt=current.attemptId;if(current.stopping)return;
   const remaining=Date.parse(result.expires_at)-Date.now();
   if(!Number.isFinite(remaining)||remaining<=0)throw Error('A sessão da IA expirou.');
   current.audio=await audioModule.startCopilotAudio({
    tracks,tokens:result.tokens,model:result.live_model,languageCodes:result.language_codes||['pt-BR'],workletUrl:panel.dataset.worklet,maxDurationMs:Math.min(600000,remaining),
    shouldSend:speaker=>{const state=window.VoxPhone?.getState();return !current.stopping&&state?.state==='active'&&!state.held&&(speaker!=='seller'||!state.muted);},
    onStatus:value=>{if(isCurrent(current)&&!current.stopping)status(value==='active'?'Acompanhando a conversa. A IA não fala com o cliente.':'Conectando a inteligência…');},
    onPartial:segment=>{if(isCurrent(current)&&!current.stopping){current.partials.add(segment.speaker);el('copilotPartial').textContent=(segment.speaker==='seller'?'Vendedor: ':'Cliente: ')+String(segment.text||'').slice(0,2000);}},
    onSegment:segment=>{
     if(current.stopping||!isCurrent(current)||!['seller','customer'].includes(segment.speaker))return;
     current.partials.delete(segment.speaker);
     let text='',bytes=0;for(const char of String(segment.text||'').trim()){const size=new TextEncoder().encode(char).length;if(bytes+size>2800){current.incomplete=true;break;}text+=char;bytes+=size;}
     const clean={speaker:segment.speaker,text,start_ms:Math.round(Math.max(0,Math.min(600000,Number(segment.start_ms)||0)))};
     if(!clean.text)return;
     if(current.queue.length>=48){current.incomplete=true;void finish('IA pausada por limite de processamento. A ligação continua.');return;}
     current.queue.push(clean);transcript(clean);
    },
    onError:()=>{current.incomplete=true;void finish('IA temporariamente indisponível. A ligação continua normalmente.');}
   });
   if(current.stopping){current.audio.stop();current.audio=null;return;}
   current.timer=setInterval(()=>{void flush(current).catch(error=>{
    if(error.status===429){status('Aguardando intervalo de atualização da IA.');return;}
    void finish('IA temporariamente indisponível. A ligação continua normalmente.');
   });},Math.max(5,Number(result.update_seconds||settings.update_seconds)||10)*1000);
   current.expiry=setTimeout(()=>void finish('Limite desta sessão de IA atingido. A ligação continua.'),Math.min(600000,remaining));
  })();
  try{await current.boot;}catch(error){void finish(error.publicMessage||'Não foi possível iniciar a conexão de áudio com a IA. A ligação continua normalmente.');}
 }
 start.addEventListener('click',()=>void begin());stop.addEventListener('click',()=>void finish());consent.addEventListener('change',controls);
 el('copilotCopy').addEventListener('click',async()=>{
  try{await navigator.clipboard.writeText(lastSuggestion);status('Sugestão copiada. Revise antes de usar.');}
  catch{status('Não foi possível copiar. Selecione o texto da sugestão manualmente.',true);}
 });
 window.addEventListener('crm:phone:attempt',event=>{const next=event.detail?.attemptId||null;if(next!==attemptId)clearReport();attemptId=next;controls();});
 window.addEventListener('crm:phone:context',()=>{if(!run)clearReport();controls();});
 window.addEventListener('vox:phone-state',event=>{controls();if(run&&!['active','ringing','dialing'].includes(event.detail?.state))void finish('Chamada encerrada. Relatório da IA salvo quando houver conteúdo suficiente.');});
 window.addEventListener('vox:call-ended',()=>{attemptId=null;void finish('Chamada encerrada. Relatório da IA salvo.');controls();});
 window.addEventListener('pagehide',()=>{if(run){run.audio?.stop();clearInterval(run.timer);clearTimeout(run.expiry);}});
 async function configure(){
  try{settings=await api('configuracao');if(settings?.can_use===false||!settings?.enabled){if(run)void finish('IA desativada pelo administrador. A ligação continua.');}
   if(!run&&(usedAttempt===null||usedAttempt!==attemptId))status(settings.enabled?(settings.configured?'Ative durante a chamada, após informar os participantes.':'Solicite ao administrador a configuração do Gemini.'):'IA desativada. O discador e a análise de gravações continuam disponíveis.');
  }catch{settings=null;if(run)void finish('IA temporariamente indisponível. A ligação continua.');else status('IA indisponível. O telefone continua funcionando normalmente.',true);}
  finally{controls();}
 }
 void configure();setInterval(()=>{if(!dead)void configure();},15000);
}
