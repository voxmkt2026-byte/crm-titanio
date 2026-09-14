<?php
declare(strict_types=1);
require_once __DIR__.'/CallAccounts.php';
require_once __DIR__.'/PhoneNormalizer.php';
require_once __DIR__.'/PhoneRequestGuard.php';
require_once __DIR__.'/PhoneReconciler.php';
require_once __DIR__.'/CallsApiClient.php';
// Load reusable transport classes only; never execute the standalone bootstrap.
foreach (['AppException','Config','HttpResponse','HttpClientInterface','DownloadedFile','RemoteUrlPolicy','CurlHttpClient','WebphoneGateway','Api4ComWebphoneClient','WebphoneService'] as $class) {
    require_once dirname(__DIR__,3).'/ligacao/src/'.$class.'.php';
}

final class PhoneException extends RuntimeException {
    public function __construct(public string $publicCode, string $message, public int $status=422) { parent::__construct($message); }
}

final class PhoneService
{
    private Closure $gatewayFactory;
    private Closure $recordReader;
    public function __construct(private PDO $db, private IntegrationConfig $config, ?callable $gatewayFactory=null, ?callable $recordReader=null) {
        $this->recordReader=$recordReader!==null?Closure::fromCallable($recordReader):fn(string $account)=>(new CallsApiClient($this->config))->forAccount($account)->page(1);
        $this->gatewayFactory=$gatewayFactory!==null?Closure::fromCallable($gatewayFactory):function(string $account): \App\WebphoneGateway {
            $scoped=CallAccounts::forAccount($this->config,$account);
            $base=(string)$scoped->get('api4com','base_url','https://api.api4com.com/api/v1');
            $url=parse_url($base);
            if (!$url || ($url['scheme']??'')!=='https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) throw new PhoneException('ACCOUNT_NOT_CONFIGURED','Configure uma URL HTTPS válida para esta conta.',503);
            return new \App\Api4ComWebphoneClient(new \App\CurlHttpClient(),\App\Config::fromArray(['API4COM_BASE_URL'=>$base,'API4COM_TOKEN'=>$scoped->get('api4com','token','')]));
        };
    }
    public function ready():bool {try{$this->db->query('SELECT attempt_id FROM phone_attempts LIMIT 0');return true;}catch(PDOException){return false;}}
    private function requireReady():void {if(!$this->ready())throw new PhoneException('MIGRATION_REQUIRED','Peça ao administrador para aplicar migration_native_phone.sql.',503);}
    private function account(string $account):string {try{return CallAccounts::key($account);}catch(InvalidArgumentException){throw new PhoneException('INVALID_ACCOUNT','Conta inválida.');}}
    private function gateway(string $account):\App\WebphoneGateway {
        $this->account($account);
        if(trim((string)CallAccounts::forAccount($this->config,$account)->get('api4com','token',''))==='')throw new PhoneException('ACCOUNT_NOT_CONFIGURED','Esta conta ainda não foi configurada.',503);
        return ($this->gatewayFactory)($account);
    }
    private function extension(int $userId,string $account):string {
        $this->account($account);
        $q=$this->db->prepare("SELECT external_key FROM call_agent_mappings WHERE user_id=? AND provider=? AND external_key LIKE 'sip:%'");$q->execute([$userId,$account]);$rows=$q->fetchAll(PDO::FETCH_COLUMN);
        if(count($rows)!==1 || !preg_match('/^sip:([0-9]{1,10})$/D',(string)$rows[0],$m))throw new PhoneException('EXTENSION_REQUIRED','Peça ao administrador para atribuir um único ramal nesta conta.',403);
        return $m[1];
    }
    public function accounts(int $userId):array {
        $accounts=[];$ready=$this->ready();$available=false;
        foreach(CallAccounts::all($this->config) as $account){$extension=null;try{$extension=$this->extension($userId,$account['key']);}catch(PhoneException|PDOException){}
            $accounts[]=['key'=>$account['key'],'name'=>$account['name'],'configured'=>$account['configured'],'extension'=>$extension];
            $available=$available||($ready&&$account['configured']&&$extension!==null);
        }
        return ['accounts'=>$accounts,'available'=>$available];
    }
    public function context(int $userId,bool $canViewAll,?int $leadId,string $phone):array {
        $lead=null;$phones=[];$candidates=[];
        if(!$leadId&&PhoneNormalizer::canonical($phone)!==null){
            $q=$this->db->prepare('SELECT * FROM leads'.($canViewAll?'':' WHERE assigned_to=?').' ORDER BY id');$q->execute($canViewAll?[]:[$userId]);
            while($candidate=$q->fetch(PDO::FETCH_ASSOC)){
                foreach($this->leadPhones($candidate) as $entry){if(array_intersect(PhoneNormalizer::candidates($phone),PhoneNormalizer::candidates($entry['number']))){$candidates[]=['id'=>(int)$candidate['id'],'name'=>(string)$candidate['name'],'phone'=>PhoneNormalizer::canonical($phone)];break;}}
                if(count($candidates)>=20)break;
            }
            $q->closeCursor();
            if(count($candidates)===1){$leadId=$candidates[0]['id'];$candidates=[];}
        }
        if($leadId){$q=$this->db->prepare('SELECT * FROM leads WHERE id=?');$q->execute([$leadId]);$lead=$q->fetch(PDO::FETCH_ASSOC);
            if(!$lead || (!$canViewAll&&(int)$lead['assigned_to']!==$userId))throw new PhoneException('LEAD_NOT_FOUND','Contato não encontrado.',404);
            $phones=$this->leadPhones($lead);
            if(trim($phone)==='')$phone=$phones[0]['number']??'';
        }
        $normalized=PhoneNormalizer::canonical($phone);
        if($normalized===null)throw new PhoneException('INVALID_PHONE','Informe um telefone válido com DDD.');
        if($lead){$matches=false;foreach($phones as $entry){if(array_intersect(PhoneNormalizer::candidates($normalized),PhoneNormalizer::candidates($entry['number'])))$matches=true;}if(!$matches){$lead=null;$phones=[];}}
        return ['lead_id'=>$lead?(int)$lead['id']:null,'name'=>$lead?(string)$lead['name']:'','phone'=>$normalized,'phones'=>$lead?$phones:[['label'=>'Telefone','number'=>$normalized]],'avatar'=>null,'candidates'=>$candidates];
    }
    private function leadPhones(array $lead):array {
        $phones=[];
        foreach(['phone'=>'Telefone','whatsapp'=>'WhatsApp'] as $key=>$label){$raw=(string)($lead[$key]??'');$digits=preg_replace('/\D+/','',$raw)??'';$ddd=preg_replace('/\D+/','',(string)($lead['ddd']??''))??'';if(strlen($ddd)===2&&in_array(strlen($digits),[8,9],true))$raw=$ddd.$digits;$number=PhoneNormalizer::canonical($raw);if($number!==null&&!in_array($number,array_column($phones,'number'),true))$phones[]=['label'=>$label,'number'=>$number];}
        return $phones;
    }
    public function sipConfig(int $userId,string $account):array {
        $this->requireReady();$extension=$this->extension($userId,$account);$gateway=$this->gateway($account);$row=$gateway->findExtension($extension);
        if(!$row || (string)($row['ramal']??'')!==$extension)throw new PhoneException('EXTENSION_INVALID','O ramal atribuído não foi encontrado nesta conta.',503);
        $domain=(string)($row['domain']??'');$password=(string)($row['senha']??'');
        if(!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?$/D',$domain)||$password==='')throw new PhoneException('SIP_CONFIG_INVALID','Configuração SIP incompleta.',503);
        return ['extension'=>$extension,'domain'=>$domain,'password'=>$password,'websocket_url'=>'wss://'.$domain.':6443','recording_enabled'=>(bool)($row['gravar_audio']??false)];
    }
    public function extensions(int $userId,string $account):array {
        $this->requireReady();$this->extension($userId,$account);$gateway=$this->gateway($account);
        return ['extensions'=>method_exists($gateway,'listExtensions')?$gateway->listExtensions():[]];
    }
    private function byRequest(int $userId,string $requestId):?array {$q=$this->db->prepare('SELECT * FROM phone_attempts WHERE user_id=? AND request_id=?');$q->execute([$userId,$requestId]);return $q->fetch(PDO::FETCH_ASSOC)?:null;}
    private function owned(int $userId,string $id):array {$this->requireReady();$q=$this->db->prepare('SELECT * FROM phone_attempts WHERE user_id=? AND attempt_id=?');$q->execute([$userId,$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new PhoneException('ATTEMPT_NOT_FOUND','Tentativa não encontrada.',404);return $row;}
    private function publicAttempt(array $row):array {return ['attempt_id'=>$row['attempt_id'],'account'=>$row['account'],'extension'=>$row['extension'],'state'=>$row['state'],'id'=>$row['provider_call_id'],'phone'=>$row['phone'],'lead_id'=>$row['lead_id']!==null?(int)$row['lead_id']:null,'created_at'=>$row['created_at'],'ended_at'=>$row['ended_at'],'error_code'=>$row['error_code']];}
    private function replay(array $row,string $account,string $phone,?int $leadId):array {
        if($row['account']!==$account||$row['phone']!==$phone||($row['lead_id']===null?null:(int)$row['lead_id'])!==$leadId)throw new PhoneException('REQUEST_CONFLICT','Esta solicitação já pertence a outro destino.',409);
        if(in_array($row['state'],['reserved','uncertain'],true))throw new PhoneException('CALL_UNCERTAIN','A operadora ainda não confirmou esta tentativa. Não disque novamente.',409);
        if($row['state']==='ended')throw new PhoneException('ATTEMPT_ENDED','Esta tentativa já foi encerrada. Prepare uma nova ligação.',409);
        return $this->publicAttempt($row);
    }
    public function dial(int $userId,bool $canViewAll,string $account,string $phone,?int $leadId,string $requestId,string $registeredExtension=''):array {
        $this->requireReady();$this->account($account);
        if(!preg_match('/^[A-Za-z0-9_-]{12,100}$/D',$requestId))throw new PhoneException('INVALID_REQUEST_ID','Identificador da solicitação inválido.');
        $context=$this->context($userId,$canViewAll,$leadId,$phone);$phone=$context['phone'];$leadId=$context['lead_id'];
        if(count($context['candidates'])>1)throw new PhoneException('AMBIGUOUS_CONTACT','Escolha o contato correto antes de ligar.',422);
        if($existing=$this->byRequest($userId,$requestId))return $this->replay($existing,$account,$phone,$leadId);
        $extension=$this->extension($userId,$account);
        if($registeredExtension!==$extension)throw new PhoneException('EXTENSION_CHANGED','Seu ramal foi alterado. Desative e reative o telefone antes de ligar.',409);
        $gateway=$this->gateway($account);
        // Validate ownership at the provider before any paid request.
        $row=$gateway->findExtension($extension);if(!$row||(string)($row['ramal']??'')!==$extension)throw new PhoneException('EXTENSION_INVALID','Ramal não encontrado nesta conta.',503);
        $id=bin2hex(random_bytes(16));$correlation=bin2hex(random_bytes(32));
        try{
            $this->db->beginTransaction();
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$this->db->prepare('SELECT id FROM users WHERE id=? AND active=1'.$lock);$q->execute([$userId]);if(!$q->fetchColumn())throw new PhoneException('DIAL_FORBIDDEN','Usuário inativo.',403);
            if($this->extension($userId,$account)!==$extension)throw new PhoneException('EXTENSION_CHANGED','O ramal foi alterado. Atualize o telefone.',409);
            $fresh=$this->context($userId,$canViewAll,$leadId,$phone);$leadId=$fresh['lead_id'];if(count($fresh['candidates'])>1)throw new PhoneException('AMBIGUOUS_CONTACT','Escolha o contato correto antes de ligar.',422);
            $this->db->prepare("INSERT INTO phone_attempts(attempt_id,request_id,user_id,account,extension,lead_id,phone,state,correlation_token,active_user,active_extension) VALUES(?,?,?,?,?,?,?,'reserved',?,?,?)")->execute([$id,$requestId,$userId,$account,$extension,$leadId,$phone,$correlation,$userId,$account.':'.$extension]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException){if($existing=$this->byRequest($userId,$requestId))return $this->replay($existing,$account,$phone,$leadId);if(in_array((string)$e->getCode(),['23000','23505','19'],true))throw new PhoneException('CALL_ACTIVE','Já existe uma tentativa ativa para este usuário ou ramal.',409);}throw $e;}
        try{
            $response=$gateway->dial($extension,$phone,['gateway'=>'titanium-crm','crm_attempt_id'=>$id,'crm_correlation'=>$correlation]);
            $providerId=$response['id']??null;
            if(!is_string($providerId)||!preg_match('/^[A-Za-z0-9._:-]{1,160}$/D',$providerId))throw new RuntimeException('Invalid provider ID');
            $this->db->prepare("UPDATE phone_attempts SET provider_call_id=?,state='dialing',updated_at=CURRENT_TIMESTAMP WHERE attempt_id=? AND state='reserved'")->execute([$providerId,$id]);
            return array_merge($response,$this->publicAttempt($this->owned($userId,$id)));
        }catch(Throwable){$this->db->prepare("UPDATE phone_attempts SET state='uncertain',error_code='CALL_UNCERTAIN',updated_at=CURRENT_TIMESTAMP WHERE attempt_id=? AND state='reserved'")->execute([$id]);throw new PhoneException('CALL_UNCERTAIN','A confirmação da operadora não chegou. A tentativa continua reservada; não disque novamente.',409);}
    }
    public function state(int $userId,?string $id=null):array {
        $this->requireReady();if($id!==null&&$id!=='')return $this->publicAttempt($this->owned($userId,$id));
        $q=$this->db->prepare('SELECT * FROM phone_attempts WHERE active_user=? AND user_id=?');$q->execute([$userId,$userId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return ['available'=>!$row&&$this->accounts($userId)['available'],'attempt'=>$row?$this->publicAttempt($row):null];
    }
    public function hangup(int $userId,string $id):array {
        $row=$this->owned($userId,$id);if($row['state']==='ended')return ['ended'=>true];
        if(!$row['provider_call_id'])throw new PhoneException('CALL_UNCERTAIN','Ainda não há ID confirmado para encerrar. Aguarde a reconciliação da operadora.',409);
        try{$this->gateway($row['account'])->hangup($row['provider_call_id']);}
        catch(Throwable $error){
            // Api4Com documents that this cancellation ID is absent after hangup.
            // Its CDR has a different ID and may not exist yet; do not wait for it.
            // Only the provider's explicit absence response is idempotent success.
            $alreadyEnded=$error instanceof \App\AppException&&$error->publicCode()==='CALL_NOT_FOUND'&&$error->status()===404;
            if(!$alreadyEnded){
                $this->db->prepare("UPDATE phone_attempts SET state='uncertain',error_code='HANGUP_UNCERTAIN',updated_at=CURRENT_TIMESTAMP WHERE attempt_id=? AND state<>'ended'")->execute([$id]);
                throw new PhoneException('HANGUP_UNCERTAIN','O encerramento ainda não foi confirmado pela operadora.',409);
            }
        }
        $this->db->prepare("UPDATE phone_attempts SET state='ended',active_user=NULL,active_extension=NULL,error_code=NULL,ended_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE attempt_id=?")->execute([$id]);return ['ended'=>true];
    }
    public function recover(int $userId,string $id):array {
        $row=$this->owned($userId,$id);if($row['state']==='ended')return ['ended'=>true];
        try{$records=($this->recordReader)($row['account']);}
        catch(Throwable){throw new PhoneException('CONFIRMATION_UNAVAILABLE','Não foi possível consultar a operadora agora. A tentativa foi preservada; confira token e conexão.',503);}
        foreach($records as $record){
            if(!is_array($record))continue;
            $external=$record['id']??null;$end=$record['ended_at']??null;$meta=$record['metadata']??[];
            $exact=is_string($external)&&$external!==''&&$external===$row['provider_call_id'];
            $correlated=is_array($meta)&&($meta['crm_attempt_id']??null)===$id&&is_string($meta['crm_correlation']??null)&&hash_equals($row['correlation_token'],$meta['crm_correlation']);
            if((!$exact&&!$correlated)||!is_string($end)||strtotime($end)===false)continue;
            $this->db->prepare("UPDATE phone_attempts SET state='ended',active_user=NULL,active_extension=NULL,error_code=NULL,ended_at=?,updated_at=CURRENT_TIMESTAMP WHERE attempt_id=? AND user_id=? AND state<>'ended'")->execute([date('Y-m-d H:i:s',strtotime($end)),$id,$userId]);
            return ['ended'=>true];
        }
        return ['ended'=>false];
    }
    public function assign(int $adminId,int $userId,string $account,string $extension):array {
        $this->requireReady();$this->account($account);if(!preg_match('/^[0-9]{1,10}$/D',$extension))throw new PhoneException('INVALID_EXTENSION','Ramal inválido.');
        $row=$this->gateway($account)->findExtension($extension);if(!$row||(string)($row['ramal']??'')!==$extension)throw new PhoneException('EXTENSION_INVALID','Ramal não encontrado nesta conta.',503);
        $this->db->beginTransaction();
        try{
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $q=$this->db->prepare('SELECT id FROM users WHERE id=? AND active=1'.$lock);$q->execute([$userId]);if(!$q->fetchColumn())throw new PhoneException('USER_NOT_FOUND','Usuário ativo não encontrado.',404);
            $q=$this->db->prepare('SELECT attempt_id FROM phone_attempts WHERE active_user=? OR active_extension=?');$q->execute([$userId,$account.':'.$extension]);if($q->fetchColumn())throw new PhoneException('CALL_ACTIVE','Encerre a tentativa ativa antes de alterar o ramal.',409);
            $q=$this->db->prepare('SELECT user_id FROM call_agent_mappings WHERE provider=? AND external_key=?');$q->execute([$account,'sip:'.$extension]);$owner=$q->fetchColumn();if($owner!==false&&(int)$owner!==$userId)throw new PhoneException('EXTENSION_ASSIGNED','Este ramal já pertence a outro usuário.',409);
            $this->db->prepare("DELETE FROM call_agent_mappings WHERE provider=? AND user_id=? AND external_key LIKE 'sip:%'")->execute([$account,$userId]);
            $this->db->prepare('INSERT INTO call_agent_mappings(provider,external_key,user_id,created_by) VALUES(?,?,?,?)')->execute([$account,'sip:'.$extension,$userId,$adminId]);
            $this->db->commit();return ['assigned'=>true,'extension'=>$extension];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&$e->getCode()==='23000')throw new PhoneException('EXTENSION_ASSIGNED','Este ramal já pertence a outro usuário.',409);throw $e;}
    }
}
