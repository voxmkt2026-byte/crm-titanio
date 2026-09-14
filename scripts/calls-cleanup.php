<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
define('APP_INIT',true);
require dirname(__DIR__).'/config/config.php';
require ROOT_PATH.'/config/database.php';
require APP_PATH.'/core/Database.php';
require APP_PATH.'/services/Calls/CallMediaStorage.php';
$db=Database::getInstance();
$storage=new CallMediaStorage(STORAGE_PATH);
$deleted=0;
$rows=$db->query("SELECT id,recording_path FROM call_records WHERE recording_status='stored' AND recording_expires_at IS NOT NULL AND recording_expires_at<=NOW()")->fetchAll();
foreach ($rows as $row) {
    $path=$storage->resolve((string)$row['recording_path']);
    if ($path!==null && !@unlink($path)) continue;
    $db->prepare("UPDATE call_records SET recording_status='expired',recording_path=NULL WHERE id=:id")->execute([':id'=>$row['id']]);
    $deleted++;
}
echo json_encode(['deleted'=>$deleted]).PHP_EOL;
