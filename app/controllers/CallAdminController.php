<?php
require_once APP_PATH.'/core/Controller.php';require_once APP_PATH.'/models/IntegrationCredential.php';require_once APP_PATH.'/models/AiProviderConfig.php';require_once APP_PATH.'/models/Setting.php';require_once APP_PATH.'/models/User.php';require_once APP_PATH.'/services/Calls/CallSyncService.php';require_once APP_PATH.'/services/Calls/CallDashboardMetrics.php';
final class CallAdminController extends Controller {
 private function guard():void{$this->requireLogin();if(!Auth::hasRole(['admin'])||!Auth::can('calls.manage')){http_response_code(403);exit;}}
 private function config():IntegrationConfig{return new IntegrationConfig(new IntegrationCredential(),SecretVault::fromFile(ROOT_PATH.'/config/integration.key'),IntegrationConfig::legacyEnvironment(ROOT_PATH.'/ligacao/.env'));}
 public function index():void{$this->guard();$c=$this->config();$db=Database::getInstance();$syncState=['status'=>'idle','last_success_at'=>null,'last_counts'=>[],'last_error_code'=>null];try{$row=$db->query("SELECT * FROM call_sync_state WHERE provider='api4com' LIMIT 1")->fetch();if($row){$syncState=$row;$syncState['last_counts']=json_decode((string)($row['last_counts']??''),true)?:[];}}catch(Throwable){}try{$metrics=(new CallDashboardMetrics($db))->summary();}catch(Throwable){$metrics=['visible_calls'=>0,'matched_calls'=>0,'analyzed_calls'=>0,'pending_calls'=>0,'failed_calls'=>0,'average_score'=>0.0];}$this->view('settings/calls',['pageTitle'=>'Integrações de Ligações','profiles'=>(new AiProviderConfig())->profiles(),'settings'=>(new Setting())->allAsMap(),'configured'=>['api4com'=>$c->isConfigured('api4com','token'),'gemini'=>$c->isConfigured('gemini','api_key'),'openrouter'=>$c->isConfigured('openrouter','api_key')],'syncState'=>$syncState,'metrics'=>$metrics]);}
 public function sync():void{$this->guard();Csrf::verifyRequest();$ajax=strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest'||str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT']??'')),'application/json');try{set_time_limit(60);$service=new CallSyncService(Database::getInstance(),$this->config());if($ajax&&$this->input('page',null)!==null){$result=$service->syncPage((int)$this->input('page',1));$this->json(['success'=>true]+$result);}$pages=max(1,min(50,(int)$this->input('pages',10)));$jobs=max(0,min(10,(int)$this->input('jobs',0)));$result=$service->run($pages,$jobs);flash('success',sprintf('Sincronização concluída: %d ligação(ões) encontrada(s) e %d análise(s) processada(s).',(int)$result['synced'],(int)$result['processed']));}catch(Throwable$e){CallFailure::log('sync',$e);$message=CallFailure::message($e);if($ajax)$this->json(['success'=>false,'message'=>$message],422);flash('error',$message);}$this->redirect('configuracoes/ligacoes');}
 public function importLegacy():void{$this->guard();Csrf::verifyRequest();try{set_time_limit(300);$directory=ROOT_PATH.'/ligacao/storage/analyses';if(!is_dir($directory))throw new RuntimeException('LEGACY_DIRECTORY_MISSING');$pages=max(1,min(100,(int)$this->input('pages',20)));$result=(new CallSyncService(Database::getInstance(),$this->config()))->importLegacy($directory,$pages,false);flash('success',sprintf('Importação concluída: %d análise(s) antiga(s) associada(s); %d caixa(s) postal(is) ignorada(s).',(int)$result['imported'],(int)$result['voicemail']));}catch(Throwable$e){flash('error',$e->getMessage()==='LEGACY_DIRECTORY_MISSING'?'A pasta ligacao/storage/analyses não foi encontrada no servidor.':'Não foi possível importar as análises antigas. Confira o token e o log.');}$this->redirect('configuracoes/ligacoes');}
 public function save():void{$this->guard();Csrf::verifyRequest();$u=(new User())->find((int)Auth::id());if(!$u||!password_verify((string)$this->input('current_password',''),$u['password'])){flash('error','Senha atual inválida.');$this->redirect('configuracoes/ligacoes');return;}$p=(string)$this->input('provider','');if(!in_array($p,['api4com','gemini','openrouter'],true)){http_response_code(422);return;}$base=filter_var((string)$this->input('base_url',''),FILTER_VALIDATE_URL);if(!$base||parse_url($base,PHP_URL_SCHEME)!=='https'){flash('error','Informe uma URL HTTPS válida.');$this->redirect('configuracoes/ligacoes');return;}$c=$this->config();$c->setPublic($p,'base_url',rtrim($base,'/'),(int)Auth::id());$c->setSecret($p,$p==='api4com'?'token':'api_key',trim((string)$this->input('secret','')),(int)Auth::id());if($p!=='api4com')(new AiProviderConfig())->saveProfile($p,rtrim($base,'/'),trim((string)$this->input('model','')),max(1048576,min(104857600,(int)$this->input('max_audio_bytes',14680064))),true,$this->input('is_active','')==='1');else(new Setting())->setMany(['calls_audio_retention_days'=>(string)max(0,min(3650,(int)$this->input('retention_days',365)))]);log_activity('calls_integration_updated','Configuração da integração '.$p.' atualizada sem registrar credenciais.');flash('success','Integração salva com segurança.');$this->redirect('configuracoes/ligacoes');}
 public function test(string $provider):void
 {
     $this->guard(); Csrf::verifyRequest();
     if (!in_array($provider,['api4com','gemini','openrouter'],true)) { http_response_code(422); return; }
     $c=$this->config();
     if ($provider==='api4com') {
         $messages=[]; $success=true;
         try { (new CallsApiClient($c))->page(1); $messages[]='Api4Com: conexão e token validados.'; }
         catch (Throwable $e) { $success=false; CallFailure::log('test_api',$e); $messages[]=CallFailure::message($e); }
         try { (new CallMediaStorage(STORAGE_PATH))->probe(); $messages[]='Armazenamento: escrita e integridade verificadas.'; }
         catch (Throwable $e) { $success=false; CallFailure::log('test_storage',$e); $messages[]=CallFailure::message($e); }
         flash($success?'success':'error',implode(' ',$messages));
         $this->redirect('configuracoes/ligacoes');
         return;
     }
     try {
         $base=rtrim((string)$c->get($provider,'base_url',$provider==='gemini'?'https://generativelanguage.googleapis.com/v1beta':'https://openrouter.ai/api/v1'),'/');
         $url=$base.'/models'; $headers=['Accept: application/json'];
         $key=trim((string)$c->get($provider,'api_key',''));
         if ($key==='') throw new RuntimeException('AI_NOT_CONFIGURED');
         if ($provider==='gemini') $headers[]='x-goog-api-key: '.$key;
         else $headers[]='Authorization: Bearer '.$key;
         $ch=curl_init($url);
         curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers]);
         $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
         if (!is_string($body) || $code<200 || $code>=300) throw new RuntimeException(in_array($code,[401,403],true)?'AI_NOT_CONFIGURED':'AI_ANALYSIS_NETWORK_FAILED');
         (new AiProviderConfig())->markTest($provider,true);
         flash('success','Conexão com '.$provider.' validada. A compatibilidade do modelo com áudio será verificada ao analisar.');
     } catch (Throwable $e) {
         (new AiProviderConfig())->markTest($provider,false,CallFailure::code($e));
         CallFailure::log('test_ai',$e);
         flash('error',CallFailure::message($e));
     }
     $this->redirect('configuracoes/ligacoes');
 }
}
