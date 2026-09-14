<?php
declare(strict_types=1);
require_once APP_PATH.'/core/Controller.php';
require_once APP_PATH.'/models/IntegrationCredential.php';
require_once APP_PATH.'/services/Calls/PhoneService.php';
require_once APP_PATH.'/services/Calls/CopilotService.php';
final class CopilotController extends Controller {
 private int $actor=0;
 private bool $viewAll=false;
 private function config():IntegrationConfig{return new IntegrationConfig(new IntegrationCredential(),SecretVault::fromFile(ROOT_PATH.'/config/integration.key'),IntegrationConfig::legacyEnvironment(ROOT_PATH.'/ligacao/.env'));}
 private function service():CopilotService{return new CopilotService(Database::getInstance(),$this->config());}
 private function payload():array{if(!str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))throw new CopilotException('INVALID_JSON','Envie JSON.',400);$raw=file_get_contents('php://input',false,null,0,65537);if(!is_string($raw)||strlen($raw)>65536)throw new CopilotException('BODY_TOO_LARGE','Solicitação muito grande.',413);try{$p=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new CopilotException('INVALID_JSON','JSON inválido.',400);}if(!is_array($p)||($p!==[]&&array_keys($p)===range(0,count($p)-1)))throw new CopilotException('INVALID_JSON','Objeto JSON esperado.',400);return $p;}
 private function guard(bool $mutation,array $data=[],bool $admin=false):void{
  header('Cache-Control: no-store, private');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
  $authenticated=Auth::check();if($authenticated)Auth::clearPermissionCache();$allowed=$authenticated&&($admin?(Auth::hasRole(['admin'])&&Auth::can('calls.manage')):(Auth::can('calls.dial')&&Auth::can('calls.ai')));
  PhoneRequestGuard::check($authenticated,$allowed,$mutation,!$mutation||Csrf::verify(is_string($data['csrf_token']??null)?$data['csrf_token']:null),$_SERVER,BASE_URL);
  $q=Database::getInstance()->prepare('SELECT active FROM users WHERE id=?');$q->execute([Auth::id()]);if((int)$q->fetchColumn()!==1)throw new CopilotException('AI_FORBIDDEN','Usuário inativo.',403);
 }
 private function respond(callable $action,bool $mutation=false):void{try{$p=$mutation?$this->payload():[];$this->guard($mutation,$p);$this->actor=(int)Auth::id();$this->viewAll=Auth::hasRole(['admin','supervisor']);if(session_status()===PHP_SESSION_ACTIVE)session_write_close();$this->json(['ok'=>true,'data'=>$action($p)]);}catch(CopilotException|PhoneException $e){$this->json(['ok'=>false,'error'=>['code'=>$e->publicCode,'message'=>$e->getMessage()]],$e->status);}catch(Throwable){$this->json(['ok'=>false,'error'=>['code'=>'AI_UNAVAILABLE','message'=>'Copiloto indisponível. A ligação continua normalmente. Verifique a configuração e a migração com o administrador.']],503);}}
 private function all():bool{return $this->viewAll;}
 public function configuration():void{$this->respond(fn()=>$this->service()->configuration());}
 public function start():void{$this->respond(fn($p)=>$this->service()->start($this->actor,$this->all(),is_string($p['attempt_id']??null)?$p['attempt_id']:'',($p['consent']??false)===true,is_string($p['request_id']??null)?$p['request_id']:''),true);}
 public function segments():void{$this->respond(fn($p)=>$this->service()->segments($this->actor,$this->all(),is_string($p['session_id']??null)?$p['session_id']:'',is_string($p['batch_id']??null)?$p['batch_id']:'',is_array($p['segments']??null)?$p['segments']:[]),true);}
 public function finish():void{$this->respond(fn($p)=>$this->service()->finish($this->actor,$this->all(),is_string($p['session_id']??null)?$p['session_id']:'',($p['incomplete']??false)===true),true);}
 public function settings():void{try{$this->guard(false,[],true);$c=new CopilotConfig($this->config());$this->view('settings/copilot',['pageTitle'=>'Copiloto de vendas','copilotSettings'=>$c->settings(),'copilotConfigured'=>$c->key()!=='']);}catch(CopilotException|PhoneException $e){$this->json(['ok'=>false,'error'=>['code'=>$e->publicCode,'message'=>$e->getMessage()]],$e->status);}}
 public function saveSettings():void{
  $json=str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json');$db=Database::getInstance();
  try{$p=$json?$this->payload():$_POST;$this->guard(true,$p,true);$key=is_string($p['api_key']??null)?trim($p['api_key']):'';if($key!==''){$q=$db->prepare('SELECT password FROM users WHERE id=?');$q->execute([Auth::id()]);if(!password_verify(is_string($p['current_password']??null)?$p['current_password']:'',(string)$q->fetchColumn()))throw new CopilotException('PASSWORD_REQUIRED','Confirme sua senha atual para substituir a chave.',403);}
   $p['api_key']=$key;$db->beginTransaction();(new CopilotConfig($this->config()))->save($p,(int)Auth::id());$db->commit();if($json)$this->json(['ok'=>true,'data'=>['saved'=>true]]);flash('success','Configuração do copiloto salva.');$this->redirect('configuracoes/copiloto');
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$known=$e instanceof CopilotException||$e instanceof PhoneException;$message=$known?$e->getMessage():'Não foi possível salvar a configuração.';if($json)$this->json(['ok'=>false,'error'=>['code'=>$known?$e->publicCode:'AI_UNAVAILABLE','message'=>$message]],$known?$e->status:503);flash('error',$message);$this->redirect('configuracoes/copiloto');}
 }
}
