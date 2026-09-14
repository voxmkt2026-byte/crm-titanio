<?php
declare(strict_types=1);
require_once APP_PATH.'/core/Controller.php';
require_once APP_PATH.'/models/IntegrationCredential.php';
require_once APP_PATH.'/services/Calls/PhoneService.php';

final class PhoneController extends Controller {
    private function service():PhoneService {
        return new PhoneService(Database::getInstance(),new IntegrationConfig(new IntegrationCredential(),SecretVault::fromFile(ROOT_PATH.'/config/integration.key'),IntegrationConfig::legacyEnvironment(ROOT_PATH.'/ligacao/.env')));
    }
    private function payload():array {
        if(str_contains(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')){
            $raw=file_get_contents('php://input');if(!is_string($raw)||strlen($raw)>16384)throw new PhoneException('INVALID_JSON','Solicitação inválida.',400);
            try{$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new PhoneException('INVALID_JSON','JSON inválido.',400);}if(!is_array($data)||array_is_list_compat_phone($data))throw new PhoneException('INVALID_JSON','Solicitação inválida.',400);return $data;
        }
        return $_POST;
    }
    private function guard(bool $mutation=false,bool $ownedOperation=false,bool $admin=false,array $data=[]):void {
        header('Cache-Control: no-store, private');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
        $authenticated=Auth::check();
        if($authenticated)Auth::clearPermissionCache();
        $authorized=$authenticated&&($ownedOperation||($admin?(Auth::hasRole(['admin'])&&Auth::can('calls.manage')):Auth::can('calls.dial')));
        PhoneRequestGuard::check($authenticated,$authorized,$mutation,!$mutation||Csrf::verify(is_string($data['csrf_token']??null)?$data['csrf_token']:null),$_SERVER,BASE_URL);
        if(!$ownedOperation){$q=Database::getInstance()->prepare('SELECT active FROM users WHERE id=?');$q->execute([Auth::id()]);if((int)$q->fetchColumn()!==1)throw new PhoneException('DIAL_FORBIDDEN','Usuário inativo.',403);}
    }
    private function respond(callable $action,bool $mutation=false,bool $owned=false,bool $admin=false):void {
        try{$data=$mutation?$this->payload():[];$this->guard($mutation,$owned,$admin,$data);$result=$action($data);$this->json(['ok'=>true,'data'=>$result]);}
        catch(PhoneException $e){$this->json(['ok'=>false,'error'=>['code'=>$e->publicCode,'message'=>$e->getMessage()]],$e->status);}
        catch(Throwable){$this->json(['ok'=>false,'error'=>['code'=>'PHONE_UNAVAILABLE','message'=>'Telefonia indisponível. Verifique a configuração e a migração com o administrador.']],503);}
    }
    public function index():void {try{$this->guard();$this->view('phone/popup',['phoneBaseUrl'=>url('telefonia'),'phoneCsrf'=>Csrf::token()],null);}catch(PhoneException $e){$this->json(['ok'=>false,'error'=>['code'=>$e->publicCode,'message'=>$e->getMessage()]],$e->status);}}
    public function accounts():void {$this->respond(fn()=>$this->service()->accounts((int)Auth::id()));}
    public function context():void {$this->respond(fn()=>$this->service()->context((int)Auth::id(),Auth::hasRole(['admin','supervisor']),isset($_GET['lead_id'])?(int)$_GET['lead_id']:null,(string)($_GET['phone']??'')));}
    public function config():void {$this->respond(fn()=>$this->service()->sipConfig((int)Auth::id(),(string)($_GET['account']??'')));}
    public function extensions():void {$this->respond(fn()=>$this->service()->extensions((int)Auth::id(),(string)($_GET['account']??'')));}
    public function dial():void {$this->respond(fn($p)=>$this->service()->dial((int)Auth::id(),Auth::hasRole(['admin','supervisor']),(string)($p['account']??''),(string)($p['phone']??''),isset($p['lead_id'])?(int)$p['lead_id']:null,(string)($p['request_id']??''),is_string($p['registered_extension']??null)?$p['registered_extension']:''),true);}
    public function hangup():void {$this->respond(fn($p)=>$this->service()->hangup((int)Auth::id(),(string)($p['attempt_id']??'')),true,true);}
    public function recover():void {$this->respond(fn($p)=>$this->service()->recover((int)Auth::id(),(string)($p['attempt_id']??'')),true,true);}
    public function state():void {$this->respond(function(){
        $state=$this->service()->state((int)Auth::id(),isset($_GET['attempt_id'])?(string)$_GET['attempt_id']:null);
        $q=Database::getInstance()->prepare('SELECT active FROM users WHERE id=?');$q->execute([Auth::id()]);
        $state['can_dial']=Auth::can('calls.dial')&&(int)$q->fetchColumn()===1;
        if(!$state['can_dial']&&array_key_exists('available',$state))$state['available']=false;
        return $state;
    },false,true);}
    public function event():void {$this->respond(function($p){if(($p['event']??'')!=='ended')throw new PhoneException('INVALID_EVENT','Evento inválido.');return $this->service()->hangup((int)Auth::id(),(string)($p['attempt_id']??''));},true,true);}
    public function assign():void {$this->respond(fn($p)=>$this->service()->assign((int)Auth::id(),(int)($p['user_id']??0),(string)($p['account']??''),(string)($p['extension']??'')),true,false,true);}
    public function resource(string $name):void {
        try{$this->guard();$files=['libwebphone.js'=>'vendor/libwebphone.js','webphone.js'=>'webphone.js','webphone-core.mjs'=>'webphone-core.mjs'];if(!isset($files[$name]))throw new PhoneException('RESOURCE_NOT_FOUND','Recurso não encontrado.',404);$path=ROOT_PATH.'/ligacao/assets/'.$files[$name];if(!is_file($path))throw new PhoneException('RESOURCE_NOT_FOUND','Recurso não encontrado.',404);header('Content-Type: text/javascript; charset=utf-8');readfile($path);}
        catch(PhoneException $e){$this->json(['ok'=>false,'error'=>['code'=>$e->publicCode,'message'=>$e->getMessage()]],$e->status);}
    }
}
/** PHP 8.0 compatible JSON-object check. */
function array_is_list_compat_phone(array $value):bool {return $value!==[]&&array_keys($value)===range(0,count($value)-1);}
