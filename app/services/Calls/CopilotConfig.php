<?php
declare(strict_types=1);
require_once __DIR__.'/../Integration/IntegrationConfig.php';
final class CopilotException extends RuntimeException {
 public function __construct(public string $publicCode,string $message,public int $status=422){parent::__construct($message);}
}
final class CopilotConfig {
 public function __construct(private IntegrationConfig $config){}
 public static function defaults():array{return ['version'=>1,'enabled'=>false,'live_model'=>'gemini-3.5-transcribe-live','analysis_model'=>'gemini-3.6-flash','language'=>'pt-BR','analysis_level'=>'balanced','update_seconds'=>20,'max_minutes'=>10,'daily_minutes'=>120,'retention_days'=>365,'retain_transcript'=>false,'private_coaching'=>true,'prompt'=>'','scripts'=>array_fill_keys(['opening','discovery','presentation','objections','closing','follow_up'],''),'objections'=>'','score_criteria'=>'','insight_categories'=>''];}
 public function settings():array{$raw=json_decode((string)$this->config->get('gemini_live','settings_v1','{}'),true);return self::validate(is_array($raw)?$raw:[]);}
 public static function validate(array $raw):array{
  $s=self::defaults();foreach($s as $k=>$v){if(array_key_exists($k,$raw))$s[$k]=$raw[$k];}
  foreach(['enabled','retain_transcript','private_coaching'] as $k)$s[$k]=in_array($s[$k],[true,1,'1'],true);
  foreach(['update_seconds'=>[10,120],'max_minutes'=>[1,10],'daily_minutes'=>[1,480],'retention_days'=>[1,3650]] as $k=>$range){if(!is_numeric($s[$k])||(int)$s[$k]<$range[0]||(int)$s[$k]>$range[1])throw new CopilotException('INVALID_CONFIG','Limite inválido: '.$k);$s[$k]=(int)$s[$k];}
  foreach(['live_model','analysis_model'] as $k)if(!is_string($s[$k])||!preg_match('/^gemini-[a-z0-9][a-z0-9.-]{1,80}$/D',$s[$k]))throw new CopilotException('INVALID_CONFIG','Modelo Gemini inválido.');
  if(!str_contains($s['live_model'],'transcribe'))throw new CopilotException('INVALID_CONFIG','Use um modelo Live de transcrição.');
  if(!in_array($s['language'],['pt-BR','en-US','es-ES','auto'],true)||!in_array($s['analysis_level'],['brief','balanced','detailed'],true))throw new CopilotException('INVALID_CONFIG','Idioma ou nível inválido.');
  foreach(['prompt','objections','score_criteria','insight_categories'] as $k){if(!is_string($s[$k])||strlen($s[$k])>8000)throw new CopilotException('INVALID_CONFIG','Texto de configuração muito longo.');}
  if(!is_array($s['scripts']))throw new CopilotException('INVALID_CONFIG','Scripts inválidos.');$scripts=[];foreach(self::defaults()['scripts'] as $k=>$v){$scripts[$k]=$s['scripts'][$k]??'';if(!is_string($scripts[$k])||strlen($scripts[$k])>4000)throw new CopilotException('INVALID_CONFIG','Script muito longo.');}$s['scripts']=$scripts;$s['version']=1;return $s;
 }
 public function key():string{return trim((string)$this->config->get('gemini_live','api_key',$this->config->get('gemini','api_key','')));}
 public function publicSettings():array{$s=$this->settings();return array_intersect_key($s,array_flip(['enabled','live_model','update_seconds','max_minutes','retain_transcript']))+['configured'=>$this->key()!=='','can_use'=>$s['enabled']&&$this->key()!==''];}
 public function save(array $data,int $userId):void{$s=self::validate($data);$key=trim((string)($data['api_key']??''));if(strlen($key)>512||strpbrk($key,"\r\n")!==false)throw new CopilotException('INVALID_CONFIG','Chave inválida.');$this->config->setPublic('gemini_live','settings_v1',json_encode($s,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$userId);if($key!=='')$this->config->setSecret('gemini_live','api_key',$key,$userId);}
}
