<?php
require_once APP_PATH . '/core/Model.php';
final class AiProviderConfig extends Model {
 protected string $table='ai_provider_configs';
 public function profiles(): array{return $this->all('provider ASC');}
 public function saveProfile(string $provider,string $baseUrl,string $model,int $maxBytes,bool $enabled,bool $active):void{
  $this->db->beginTransaction();try{if($active)$this->db->exec('UPDATE ai_provider_configs SET is_active=0');
  $s=$this->db->prepare('INSERT INTO ai_provider_configs(provider,base_url,model,max_audio_bytes,enabled,is_active) VALUES(:p,:u,:m,:b,:e,:a) ON DUPLICATE KEY UPDATE base_url=VALUES(base_url),model=VALUES(model),max_audio_bytes=VALUES(max_audio_bytes),enabled=VALUES(enabled),is_active=VALUES(is_active)');
  $s->execute([':p'=>$provider,':u'=>$baseUrl,':m'=>$model,':b'=>$maxBytes,':e'=>$enabled?1:0,':a'=>$active?1:0]);$this->db->commit();}catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
 public function markTest(string $provider,bool $success,?string $error=null):void{$s=$this->db->prepare("UPDATE ai_provider_configs SET last_tested_at=NOW(),last_test_status=:s,last_error_code=:e WHERE provider=:p");$s->execute([':s'=>$success?'success':'failed',':e'=>$error,':p'=>$provider]);}
}
