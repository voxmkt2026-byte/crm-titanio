<?php
define('APP_INIT',true);require dirname(__DIR__).'/config/config.php';require ROOT_PATH.'/config/database.php';require APP_PATH.'/core/Database.php';require APP_PATH.'/core/Model.php';require APP_PATH.'/services/Integration/IntegrationConfig.php';require APP_PATH.'/models/IntegrationCredential.php';require APP_PATH.'/services/Calls/CallSyncService.php';if(PHP_SAPI!=='cli'){http_response_code(404);exit;}$config=new IntegrationConfig(new IntegrationCredential(),SecretVault::fromFile(ROOT_PATH.'/config/integration.key'),IntegrationConfig::legacyEnvironment(ROOT_PATH.'/ligacao/.env'));$result=[];$failed=false;
foreach(CallAccounts::all($config) as $account){
    if(!$account['configured']){$result[$account['key']]=['status'=>'not_configured'];continue;}
    try{$result[$account['key']]=['status'=>'ok']+(new CallSyncService(Database::getInstance(),$config,$account['key']))->run();}
    catch(Throwable $error){$failed=true;CallFailure::log('scheduled_sync_'.$account['key'],$error);$result[$account['key']]=['status'=>'failed','error'=>CallFailure::code($error)];}
}
echo json_encode($result,JSON_UNESCAPED_UNICODE).PHP_EOL;
exit($failed?1:0);
