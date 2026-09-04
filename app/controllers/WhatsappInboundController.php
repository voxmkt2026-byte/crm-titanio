<?php
require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/models/Setting.php';
require_once APP_PATH . '/models/Notification.php';
require_once APP_PATH . '/models/LeadHistory.php';

class WhatsappInboundController extends Controller
{
    public function verify(): void
    {
        $token=(new Setting())->get('webhook_verify_token','');
        if(($_GET['hub_mode']??$_GET['hub.mode']??'')==='subscribe' && hash_equals((string)$token,(string)($_GET['hub_verify_token']??$_GET['hub.verify_token']??''))){echo (string)($_GET['hub_challenge']??$_GET['hub.challenge']??'');return;}
        http_response_code(403);
    }
    public function receive(): void
    {
        $payload=json_decode((string)file_get_contents('php://input'),true);$messages=$payload['entry'][0]['changes'][0]['value']['messages']??[];
        $db=Database::getInstance();$notification=new Notification();$history=new LeadHistory();
        foreach($messages as $message){$from=preg_replace('/\D/','',(string)($message['from']??''));$text=$message['text']['body']??('[Mensagem '.($message['type']??'recebida').']');if($from==='')continue;$suffix=substr($from,-11);$stmt=$db->prepare("SELECT * FROM leads WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp,phone),'+',''),'(',''),')',''),'-','') LIKE :phone ORDER BY updated_at DESC LIMIT 1");$stmt->execute([':phone'=>'%'.$suffix]);$lead=$stmt->fetch();if(!$lead)continue;$history->add((int)$lead['id'],null,'whatsapp','Cliente respondeu via WhatsApp: '.$text);$recipients=[];if($lead['assigned_to'])$recipients[]=(int)$lead['assigned_to'];if(!$recipients){$recipients=array_map('intval',array_column($db->query("SELECT id FROM users WHERE active=1 AND role IN ('admin','supervisor')")->fetchAll(),'id'));}foreach(array_unique($recipients) as $uid)$notification->create($uid,'Cliente respondeu',($lead['name']?:'Lead #'.$lead['id']).': '.mb_substr($text,0,140),'leads/'.$lead['id']);}
        http_response_code(200);echo 'EVENT_RECEIVED';
    }
}
