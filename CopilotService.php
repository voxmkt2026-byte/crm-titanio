<?php
declare(strict_types=1);
require_once __DIR__.'/CopilotConfig.php';
require_once __DIR__.'/CopilotInsight.php';
require_once __DIR__.'/CopilotGateway.php';

/** Independent copilot state. Never mutates phone attempts, leads or legacy analyses. */
final class CopilotService {
 private CopilotConfig $config;
 private CopilotGateway $gateway;
 public function __construct(private PDO $db,IntegrationConfig $config,?CopilotGateway $gateway=null){$this->config=new CopilotConfig($config);$this->gateway=$gateway??new CopilotGateway();}
 public function configuration():array{return $this->config->publicSettings();}
 private function query(string $sql,array $args=[]):PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 private function json($v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
 private function logFailure(string $id,string $code,string $stage='unknown',?Throwable $error=null):void{$details=['session_id'=>$id,'code'=>$code,'stage'=>$stage];if($error)$details['exception']=$error::class;error_log('[copilot] '.$this->json($details));}
 private function analysisFailure(Throwable $error):array{
  // Never forward raw provider errors, keys, SQL or transcript contents to the UI.
  $messages=[
   'AI_MODEL_UNAVAILABLE'=>'O modelo de análise não está disponível para esta conta. Atualize o modelo nas configurações do copiloto.',
   'AI_AUTH_FAILED'=>'A chave Gemini não tem acesso à análise. Confira a chave e as permissões nas configurações.',
   'AI_RATE_LIMIT'=>'O limite do Gemini foi atingido. Confira a cota da conta. A ligação continua normalmente.',
   'AI_PROVIDER_BUSY'=>'O Gemini está temporariamente ocupado. A análise deste trecho não foi concluída.',
   'AI_INVALID_RESPONSE'=>'A IA não retornou uma análise válida para este trecho.',
   'AI_TIMEOUT'=>'A análise demorou além do limite. A transcrição foi preservada e a ligação continua.',
   'AI_CONNECTION_FAILED'=>'Não foi possível conectar ao Gemini para analisar este trecho. A transcrição foi preservada e a ligação continua.',
   'AI_REQUEST_REJECTED'=>'O Gemini recusou a configuração da análise. Confira o modelo e os parâmetros nas configurações do copiloto.',
   'AI_RESPONSE_TOO_LARGE'=>'A resposta da IA excedeu o limite de tamanho. A transcrição foi preservada e a ligação continua.',
   'AI_UNAVAILABLE'=>'Não foi possível atualizar a análise. A transcrição foi recebida e a ligação continua normalmente.'
  ];
  $code=$error instanceof CopilotException&&isset($messages[$error->publicCode])?$error->publicCode:'AI_UNAVAILABLE';
  return ['warning'=>$code,'warning_message'=>$messages[$code]];
 }
 private function analysisPending(array $settings,int $segments,int $cursor,int $count,?string $error):bool{
  return $segments>$cursor&&$count<(int)ceil($settings['max_minutes']*60/$settings['update_seconds'])&&(!$error||in_array($error,['AI_TIMEOUT','AI_CONNECTION_FAILED','AI_PROVIDER_BUSY','AI_UNAVAILABLE','AI_INVALID_RESPONSE'],true));
 }
 private function decode(?string $v):array{$a=json_decode($v??'',true);return is_array($a)?$a:[];}
 private function lock():string{return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
 private function activeUser(int $user):void{if(!$this->query('SELECT id FROM users WHERE id=? AND active=1'.$this->lock(),[$user])->fetchColumn())throw new CopilotException('AI_FORBIDDEN','Usuário inativo ou sem acesso.',403);}
 private function attempt(int $user,string $attempt,bool $live):array{$row=$this->query('SELECT * FROM phone_attempts WHERE attempt_id=? AND user_id=?',[$attempt,$user])->fetch(PDO::FETCH_ASSOC);if(!$row)throw new CopilotException('ATTEMPT_NOT_FOUND','Ligação não encontrada.',404);if($live&&!in_array($row['state'],['dialing','active','ringing','answered','connected'],true))throw new CopilotException('CALL_NOT_ACTIVE','Inicie uma ligação antes de ativar o copiloto.',409);return $row;}
 private function context(?int $lead,int $user,bool $all):array{if(!$lead)return [];$row=$this->query('SELECT * FROM leads WHERE id=?',[$lead])->fetch(PDO::FETCH_ASSOC);if(!$row||(!$all&&(int)$row['assigned_to']!==$user))throw new CopilotException('LEAD_FORBIDDEN','O acesso ao contato foi alterado.',403);$out=[];foreach(['name','interest','desired_value','status'] as $k){if(isset($row[$k])&&is_scalar($row[$k]))$out[$k]=mb_substr((string)$row[$k],0,300);}return $out;}
 private function paidContext(int $user,bool $all,array $session):array{$this->activeUser($user);$a=$this->attempt($user,$session['attempt_id'],false);if(($a['lead_id']===null?null:(int)$a['lead_id'])!==($session['lead_id']===null?null:(int)$session['lead_id']))throw new CopilotException('LEAD_FORBIDDEN','O vínculo do contato foi alterado.',403);return $this->context($session['lead_id']===null?null:(int)$session['lead_id'],$user,$all);}
 private function owned(int $user,string $id):array{$this->activeUser($user);$r=$this->query('SELECT * FROM copilot_sessions WHERE session_id=? AND user_id=?',[$id,$user])->fetch(PDO::FETCH_ASSOC);if(!$r)throw new CopilotException('SESSION_NOT_FOUND','Sessão não encontrada.',404);return $r;}
 private function available():array{$s=$this->config->settings();if(!$s['enabled'])throw new CopilotException('AI_DISABLED','O copiloto está desativado.',403);if($this->config->key()==='')throw new CopilotException('AI_NOT_CONFIGURED','Peça ao administrador para configurar a IA.',503);return $s;}
 public function purgeExpired():void{$now=time();$this->query("UPDATE copilot_sessions SET active_user=NULL,status=CASE WHEN status IN ('starting','active') THEN 'expired' ELSE status END WHERE expires_at<=? AND active_user IS NOT NULL",[$now]);$this->query('DELETE FROM copilot_batches WHERE session_id IN (SELECT session_id FROM copilot_sessions WHERE purge_at<?)',[$now]);$this->query('DELETE FROM copilot_sessions WHERE purge_at<?',[$now]);$this->query("UPDATE copilot_sessions SET transcript_json='[]' WHERE expires_at<? AND retain_transcript=0 AND last_analysis_at<?",[$now-3600,$now-3600]);}
 public function start(int $user,bool $all,string $attempt,bool $consent,string $request):array{
  if(!$consent)throw new CopilotException('CONSENT_REQUIRED','Confirme o aviso de transcrição e análise antes de iniciar.');if(!preg_match('/^[A-Za-z0-9_-]{12,100}$/D',$request))throw new CopilotException('INVALID_REQUEST_ID','Identificador inválido.');$s=$this->available();$this->purgeExpired();$id=bin2hex(random_bytes(16));$now=time();$expires=$now+$s['max_minutes']*60;
  try{$this->db->beginTransaction();$this->activeUser($user);$a=$this->attempt($user,$attempt,true);$this->context($a['lead_id']===null?null:(int)$a['lead_id'],$user,$all);
   if($this->query('SELECT session_id FROM copilot_sessions WHERE user_id=? AND request_id=?',[$user,$request])->fetchColumn())throw new CopilotException('REQUEST_ALREADY_USED','Esta solicitação já foi consumida. Tokens não podem ser emitidos novamente.',409);
   $used=(int)$this->query('SELECT COALESCE(SUM(reserved_minutes),0) FROM copilot_sessions WHERE user_id=? AND quota_day=?',[$user,gmdate('Y-m-d')])->fetchColumn();if($used+$s['max_minutes']>$s['daily_minutes'])throw new CopilotException('DAILY_LIMIT','Limite diário de minutos da IA atingido.',429);
   if($this->query('SELECT session_id FROM copilot_sessions WHERE active_user=? OR (attempt_id=? AND user_id=?)',[$user,$attempt,$user])->fetchColumn())throw new CopilotException('SESSION_EXISTS','Esta ligação já possui uma sessão de IA; não é permitido emitir novos tokens.',409);
   $this->query("INSERT INTO copilot_sessions(session_id,request_id,attempt_id,user_id,lead_id,active_user,status,created_at,expires_at,purge_at,quota_day,reserved_minutes,retain_transcript,settings_json,transcript_json,insight_json,analysis_count,last_analysis_at,analysis_cursor) VALUES(?,?,?,?,?,?,'starting',?,?,?,?,?,?,?,'[]','{}',0,0,0)",[$id,$request,$attempt,$user,$a['lead_id'],$user,$now,$expires,$now+$s['retention_days']*86400,gmdate('Y-m-d'),$s['max_minutes'],$s['retain_transcript']?1:0,$this->json($s)]);$this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException)throw new CopilotException('SESSION_EXISTS','Já existe uma sessão reservada.',409);throw $e;}
  try{$row=$this->owned($user,$id);$this->paidContext($user,$all,$row);$seller=$this->gateway->token($this->config->key(),$s,$expires);$this->paidContext($user,$all,$row);$customer=$this->gateway->token($this->config->key(),$s,$expires);$this->query("UPDATE copilot_sessions SET status='active' WHERE session_id=?",[$id]);return ['session_id'=>$id,'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',$expires),'live_model'=>$s['live_model'],'language_codes'=>$s['language']==='auto'?[]:[$s['language']],'tokens'=>['seller'=>$seller,'customer'=>$customer],'update_seconds'=>$s['update_seconds']];}
  catch(Throwable $e){$this->logFailure($id,'TOKEN_FAILED','token',$e);$this->query("UPDATE copilot_sessions SET status='failed',active_user=NULL,error_code='TOKEN_FAILED' WHERE session_id=?",[$id]);if($e instanceof CopilotException)throw $e;throw new CopilotException('AI_UNAVAILABLE','Não foi possível iniciar a IA; sua ligação continua.',503);}
 }
 private function validateSegments(array $segments,int $maxMs):array{if(!$segments||count($segments)>40)throw new CopilotException('INVALID_SEGMENTS','Envie entre 1 e 40 trechos.');$bytes=0;$out=[];foreach($segments as $v){if(!is_array($v)||!in_array($v['speaker']??null,['seller','customer'],true)||!is_string($v['text']??null)||trim($v['text'])===''||strlen($v['text'])>3000||!is_int($v['start_ms']??null)||$v['start_ms']<0||$v['start_ms']>$maxMs)throw new CopilotException('INVALID_SEGMENTS','Trecho inválido.');$bytes+=strlen($v['text']);$out[]=['speaker'=>$v['speaker'],'text'=>trim($v['text']),'start_ms'=>$v['start_ms']];}if($bytes>24000)throw new CopilotException('INVALID_SEGMENTS','Lote muito grande.');return $out;}
 public function segments(int $user,bool $all,string $id,string $batch,array $segments,bool $retryAnalysis=false):array{
  if(!preg_match('/^[A-Za-z0-9_-]{8,100}$/D',$batch))throw new CopilotException('INVALID_BATCH','Identificador de lote inválido.');
  $row=$this->owned($user,$id);$s=$this->decode($row['settings_json']);
  if($retryAnalysis){if($segments!==[])throw new CopilotException('INVALID_SEGMENTS','Uma nova tentativa deve usar somente os trechos já recebidos.');}
  else $segments=$this->validateSegments($segments,$s['max_minutes']*60000);
  $hash=hash('sha256',$this->json($segments));$this->paidContext($user,$all,$row);$this->available();$now=time();
  if((int)$row['expires_at']<$now)throw new CopilotException('SESSION_EXPIRED','Tempo de IA encerrado; finalize o relatório.',409);
  $previous=$this->query('SELECT * FROM copilot_batches WHERE session_id=? AND batch_id=?',[$id,$batch])->fetch(PDO::FETCH_ASSOC);
  if($previous){if($previous['payload_hash']!==$hash)throw new CopilotException('BATCH_CONFLICT','Identificador reutilizado para outro lote.',409);if(!$previous['result_json'])throw new CopilotException('AI_BUSY','Lote em processamento.',409);return $this->decode($previous['result_json']);}
  try{
   $this->db->beginTransaction();$row=$this->query('SELECT * FROM copilot_sessions WHERE session_id=? AND user_id=?'.$this->lock(),[$id,$user])->fetch(PDO::FETCH_ASSOC);
   if(!$row)throw new CopilotException('SESSION_NOT_FOUND','Sessão não encontrada.',404);
   if($row['status']!=='active')throw new CopilotException('AI_BUSY','Sessão ocupada ou encerrada.',409);
   if($retryAnalysis){
    $retryLimit=(int)ceil($s['max_minutes']*60/$s['update_seconds'])*2;
    $retryCount=(int)$this->query('SELECT COUNT(*) FROM copilot_batches WHERE session_id=? AND payload_hash=?',[$id,$hash])->fetchColumn();
    if($retryCount>=$retryLimit){
     $this->db->commit();
     return ['session_id'=>$id,'insight'=>$this->decode($row['insight_json'])?:null,'analysis_updated'=>false,'analysis_pending'=>false,'warning'=>'AI_RETRY_LIMIT','warning_message'=>'O limite de novas tentativas sem fala foi atingido. A transcrição e a ligação continuam normalmente.'];
    }
   }
   $allSegments=$this->decode($row['transcript_json']);
   if(!$retryAnalysis){
    if(count($allSegments)+count($segments)>2000||strlen($row['transcript_json'])+strlen($this->json($segments))>180000)throw new CopilotException('TRANSCRIPT_LIMIT','Limite de transcrição atingido; finalize a IA.',429);
    $allSegments=array_merge($allSegments,$segments);
   }
   $cursor=(int)$row['analysis_cursor'];$count=(int)$row['analysis_count'];
   $hasBudget=$count<(int)ceil($s['max_minutes']*60/$s['update_seconds']);
   $due=$now-(int)$row['last_analysis_at']>=$s['update_seconds']&&$hasBudget&&(!$retryAnalysis||$this->analysisPending($s,count($allSegments),$cursor,$count,$row['error_code']));
   $this->query('INSERT INTO copilot_batches(session_id,batch_id,payload_hash,created_at) VALUES(?,?,?,?)',[$id,$batch,$hash,$now]);
   $this->query('UPDATE copilot_sessions SET transcript_json=?,status=?,last_analysis_at=?,analysis_count=analysis_count+? WHERE session_id=?',[$retryAnalysis?$row['transcript_json']:$this->json($allSegments),$due?'analyzing':'active',$due?$now:$row['last_analysis_at'],$due?1:0,$id]);
   $this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  $insight=$this->decode($row['insight_json']);$warning=[];
  if($due){
   $count++;$stage='context';
   try{
    $context=$this->paidContext($user,$all,$row);$stage='analysis';
    $insight=$this->gateway->analyze($this->config->key(),$s,['lead'=>$context,'new_segments'=>array_slice($allSegments,$cursor,60),'rolling_context'=>array_slice($allSegments,max(0,$cursor-12),12),'previous_insight'=>$insight,'final'=>false]);
    $nextCursor=min(count($allSegments),$cursor+60);$stage='persist';
    $this->query('UPDATE copilot_sessions SET insight_json=?,analysis_cursor=? WHERE session_id=?',[$this->json($insight),$nextCursor,$id]);$cursor=$nextCursor;
   }catch(Throwable $error){$warning=$this->analysisFailure($error);$this->logFailure($id,$warning['warning'],$stage,$error);}
   $this->query("UPDATE copilot_sessions SET status='active',error_code=? WHERE session_id=?",[$warning['warning']??null,$id]);
  }elseif(!empty($row['error_code']))$warning=$this->analysisFailure(new CopilotException($row['error_code'],''));
  $pending=(int)$row['expires_at']>=time()&&$this->analysisPending($s,count($allSegments),$cursor,$count,$warning['warning']??null);
  $result=['session_id'=>$id,'insight'=>$insight?:null,'analysis_updated'=>$due&&!$warning,'analysis_pending'=>$pending]+$warning;
  $this->query('UPDATE copilot_batches SET result_json=? WHERE session_id=? AND batch_id=?',[$this->json($result),$id,$batch]);return $result;
 }
 public function finish(int $user,bool $all,string $id,bool $incomplete=false):array{
  $row=$this->owned($user,$id);$this->paidContext($user,$all,$row);if($row['final_report_json'])return $this->decode($row['final_report_json']);$s=$this->decode($row['settings_json']);
  try{$this->db->beginTransaction();$row=$this->query('SELECT * FROM copilot_sessions WHERE session_id=? AND user_id=?'.$this->lock(),[$id,$user])->fetch(PDO::FETCH_ASSOC);if($row['final_report_json']){$this->db->commit();return $this->decode($row['final_report_json']);}if(in_array($row['status'],['analyzing','finalizing'],true)&&time()-(int)$row['last_analysis_at']<45)throw new CopilotException('AI_BUSY','Aguarde a análise atual para finalizar.',409);$this->query("UPDATE copilot_sessions SET status='finalizing',last_analysis_at=?,active_user=NULL WHERE session_id=?",[time(),$id]);$this->db->commit();}catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
  $segments=$this->decode($row['transcript_json']);$insight=$this->decode($row['insight_json']);$status='unavailable';$warning=[];
  if($segments){$stage='context';try{$this->available();$context=$this->paidContext($user,$all,$row);$stage='analysis';$insight=$this->gateway->analyze($this->config->key(),$s,['lead'=>$context,'new_segments'=>array_slice($segments,(int)$row['analysis_cursor'],60),'rolling_context'=>array_slice($segments,-20),'previous_insight'=>$insight,'final'=>true]);$status=($incomplete||count($segments)>(int)$row['analysis_cursor']+60)?'partial':'completed';}catch(Throwable $error){$status=$insight?'partial':'unavailable';$warning=$this->analysisFailure($error);$this->logFailure($id,$warning['warning'],$stage,$error);}}
  else $warning=['warning'=>'NO_AUDIO','warning_message'=>'Nenhum trecho de áudio foi recebido para análise. Use a gravação após a sincronização.'];
  $result=['insight'=>$insight?:null,'status'=>$status]+$warning;$this->query('UPDATE copilot_sessions SET status=?,final_report_json=?,insight_json=?,finished_at=?,transcript_json=? WHERE session_id=?',[$status,$this->json($result),$this->json($insight),time(),$s['retain_transcript']?$row['transcript_json']:'[]',$id]);if(!$s['retain_transcript'])$this->query('DELETE FROM copilot_batches WHERE session_id=?',[$id]);return $result;
 }
 public function readReportsForLead(int $lead,int $user,bool $all):array{try{$this->activeUser($user);$this->context($lead,$user,$all);return $this->reports('s.lead_id=?',[$lead],$user,$all);}catch(Throwable){return [];}}
 public function readReportsForCall(int $call,int $user,bool $all):array{try{$this->activeUser($user);return $this->reports('p.call_record_id=?',[$call],$user,$all);}catch(Throwable){return [];}}
 private function reports(string $where,array $args,int $user,bool $all):array{$this->purgeExpired();$rows=$this->query('SELECT s.* FROM copilot_sessions s JOIN phone_attempts p ON p.attempt_id=s.attempt_id WHERE '.$where.' AND s.final_report_json IS NOT NULL ORDER BY s.created_at DESC LIMIT 30',$args)->fetchAll(PDO::FETCH_ASSOC);$out=[];foreach($rows as $r){try{if(!$all&&(int)$r['user_id']!==$user)continue;$this->context($r['lead_id']===null?null:(int)$r['lead_id'],$user,$all);$report=$this->decode($r['final_report_json']);$s=$this->decode($r['settings_json']);if($s['private_coaching']&&(int)$r['user_id']!==$user&&is_array($report['insight']??null)){foreach(['strengths','improvements'] as $k)$report['insight'][$k]=[];}$out[]=$report+['session_id'=>$r['session_id'],'attempt_id'=>$r['attempt_id'],'created_at'=>gmdate('Y-m-d H:i:s',(int)$r['created_at']),'finished_at'=>$r['finished_at']?gmdate('Y-m-d H:i:s',(int)$r['finished_at']):null];}catch(CopilotException){}}return $out;}
}
