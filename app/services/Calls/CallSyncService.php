<?php
require_once APP_PATH.'/services/Calls/PhoneNormalizer.php';require_once APP_PATH.'/services/Calls/CallLeadMatcher.php';require_once APP_PATH.'/services/Calls/AnalysisNormalizer.php';require_once APP_PATH.'/services/Calls/OpenRouterAnalyzer.php';require_once APP_PATH.'/services/Calls/GeminiCallAnalyzer.php';require_once __DIR__.'/CallRecordingArchive.php';
final class CallSyncService {
 private CallLeadMatcher $matcher;
 public function __construct(private PDO $db,private IntegrationConfig $config){$this->matcher=new CallLeadMatcher($db);}
 public static function contactNumber(array $c):?string{$type=strtolower((string)($c['call_type']??$c['direction']??''));$values=str_contains($type,'inbound')?[$c['from']??null,$c['BINA']??$c['bina']??null,$c['to']??null]:[$c['to']??null,$c['BINA']??$c['bina']??null,$c['from']??null];foreach($values as$value){$phone=PhoneNormalizer::canonical(is_scalar($value)?(string)$value:null);if($phone!==null)return$phone;}return null;}
 public function run(int $pages=3,int $jobs=1):array{$synced=0;try{$this->db->exec("INSERT INTO call_sync_state(provider,status,locked_at) VALUES('api4com','running',NOW()) ON DUPLICATE KEY UPDATE status='running',locked_at=NOW(),last_error_code=NULL");for($page=1;$page<=max(1,min(100,$pages));$page++){ $calls=$this->fetchCalls($page);if(!$calls)break;foreach($calls as$call)if(!empty($call['record_url'])){$this->upsert($call);$synced++;}}$processed=$jobs>0?$this->process($jobs):0;$result=compact('synced','processed');$last=$this->db->query("SELECT MAX(started_at) FROM call_records WHERE provider='api4com'")->fetchColumn();$q=$this->db->prepare("UPDATE call_sync_state SET status='idle',last_started_at=:last,last_success_at=NOW(),last_counts=:counts,last_error_code=NULL,locked_at=NULL WHERE provider='api4com'");$q->execute([':last'=>$last?:null,':counts'=>json_encode($result,JSON_UNESCAPED_UNICODE)]);return$result;}catch(Throwable$e){$code=substr(preg_replace('/[^A-Z0-9_]/','',strtoupper($e->getMessage()))?:'UNKNOWN_ERROR',0,80);try{$this->db->prepare("INSERT INTO call_sync_state(provider,status,last_error_code) VALUES('api4com','failed',:error) ON DUPLICATE KEY UPDATE status='failed',last_error_code=VALUES(last_error_code),locked_at=NULL")->execute([':error'=>$code]);}catch(Throwable){}throw$e;}}
 public function syncPage(int $page):array{$page=max(1,min(100,$page));$calls=$this->fetchCalls($page);$synced=0;foreach($calls as$call)if(!empty($call['record_url'])){$this->upsert($call);$synced++;}$result=['page'=>$page,'source_count'=>count($calls),'synced'=>$synced,'has_more'=>count($calls)>=20];$last=$this->db->query("SELECT MAX(started_at) FROM call_records WHERE provider='api4com'")->fetchColumn();$q=$this->db->prepare("INSERT INTO call_sync_state(provider,last_started_at,last_success_at,status,last_counts,last_error_code,locked_at) VALUES('api4com',:last,NOW(),'idle',:counts,NULL,NULL) ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at),last_success_at=NOW(),status='idle',last_counts=VALUES(last_counts),last_error_code=NULL,locked_at=NULL");$q->execute([':last'=>$last?:null,':counts'=>json_encode($result,JSON_UNESCAPED_UNICODE)]);return$result;}
 public function importLegacy(string $directory,int $pages=100,bool $dryRun=true):array{$result=['with_recording'=>0,'with_analysis'=>0,'imported'=>0,'voicemail'=>0,'invalid'=>0];for($page=1;$page<=max(1,$pages);$page++){foreach($this->fetchCalls($page)as$c){if(empty($c['record_url']))continue;$result['with_recording']++;$x=(string)($c['id']??'');$file=rtrim($directory,'/\\').DIRECTORY_SEPARATOR.hash('sha256',$x).'.json';if(!is_file($file))continue;$result['with_analysis']++;try{$raw=file_get_contents($file);$a=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);$transcript=mb_strtolower((string)($a['transcript']??''));$a['call_outcome']=(str_contains($transcript,'caixa postal')||str_contains($transcript,'correio de voz'))?'voicemail':($a['call_outcome']??'conversation');$a=(new AnalysisNormalizer())->normalize($a,'gemini',(string)($a['provider_model']??'legado'));if($a['call_outcome']==='voicemail'){$result['voicemail']++;if(!$dryRun){$this->upsert($c);$this->db->prepare("UPDATE call_records SET outcome='voicemail',analysis_status='discarded',recording_status='discarded' WHERE provider='api4com' AND external_id=:x")->execute([':x'=>$x]);}continue;}if(!$dryRun){$this->upsert($c);$id=(int)$this->db->query("SELECT id FROM call_records WHERE provider='api4com' AND external_id=".$this->db->quote($x))->fetchColumn();$j=json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$q=$this->db->prepare("INSERT INTO call_analyses(call_id,analysis_version,summary,transcript,sentiment,lead_temperature,sales_stage,call_outcome,overall_score,provider,provider_model,payload,analyzed_at) VALUES(:id,:v,:s,:t,:se,:lt,:st,:o,:sc,:p,:m,:j,NOW()) ON DUPLICATE KEY UPDATE payload=VALUES(payload),summary=VALUES(summary),transcript=VALUES(transcript)");$q->execute([':id'=>$id,':v'=>$a['analysis_version'],':s'=>$a['summary'],':t'=>$a['transcript'],':se'=>$a['sentiment'],':lt'=>$a['lead_temperature'],':st'=>$a['sales_stage'],':o'=>$a['call_outcome'],':sc'=>$a['scores']['overall'],':p'=>$a['provider'],':m'=>$a['provider_model'],':j'=>$j]);$this->db->prepare("UPDATE call_records SET outcome=:o,analysis_status='completed' WHERE id=:id")->execute([':o'=>$a['call_outcome'],':id'=>$id]);$this->db->prepare("UPDATE call_processing_jobs SET status='completed',completed_at=NOW() WHERE call_id=:id AND job_type='analyze'")->execute([':id'=>$id]);$this->history($id);}$result['imported']++;}catch(Throwable$e){$result['invalid']++;}}}return$result;}
 public function importCachedAnalysis(int $callId, string $directory): array
 {
     $q=$this->db->prepare("SELECT external_id FROM call_records WHERE id=:id AND provider='api4com' LIMIT 1");
     $q->execute([':id'=>$callId]); $externalId=(string)$q->fetchColumn();
     if ($externalId==='' || !preg_match('/^[A-Za-z0-9._:-]{1,160}$/D',$externalId)) throw new RuntimeException('CALL_NOT_FOUND');
     $file=rtrim($directory,'/\\').DIRECTORY_SEPARATOR.hash('sha256',$externalId).'.json';
     if (!is_file($file)) throw new RuntimeException('ANALYSIS_CACHE_MISSING');
     $a=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
     if (!is_array($a)) throw new RuntimeException('ANALYSIS_CACHE_INVALID');
     $a=(new AnalysisNormalizer())->normalize($a,'gemini',(string)($a['provider_model']??'modulo-ligacao'));
     $this->saveAnalysis($callId,$a);
     return $a;
 }
 public function saveAnalysis(int $callId, array $a): void
 {
     $a=(new AnalysisNormalizer())->normalize($a,(string)($a['provider']??'gemini'),(string)($a['provider_model']??''));
     $this->db->beginTransaction();
     $deletePath=null;
     try {
         if ($a['call_outcome']==='voicemail') {
             $q=$this->db->prepare('SELECT recording_path FROM call_records WHERE id=:id');
             $q->execute([':id'=>$callId]);
             $deletePath=(new CallMediaStorage(STORAGE_PATH))->resolve((string)$q->fetchColumn());
             $this->db->prepare("DELETE FROM call_analyses WHERE call_id=:id")->execute([':id'=>$callId]);
             $this->db->prepare("UPDATE call_records SET outcome='voicemail',analysis_status='discarded',recording_status='discarded',recording_path=NULL,recording_mime=NULL,recording_size=NULL,recording_checksum=NULL,recording_expires_at=NULL,last_error_code=NULL WHERE id=:id")->execute([':id'=>$callId]);
         } else {
             $json=json_encode($a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
             $q=$this->db->prepare("INSERT INTO call_analyses(call_id,analysis_version,summary,transcript,sentiment,lead_temperature,sales_stage,call_outcome,overall_score,provider,provider_model,payload,analyzed_at) VALUES(:id,:v,:s,:t,:se,:lt,:st,:o,:sc,:p,:m,:j,NOW()) ON DUPLICATE KEY UPDATE analysis_version=VALUES(analysis_version),summary=VALUES(summary),transcript=VALUES(transcript),sentiment=VALUES(sentiment),lead_temperature=VALUES(lead_temperature),sales_stage=VALUES(sales_stage),call_outcome=VALUES(call_outcome),overall_score=VALUES(overall_score),provider=VALUES(provider),provider_model=VALUES(provider_model),payload=VALUES(payload),analyzed_at=NOW()");
             $q->execute([':id'=>$callId,':v'=>$a['analysis_version'],':s'=>$a['summary'],':t'=>$a['transcript'],':se'=>$a['sentiment'],':lt'=>$a['lead_temperature'],':st'=>$a['sales_stage'],':o'=>$a['call_outcome'],':sc'=>$a['scores']['overall'],':p'=>$a['provider'],':m'=>$a['provider_model'],':j'=>$json]);
             $this->db->prepare("UPDATE call_records SET outcome=:o,analysis_status='completed',last_error_code=NULL WHERE id=:id")->execute([':o'=>$a['call_outcome'],':id'=>$callId]);
             $this->history($callId);
         }
         $this->db->prepare("INSERT INTO call_processing_jobs(call_id,job_type,status,completed_at,last_error_code) VALUES(:id,'analyze','completed',NOW(),NULL) ON DUPLICATE KEY UPDATE status='completed',completed_at=NOW(),locked_at=NULL,last_error_code=NULL")->execute([':id'=>$callId]);
         $this->db->commit();
     } catch (Throwable $e) {
         if ($this->db->inTransaction()) $this->db->rollBack();
         throw $e;
     }
     if ($deletePath!==null) @unlink($deletePath);
 }
 public function queueAnalysis(int $callId):void{$exists=$this->db->prepare('SELECT COUNT(*) FROM call_records WHERE id=:id');$exists->execute([':id'=>$callId]);if(!(int)$exists->fetchColumn())throw new RuntimeException('CALL_NOT_FOUND');$this->db->prepare("INSERT INTO call_processing_jobs(call_id,job_type,status,available_at) VALUES(:id,'analyze','pending',NOW()) ON DUPLICATE KEY UPDATE status='pending',available_at=NOW(),locked_at=NULL,completed_at=NULL,last_error_code=NULL")->execute([':id'=>$callId]);$this->db->prepare("UPDATE call_records SET analysis_status='pending',last_error_code=NULL WHERE id=:id")->execute([':id'=>$callId]);}
 public function processQueued(int $callId):void { $this->executeAnalysis($callId,false); }
 public function reanalyze(int $callId):void { $this->executeAnalysis($callId,true); }
 private function executeAnalysis(int $callId,bool $restart):void
 {
     $database=(string)$this->db->query('SELECT DATABASE()')->fetchColumn();
     $lock='titanium_call_'.$database.'_'.$callId;
     // Hash unusually long database names to respect MySQL's 64-character lock limit.
     if (strlen($lock)>64) $lock='titanium_call_'.substr(hash('sha256',$lock),0,48);
     $q=$this->db->prepare('SELECT GET_LOCK(:name,0)');
     $q->execute([':name'=>$lock]);
     if ((int)$q->fetchColumn()!==1) throw new RuntimeException('ANALYSIS_BUSY');
     try {
         if ($restart) $this->queueAnalysis($callId);
         $q=$this->db->prepare("SELECT j.id job_id,j.status job_status,c.* FROM call_processing_jobs j JOIN call_records c ON c.id=j.call_id WHERE c.id=:id AND j.job_type='analyze' LIMIT 1");
         $q->execute([':id'=>$callId]); $call=$q->fetch();
         if (!$call) throw new RuntimeException('CALL_NOT_FOUND');
         if (!$restart && $call['job_status']==='completed') return;
         $this->db->prepare("UPDATE call_processing_jobs SET status='processing',locked_at=NOW() WHERE id=:id")->execute([':id'=>$call['job_id']]);
         $this->db->prepare("UPDATE call_records SET analysis_status='processing' WHERE id=:id")->execute([':id'=>$callId]);
         try { $this->processOne($call); }
         catch (Throwable $e) { $this->markFailure($call,$e); throw $e; }
     } finally {
         $q=$this->db->prepare('SELECT RELEASE_LOCK(:name)');$q->execute([':name'=>$lock]);
     }
 }
 private function fetchCalls(int $page,array $where=[]):array{return (new CallsApiClient($this->config))->page($page,$where);}
 private function upsert(array $c):void{$x=trim((string)($c['id']??''));if($x===''||!preg_match('/^[A-Za-z0-9._:-]{1,160}$/',$x))return;$match=$this->matcher->match($c);$email=strtolower(trim((string)($c['email']??'')));$q=$this->db->prepare("INSERT INTO call_records(provider,external_id,lead_id,user_id,agent_external_key,contact_phone,normalized_phone,direction,started_at,ended_at,duration,hangup_cause,call_price,link_status,recording_url_hash) VALUES('api4com',:x,:l,:u,:a,:p,:n,:direction,:s,:e,:du,:h,:price,:link,:rh) ON DUPLICATE KEY UPDATE lead_id=IF(link_status='manual',lead_id,VALUES(lead_id)),user_id=COALESCE(VALUES(user_id),user_id),contact_phone=VALUES(contact_phone),normalized_phone=VALUES(normalized_phone),direction=VALUES(direction),link_status=IF(link_status='manual',link_status,VALUES(link_status)),updated_at=NOW()");$q->execute([':x'=>$x,':l'=>$match['lead_id'],':u'=>$match['user_id'],':a'=>$email?:null,':p'=>$match['contact_phone'],':n'=>$match['normalized_phone'],':direction'=>$match['direction'],':s'=>$this->date($c['started_at']??null),':e'=>$this->date($c['ended_at']??null),':du'=>max(0,(int)($c['duration']??0)),':h'=>mb_substr((string)($c['hangup_cause']??''),0,120),':price'=>is_numeric($c['call_price']??null)?$c['call_price']:null,':link'=>$match['link_status'],':rh'=>hash('sha256',(string)$c['record_url'])]);$id=(int)$this->db->query("SELECT id FROM call_records WHERE provider='api4com' AND external_id=".$this->db->quote($x))->fetchColumn();$this->db->prepare("INSERT IGNORE INTO call_processing_jobs(call_id,job_type,status) VALUES(:id,'analyze','pending')")->execute([':id'=>$id]);$this->history($id);}
 private function matchLead(?string $phone):array{if(!$phone)return[null,'unmatched'];$ids=[];foreach($this->db->query('SELECT id,phone,whatsapp FROM leads')->fetchAll()as$r)if(PhoneNormalizer::canonical($r['phone']??null)===$phone||PhoneNormalizer::canonical($r['whatsapp']??null)===$phone)$ids[]=(int)$r['id'];$ids=array_values(array_unique($ids));return count($ids)===1?[$ids[0],'matched']:[null,count($ids)>1?'ambiguous':'unmatched'];}
 private function markFailure(array $c,Throwable $e):void
 {
     $code=CallFailure::code($e);
     CallFailure::log('analysis',$e);
     $this->db->prepare("UPDATE call_processing_jobs SET status='failed',attempts=attempts+1,locked_at=NULL,available_at=DATE_ADD(NOW(),INTERVAL 15 MINUTE),last_error_code=:e WHERE id=:id")->execute([':e'=>$code,':id'=>$c['job_id']]);
     $this->db->prepare("UPDATE call_records SET analysis_status='failed',last_error_code=:e WHERE id=:id")->execute([':e'=>$code,':id'=>$c['id']]);
 }
 private function process(int $limit):int
 {
     $rows=$this->db->query("SELECT c.id FROM call_processing_jobs j JOIN call_records c ON c.id=j.call_id WHERE j.job_type='analyze' AND ((j.status IN('pending','failed') AND j.available_at<=NOW()) OR (j.status='processing' AND j.locked_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE))) ORDER BY j.id LIMIT ".max(1,min(10,$limit)))->fetchAll();
     $done=0;
     foreach ($rows as $call) {
         try { $this->processQueued((int)$call['id']); $done++; }
         catch (Throwable $e) { CallFailure::log('queue',$e); }
     }
     return $done;
 }
 private function processOne(array $c): void
 {
     $stored=(new CallRecordingArchive($this->db,$this->config))->archive((int)$c['id']);
     $profile=$this->db->query("SELECT * FROM ai_provider_configs WHERE is_active=1 AND enabled=1 LIMIT 1")->fetch();
     if (!$profile) throw new RuntimeException('AI_NOT_CONFIGURED');
     $provider=(string)$profile['provider'];
     $key=trim((string)$this->config->get($provider,'api_key',''));
     $model=trim((string)$profile['model']);
     if ($key==='' || $model==='') throw new RuntimeException('AI_NOT_CONFIGURED');
     $limit=max(1048576,min(104857600,(int)($profile['max_audio_bytes']??14680064)));
     if ((int)$stored['recording_size']>$limit) throw new RuntimeException('AUDIO_TOO_LARGE');
     $ai=$provider==='openrouter'
         ? new OpenRouterAnalyzer($profile['base_url'],$key,$model)
         : new GeminiCallAnalyzer($profile['base_url'],$key,$model,120,$limit);
     $phone=(string)($stored['contact_phone']??$stored['normalized_phone']??'');
     $context=['from'=>($stored['direction']??'')==='inbound'?$phone:'','to'=>($stored['direction']??'')==='inbound'?'':$phone,'duration'=>$stored['duration']??0,'started_at'=>$stored['started_at']??''];
     $analysis=$ai->analyze($stored['absolute_recording_path'],$stored['recording_mime'],$context);
     $this->saveAnalysis((int)$c['id'],$analysis);
 }
 private function history(int$id):void{$c=$this->db->query('SELECT * FROM call_records WHERE id='.$id)->fetch();if(!$c||!$c['lead_id']||$c['lead_history_id'])return;$this->db->prepare("INSERT INTO lead_history(lead_id,user_id,type,description,created_at) VALUES(:l,:u,'ligacao','Ligação sincronizada automaticamente.',COALESCE(:at,NOW()))")->execute([':l'=>$c['lead_id'],':u'=>$c['user_id'],':at'=>$c['started_at']]);$h=(int)$this->db->lastInsertId();$this->db->prepare('UPDATE call_records SET lead_history_id=:h WHERE id=:id AND lead_history_id IS NULL')->execute([':h'=>$h,':id'=>$id]);$this->db->prepare('UPDATE leads SET last_contact_at=CASE WHEN last_contact_at IS NULL OR last_contact_at<:a THEN :b ELSE last_contact_at END WHERE id=:id')->execute([':a'=>$c['started_at'],':b'=>$c['started_at'],':id'=>$c['lead_id']]);}
 private function setting(string$k,string$d):string{$s=$this->db->prepare('SELECT value FROM settings WHERE `key`=:k');$s->execute([':k'=>$k]);$v=$s->fetchColumn();return$v===false?$d:(string)$v;}private function date(mixed$v):?string{if(!$v)return null;$t=strtotime((string)$v);return$t?date('Y-m-d H:i:s',$t):null;}
}
