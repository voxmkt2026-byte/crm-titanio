<?php
require_once dirname(__DIR__).'/config/config.php';
require_once dirname(__DIR__).'/config/database.php';
require_once APP_PATH.'/core/Database.php';
$expected=(string)getenv('AUTOMATION_CRON_TOKEN');$provided=(string)($_GET['token']??'');
if($expected===''||!hash_equals($expected,$provided)){http_response_code(403);exit('Acesso negado');}
require_once APP_PATH.'/services/AutomationRunner.php';header('Content-Type: application/json; charset=utf-8');echo json_encode((new AutomationRunner())->run(),JSON_UNESCAPED_UNICODE);
