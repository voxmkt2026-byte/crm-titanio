<?php
declare(strict_types=1);
require_once __DIR__.'/CopilotConfig.php';

/** Bounded Gemini HTTPS transport. Diagnostics deliberately exclude all payloads. */
final class CopilotHttpTransport {
 private ?Closure $execute;
 public function __construct(?callable $execute=null){$this->execute=$execute?Closure::fromCallable($execute):null;}
 public function post(string $path,string $key,array $body,int $timeout):array{
  $url='https://generativelanguage.googleapis.com/v1beta/'.$path;
  $result=$this->execute?($this->execute)($url,$key,$body,$timeout):$this->send($url,$key,$body,$timeout);
  $status=(int)($result['status']??0);$errno=(int)($result['errno']??0);$code=null;
  if(!empty($result['too_large']))$code='AI_RESPONSE_TOO_LARGE';
  elseif($errno===28||$status===408||$status===504)$code='AI_TIMEOUT';
  elseif($status===404&&str_starts_with($path,'models/'))$code='AI_MODEL_UNAVAILABLE';
  elseif(in_array($status,[401,403],true))$code='AI_AUTH_FAILED';
  elseif($status===400||$status===422)$code='AI_REQUEST_REJECTED';
  elseif($status===429)$code='AI_RATE_LIMIT';
  elseif($status>=500)$code='AI_PROVIDER_BUSY';
  elseif(empty($result['ok'])||$errno!==0)$code='AI_CONNECTION_FAILED';
  elseif($status<200||$status>=300)$code='AI_UNAVAILABLE';
  $data=$code===null?json_decode((string)($result['body']??''),true):null;
  if($code===null&&!is_array($data))$code='AI_INVALID_RESPONSE';
  if($code!==null){
   preg_match('#^models/([A-Za-z0-9._-]{1,120}):generateContent$#D',$path,$model);
   error_log('[copilot-http] '.json_encode(['code'=>$code,'stage'=>str_starts_with($path,'models/')?'analysis':'token','model'=>$model[1]??null,'http_status'=>$status,'curl_errno'=>$errno,'elapsed_ms'=>max(0,(int)($result['elapsed_ms']??0))]));
   $messages=[
    'AI_TIMEOUT'=>'O Gemini demorou além do limite para analisar. A transcrição foi preservada.',
    'AI_CONNECTION_FAILED'=>'A hospedagem não conseguiu conectar ao Gemini. A ligação continua normalmente.',
    'AI_REQUEST_REJECTED'=>'O Gemini não aceitou a configuração da análise. Confira o modelo nas configurações do copiloto.',
    'AI_RESPONSE_TOO_LARGE'=>'A resposta da IA excedeu o limite seguro. Confira o modelo e as orientações configuradas.',
    'AI_MODEL_UNAVAILABLE'=>'O modelo de análise não está disponível para esta conta. Atualize o modelo nas configurações do copiloto.',
    'AI_AUTH_FAILED'=>'A chave Gemini não tem acesso à análise. Confira a chave e as permissões nas configurações.',
    'AI_RATE_LIMIT'=>'O limite do Gemini foi atingido. Confira a cota da conta; a ligação continua.',
    'AI_PROVIDER_BUSY'=>'O Gemini está temporariamente ocupado. A ligação continua normalmente.',
    'AI_INVALID_RESPONSE'=>'A IA não retornou uma resposta válida.',
    'AI_UNAVAILABLE'=>'A IA não respondeu. A ligação continua normalmente.'
   ];
   throw new CopilotException($code,$messages[$code],503);
  }
  return $data;
 }
 private function send(string $url,string $key,array $body,int $timeout):array{
  $ch=curl_init($url);$response='';$tooLarge=false;
  curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>$timeout,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$response,&$tooLarge){if(strlen($response)+strlen($chunk)>262144){$tooLarge=true;return 0;}$response.=$chunk;return strlen($chunk);}]);
  $ok=curl_exec($ch);$result=['ok'=>$ok!==false,'status'=>(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE),'errno'=>curl_errno($ch),'elapsed_ms'=>(int)round(curl_getinfo($ch,CURLINFO_TOTAL_TIME)*1000),'too_large'=>$tooLarge,'body'=>$response];curl_close($ch);return $result;
 }
}
