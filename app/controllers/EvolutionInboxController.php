<?php
/**
 * app/controllers/EvolutionInboxController.php
 * Atendimento WhatsApp (Evolution API): inbox estilo "Zap Responder".
 *
 * IMPORTANTE: a Evolution API é só o transporte (gateway WhatsApp via
 * Baileys) — não existe "conversa"/ticket do lado dela. Por isso a lista de
 * atendimentos e o histórico de mensagens vêm do NOSSO banco
 * (evolution_conversation_links + evolution_messages), alimentado em tempo
 * real pelo webhook (EvolutionWebhookController) e por esta tela ao enviar
 * mensagens. O envio de fato ao WhatsApp é feito via EvolutionClient.
 */
require_once APP_PATH.'/core/Controller.php';
require_once APP_PATH.'/core/Database.php';
require_once APP_PATH.'/models/User.php';
require_once APP_PATH.'/models/Notification.php';
require_once APP_PATH.'/models/Setting.php';
require_once APP_PATH.'/models/Lead.php';
require_once APP_PATH.'/models/LeadHistory.php';
require_once APP_PATH.'/models/LeadScore.php';
require_once APP_PATH.'/models/Task.php';
require_once APP_PATH.'/models/TaskHistory.php';
require_once APP_PATH.'/models/TaskWatcher.php';
require_once APP_PATH.'/models/EvolutionConnection.php';
require_once APP_PATH.'/models/EvolutionServiceFlow.php';
require_once APP_PATH.'/services/EvolutionClient.php';
require_once APP_PATH.'/core/Mailer.php';

class EvolutionInboxController extends Controller
{
    private PDO $db;
    private EvolutionConnection $connectionModel;
    private EvolutionServiceFlow $flowModel;
    public function __construct(){
        $this->db=Database::getInstance();
        $this->connectionModel=new EvolutionConnection();
        $this->flowModel=new EvolutionServiceFlow();
    }

    public function index(): void
    {
        $this->requireAccess();
        $filter=(string)$this->input('filtro','all');
        $q=trim((string)$this->input('q',''));
        $selected=(string)$this->input('conversa','');
        $connections=$this->connectionModel->active();
        $selectedInstance=trim((string)$this->input('instancia',''));
        if($selectedInstance!==''&&!$this->connectionModel->findActiveByName($selectedInstance))$selectedInstance='';

        $items=$this->listConversations($filter,$q,$selectedInstance);
        $active=null;$messages=[];$error=null;$flowOptions=[];
        if($selected!==''){
            $link=$this->link($selected);
            if(!$this->canSee($link)){
                $error='Este atendimento não está atribuído a você.';
            }else{
                // Responsável pelo lead é sempre o dono da verdade: se alguém mudou o responsável
                // direto na tela de Leads, reflete aqui em vez de deixar os dois dessincronizados.
                if($link&&!empty($link['lead_id'])){
                    $leadOwner=$this->db->prepare('SELECT assigned_to FROM leads WHERE id=:id');
                    $leadOwner->execute([':id'=>$link['lead_id']]);
                    $ownerRow=$leadOwner->fetch();
                    if($ownerRow&&(int)($ownerRow['assigned_to']??0)!==(int)($link['assigned_to']??0)){
                        $this->db->prepare('UPDATE evolution_conversation_links SET assigned_to=:uid WHERE conversation_id=:id')->execute([':uid'=>$ownerRow['assigned_to'],':id'=>$selected]);
                        $link=$this->link($selected);
                    }
                }
                $active=$this->conversationFromLink($link?:['conversation_id'=>$selected]);
                $active['link']=$link;
                $messages=$this->loadMessages($selected);
                if(empty($messages)){
                    // Primeira vez que este atendimento é aberto: busca o histórico real na Evolution
                    // (conversas antigas, de antes do webhook estar configurado) e guarda no nosso banco.
                    $this->backfillMessages($selected,$link);
                    $messages=$this->loadMessages($selected);
                }
                $flowOptions=$this->flowModel->activeForInstance((string)($link['instance_name']??''));
                $this->markRead($selected);
            }
        }

        $users=(new User())->allActive();
        $client=EvolutionClient::fromSettings();
        $connectionStatus=null;
        $statusTarget=$selectedInstance!==''?$this->connectionModel->findActiveByName($selectedInstance):($connections[0]??null);
        if($client->isConfigured()&&$statusTarget){
            try{$instances=$client->fetchInstances();foreach($instances as $inst){$name=$inst['name']??($inst['instanceName']??'');if($name===(string)$statusTarget['instance_name']){$connectionStatus=(string)($inst['connectionStatus']??'');break;}}}catch(Throwable $e){}
        }

        $isManager=Auth::hasRole(['admin','supervisor']);
        $stats=null;
        if($isManager){
            $stats=$this->db->query('SELECT COUNT(*) total, SUM(unread_count>0) unread, SUM(assigned_to IS NULL) unassigned FROM evolution_conversation_links')->fetch();
        }

        $this->view('evolution/index',['pageTitle'=>'Atendimento WhatsApp','conversations'=>$items,'active'=>$active,'messages'=>$messages,'error'=>$error,'users'=>$users,'filter'=>$filter,'q'=>$q,'connectionStatus'=>$connectionStatus,'clientConfigured'=>$client->isConfigured()&&!empty($connections),'stats'=>$stats,'suggestedLabels'=>$this->suggestedLabels(),'connections'=>$connections,'selectedInstance'=>$selectedInstance,'flowOptions'=>$flowOptions]);
    }

    public function send(string $id): void
    {
        $this->requireAccess();Csrf::verifyRequest();
        $link=$this->link($id);
        if(!$this->canSee($link))$this->json(['success'=>false,'message'=>'Sem acesso ao atendimento.'],403);
        $content=trim((string)$this->input('content',''));
        if($content==='')$this->json(['success'=>false,'message'=>'Digite a mensagem.'],422);
        $isPrivate=(int)$this->input('private',0)===1;

        try{
            if(!$isPrivate){
                [$client,$connection]=$this->clientForLink($link);
                $remoteJid=$this->remoteJidForLink($link);
                $destination=$remoteJid;
                if(str_ends_with(strtolower($remoteJid),'@lid')){
                    $destination=(string)($link['contact_phone']??'');
                    if($destination==='')throw new RuntimeException('Este contato usa um identificador privado do WhatsApp. Informe o número no painel lateral antes de responder.');
                }
                $client->sendText($destination,$content,(string)($connection['payload_mode']??'auto'));
            }
            $userId=Auth::id();
            $insertedId=$this->storeLocalMessage($id,(string)($link['instance_name']??''),$isPrivate,$content,$userId);
            if(!$isPrivate){
                $this->db->prepare("UPDATE evolution_conversation_links SET last_message=:msg,last_message_at=NOW(),last_synced_at=NOW() WHERE conversation_id=:id")
                    ->execute([':id'=>$id,':msg'=>$content]);
            }
            if(!empty($link['lead_id']))$this->db->prepare("INSERT INTO lead_history(lead_id,user_id,type,description) VALUES(:lead,:uid,'whatsapp',:description)")->execute([':lead'=>$link['lead_id'],':uid'=>$userId,':description'=>($isPrivate?'Nota interna no atendimento WhatsApp: ':'Mensagem enviada via WhatsApp: ').$content]);
            $this->json(['success'=>true,'message'=>['id'=>$insertedId,'time'=>date('H:i')]]);
        }catch(Throwable $e){$this->json(['success'=>false,'message'=>$e->getMessage()],422);}
    }

    /**
     * Envia e-mail manualmente a partir de uma conversa vinculada ao lead.
     * A etapa do fluxo só prepara o rascunho; este endpoint é chamado apenas
     * após a confirmação explícita do atendente no modal.
     */
    public function sendEmail(string $id): void
    {
        $this->requireAccess();
        Csrf::verifyRequest();
        $link = $this->link($id);
        if (!$this->canSee($link)) $this->json(['success' => false, 'message' => 'Sem acesso ao atendimento.'], 403);
        if (empty($link['lead_id'])) $this->json(['success' => false, 'message' => 'Vincule um lead antes de enviar e-mail por este atendimento.'], 422);

        $to = trim((string) $this->input('to', (string) ($link['lead_email'] ?? '')));
        $subject = mb_substr(trim((string) $this->input('subject', '')), 0, 255);
        $content = mb_substr(trim((string) $this->input('content', '')), 0, 12000);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) $this->json(['success' => false, 'message' => 'Informe um e-mail de destino válido.'], 422);
        if ($subject === '' || $content === '') $this->json(['success' => false, 'message' => 'Informe assunto e mensagem antes de enviar.'], 422);

        $mailer = Mailer::fromSettings();
        if (!$mailer->isConfigured()) $this->json(['success' => false, 'message' => 'Configure o SMTP em Configurações antes de enviar e-mails.'], 422);

        $body = nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        if (!$mailer->send($to, $subject, $body, (string) ($link['lead_name'] ?? ''))) {
            $this->json(['success' => false, 'message' => 'O e-mail não foi enviado. Confira o SMTP em Configurações e o log de e-mail.'], 422);
        }

        try {
            $this->db->prepare("INSERT INTO lead_history(lead_id,user_id,type,description) VALUES(:lead,:uid,'email',:description)")
                ->execute([
                    ':lead' => (int) $link['lead_id'], ':uid' => Auth::id(),
                    ':description' => 'E-mail enviado pelo Atendimento WhatsApp para ' . $to . '. Assunto: ' . $subject,
                ]);
        } catch (Throwable $e) {
            error_log('EvolutionInboxController::sendEmail history - ' . $e->getMessage());
        }
        log_activity('email_enviado_atendimento', 'E-mail enviado ao lead #' . (int) $link['lead_id'] . ' pelo Atendimento WhatsApp.');
        $this->json(['success' => true, 'message' => 'E-mail enviado com sucesso.']);
    }

    /** GET /atendimento-whatsapp/{id}/poll — só mensagens novas (id > last_id), sem tocar na Evolution API. */
    public function poll(string $id): void
    {
        $this->requireAccess();$link=$this->link($id);if(!$this->canSee($link))$this->json(['success'=>false,'message'=>'Sem acesso.'],403);
        $lastId=(int)$this->input('last_id',0);
        $s=$this->db->prepare('SELECT * FROM evolution_messages WHERE conversation_id=:id AND id>:last ORDER BY id ASC LIMIT 100');
        $s->execute([':id'=>$id,':last'=>$lastId]);
        $rows=array_map([$this,'formatMessage'],$s->fetchAll());
        if($rows)$this->markRead($id);
        $this->json(['success'=>true,'messages'=>$rows]);
    }

    /** Define (ou remove) o roteiro guiado da conversa. Não envia nada ao cliente. */
    public function setFlow(string $id): void
    {
        $this->requireAccess();Csrf::verifyRequest();
        $link=$this->link($id);
        if(!$this->canSee($link))$this->json(['success'=>false,'message'=>'Sem acesso ao atendimento.'],403);
        $flowId=(int)$this->input('flow_id',0);
        if($flowId<=0){
            try{$this->db->prepare('UPDATE evolution_conversation_links SET flow_id=NULL,flow_step=0 WHERE conversation_id=:id')->execute([':id'=>$id]);}catch(Throwable $e){$this->json(['success'=>false,'message'=>'Rode a migration evolution_inbox_v3 para usar fluxos.'],422);}
            $this->json(['success'=>true,'message'=>'Fluxo removido deste atendimento.','flow'=>null]);
        }
        $flow=$this->flowModel->find($flowId);
        $allowed=false;foreach($this->flowModel->activeForInstance((string)($link['instance_name']??'')) as $item){if((int)$item['id']===$flowId){$allowed=true;break;}}
        if(!$flow||!$allowed)$this->json(['success'=>false,'message'=>'Fluxo inválido para esta linha WhatsApp.'],422);
        try{$this->db->prepare('UPDATE evolution_conversation_links SET flow_id=:flow,flow_step=0 WHERE conversation_id=:id')->execute([':flow'=>$flowId,':id'=>$id]);}catch(Throwable $e){$this->json(['success'=>false,'message'=>'Rode a migration evolution_inbox_v3 para usar fluxos.'],422);}
        $this->json(['success'=>true,'message'=>'Fluxo iniciado.','flow'=>$this->flowStepPayload($flow,0)]);
    }

    /** Avança a conversa à próxima etapa e retorna um texto apenas sugerido. */
    public function advanceFlow(string $id): void
    {
        $this->requireAccess();Csrf::verifyRequest();
        $link=$this->link($id);
        if(!$this->canSee($link))$this->json(['success'=>false,'message'=>'Sem acesso ao atendimento.'],403);
        $flow=$this->flowModel->find((int)($link['flow_id']??0));
        $steps=$this->flowModel->parsedSteps($flow);
        if(!$flow||empty($steps))$this->json(['success'=>false,'message'=>'Nenhum fluxo ativo nesta conversa.'],422);
        $next=min((int)($link['flow_step']??0)+1,count($steps)-1);
        try{$this->db->prepare('UPDATE evolution_conversation_links SET flow_step=:step WHERE conversation_id=:id')->execute([':step'=>$next,':id'=>$id]);}catch(Throwable $e){$this->json(['success'=>false,'message'=>'Rode a migration evolution_inbox_v3 para usar fluxos.'],422);}
        $this->json(['success'=>true,'message'=>$next===count($steps)-1?'Última etapa do fluxo.':'Próxima etapa carregada.','flow'=>$this->flowStepPayload($flow,$next)]);
    }

    public function linkLead(string $id): void
    {
        $this->requireAccess();Csrf::verifyRequest();$leadId=(int)$this->input('lead_id',0);$lead=null;if($leadId){$s=$this->db->prepare("SELECT id,name,phone,whatsapp,assigned_to FROM leads WHERE id=:id");$s->execute([':id'=>$leadId]);$lead=$s->fetch();if(!$lead)$this->json(['success'=>false,'message'=>'Lead não encontrado.'],404);if(!Auth::hasRole(['admin','supervisor'])&&(int)$lead['assigned_to']!==Auth::id())$this->json(['success'=>false,'message'=>'Lead fora do seu acesso.'],403);}
        $this->db->prepare("INSERT INTO evolution_conversation_links(conversation_id,lead_id,assigned_to,last_synced_at) VALUES(:conversation,:lead,:assigned,NOW()) ON DUPLICATE KEY UPDATE lead_id=VALUES(lead_id),assigned_to=VALUES(assigned_to),last_synced_at=NOW()")->execute([':conversation'=>$id,':lead'=>$leadId?:null,':assigned'=>$lead['assigned_to']??Auth::id()]);$this->json(['success'=>true]);
    }

    /**
     * POST /atendimento-whatsapp/{id}/criar-lead — cadastra um lead novo a partir do
     * atendimento (mesmo fluxo de app/controllers/LeadController::store, mas em AJAX
     * para não sair da tela de conversa) e já vincula a conversa a ele.
     */
    public function createLead(string $id): void
    {
        $this->requireAccess();Csrf::verifyRequest();
        $link=$this->link($id);
        if(!$this->canSee($link))$this->json(['success'=>false,'message'=>'Sem acesso ao atendimento.'],403);
        if(!empty($link['lead_id']))$this->json(['success'=>false,'message'=>'Este atendimento já está vinculado a um lead.'],422);

        $name=trim((string)$this->input('name',''));
        if($name==='')$this->json(['success'=>false,'message'=>'Informe o nome do lead.'],422);
        $whatsapp=preg_replace('/\D/','',(string)$this->input('whatsapp',''));
        $assignedTo=(int)$this->input('assigned_to',0)?:Auth::id();
        if(!Auth::hasRole(['admin','supervisor']))$assignedTo=Auth::id();

        $data=[
            'name'=>$name,
            'whatsapp'=>$whatsapp?:null,
            'phone'=>$whatsapp?:null,
            'interest'=>trim((string)$this->input('interest',''))?:null,
            'source'=>'whatsapp',
            'status'=>'novo',
            'assigned_to'=>$assignedTo,
            'internal_notes'=>trim((string)$this->input('notes',''))?:null,
        ];
        $data=array_filter($data,fn($v)=>$v!==null);
        $data['status']='novo';
        $data['source']='whatsapp';

        try{
            $leadModel=new Lead();
            $result=$leadModel->createWithLeadCode($data);
            $leadId=$result['id'];

            (new LeadHistory())->add($leadId,Auth::id(),'criacao','Lead criado a partir do Atendimento WhatsApp. Código: '.$result['lead_code'].'.');
            (new LeadScore())->recalculateForLead($leadId);
            if($assignedTo)(new Notification())->create($assignedTo,'Novo lead atribuído a você','O lead "'.$name.'" foi criado a partir de um atendimento WhatsApp.','leads/'.$leadId);

            $this->db->prepare("INSERT INTO evolution_conversation_links(conversation_id,lead_id,assigned_to,last_synced_at) VALUES(:conversation,:lead,:assigned,NOW()) ON DUPLICATE KEY UPDATE lead_id=VALUES(lead_id),assigned_to=VALUES(assigned_to),last_synced_at=NOW()")
                ->execute([':conversation'=>$id,':lead'=>$leadId,':assigned'=>$assignedTo]);

            log_activity('lead_criado','Lead #'.$leadId.' ('.$result['lead_code'].', "'.$name.'") criado a partir do Atendimento WhatsApp.');
            $this->json(['success'=>true,'lead_id'=>$leadId,'lead_code'=>$result['lead_code'],'url'=>url('leads/'.$leadId)]);
        }catch(Throwable $e){$this->json(['success'=>false,'message'=>$e->getMessage()],422);}
    }

    /** POST /atendimento-whatsapp/{id}/criar-tarefa — cria uma tarefa (mesmo fluxo de TaskController::store) sem sair da conversa. */
    public function createTask(string $id): void
    {
        $this->requireAccess();Csrf::verifyRequest();
        if(!Auth::can('tasks.create'))$this->json(['success'=>false,'message'=>'Você não tem permissão para criar tarefas.'],403);
        $link=$this->link($id);
        if(!$this->canSee($link))$this->json(['success'=>false,'message'=>'Sem acesso ao atendimento.'],403);

        $title=trim((string)$this->input('title',''));
        if($title==='')$this->json(['success'=>false,'message'=>'Informe um título para a tarefa.'],422);
        $priority=(string)$this->input('priority','media');
        if(!in_array($priority,['baixa','media','alta','urgente'],true))$priority='media';
        $dueAt=trim((string)$this->input('due_at',''));

        try{
            $taskModel=new Task();
            $assignedTo=(int)$this->input('assigned_to',0)?:null;
            $taskId=$taskModel->create([
                'title'=>$title,
                'description'=>trim((string)$this->input('description',''))?:('Aberta a partir do Atendimento WhatsApp ('.($link['contact_name']??$link['lead_name']??$id).').'),
                'creator_id'=>Auth::id(),
                'assigned_to'=>$assignedTo,
                'lead_id'=>$link['lead_id']??null,
                'priority'=>$priority,
                'due_at'=>$dueAt!==''?str_replace('T',' ',$dueAt):null,
            ]);

            (new TaskHistory())->add($taskId,Auth::id(),'criacao','Tarefa criada a partir do Atendimento WhatsApp.');
            (new TaskWatcher())->add($taskId,Auth::id());
            if($assignedTo)(new Notification())->create($assignedTo,'Nova tarefa atribuída a você','Você recebeu a tarefa "'.$title.'" a partir de um atendimento WhatsApp.','tarefas/'.$taskId);

            log_activity('tarefa_criada','Tarefa #'.$taskId.' ("'.$title.'") criada a partir do Atendimento WhatsApp.');
            $this->json(['success'=>true,'task_id'=>$taskId,'url'=>url('tarefas/'.$taskId)]);
        }catch(Throwable $e){$this->json(['success'=>false,'message'=>$e->getMessage()],422);}
    }

    /** Transferência é 100% do nosso CRM — a Evolution API não tem conceito de responsável/agente. */
    public function transfer(string $id): void
    {
        $this->requireAccess();Csrf::verifyRequest();if(!Auth::hasRole(['admin','supervisor']))$this->json(['success'=>false,'message'=>'Somente gestão pode transferir atendimentos.'],403);
        $userId=(int)$this->input('user_id',0);$s=$this->db->prepare("SELECT id,name FROM users WHERE id=:id AND active=1");$s->execute([':id'=>$userId]);$user=$s->fetch();if(!$user)$this->json(['success'=>false,'message'=>'Colaborador inválido.'],422);
        $this->db->prepare("INSERT INTO evolution_conversation_links(conversation_id,assigned_to,last_synced_at) VALUES(:conversation,:uid,NOW()) ON DUPLICATE KEY UPDATE assigned_to=VALUES(assigned_to),last_synced_at=NOW()")->execute([':conversation'=>$id,':uid'=>$userId]);
        $link=$this->link($id);if(!empty($link['lead_id']))$this->db->prepare("UPDATE leads SET assigned_to=:uid WHERE id=:lead")->execute([':uid'=>$userId,':lead'=>$link['lead_id']]);
        (new Notification())->create($userId,'Atendimento WhatsApp transferido','Uma conversa foi atribuída a você.','atendimento-whatsapp?conversa='.rawurlencode($id));
        log_activity('evolution_transferido','Atendimento '.$id.' transferido para '.$user['name'].'.');
        $this->json(['success'=>true]);
    }

    public function labels(string $id): void
    {
        $this->requireAccess();Csrf::verifyRequest();$link=$this->link($id);if(!$this->canSee($link))$this->json(['success'=>false,'message'=>'Sem acesso.'],403);
        $labels=array_values(array_unique(array_filter(array_map('trim',explode(',',(string)$this->input('labels',''))))));
        $previous=array_values(array_filter(array_map('trim',explode(',',(string)($link['labels']??'')))));

        try{[$client]=$this->clientForLink($link);}catch(Throwable $e){$client=null;}
        if($client){
            $remoteJid=$this->remoteJidForLink($link);
            foreach(array_diff($labels,$previous) as $label){try{$client->handleLabel($remoteJid,$label,'add');}catch(Throwable $e){error_log('EvolutionInboxController::labels add - '.$e->getMessage());}}
            foreach(array_diff($previous,$labels) as $label){try{$client->handleLabel($remoteJid,$label,'remove');}catch(Throwable $e){error_log('EvolutionInboxController::labels remove - '.$e->getMessage());}}
        }

        $this->db->prepare("INSERT INTO evolution_conversation_links(conversation_id,labels,last_synced_at) VALUES(:conversation,:labels,NOW()) ON DUPLICATE KEY UPDATE labels=VALUES(labels),last_synced_at=NOW()")->execute([':conversation'=>$id,':labels'=>implode(',',$labels)]);
        if(!empty($link['lead_id']))foreach($labels as $label){$this->db->prepare("INSERT IGNORE INTO tags(name,color) VALUES(:name,'#6366f1')")->execute([':name'=>mb_substr($label,0,80)]);$tag=$this->db->prepare("SELECT id FROM tags WHERE name=:name");$tag->execute([':name'=>mb_substr($label,0,80)]);$tagId=(int)$tag->fetchColumn();if($tagId)$this->db->prepare("INSERT IGNORE INTO lead_tags(lead_id,tag_id) VALUES(:lead,:tag)")->execute([':lead'=>$link['lead_id'],':tag'=>$tagId]);}
        $this->json(['success'=>true]);
    }

    /** GET /instance/connect — gera QR code para (re)conectar a instância (chamado da tela de Configurações). */
    public function qrcode(): void
    {
        $this->requireLogin();if(!Auth::hasRole(['admin']))$this->json(['success'=>false],403);Csrf::verifyRequest();
        try{
            $connection=$this->selectedConnection();
            if(!$connection)$this->json(['success'=>false,'message'=>'Cadastre uma linha WhatsApp ativa antes de gerar o QR Code.'],422);
            $r=EvolutionClient::fromSettings()->withInstance($connection['instance_name'])->connect();
            $base64=$r['base64']??($r['qrcode']['base64']??null);
            $pairing=$r['pairingCode']??null;
            $state=(string)($r['instance']['state']??'');
            if(!$base64&&!$pairing){
                if(strtolower($state)==='open'){
                    $this->json(['success'=>true,'already_connected'=>true,'message'=>'Esta instância já está conectada — não é necessário escanear o QR Code.']);
                }
                $this->json(['success'=>false,'message'=>'A Evolution não retornou um QR Code neste momento (status atual: '.($state?:'desconhecido').'). Tente novamente em alguns segundos.'],422);
            }
            $this->json(['success'=>true,'base64'=>$base64,'pairing_code'=>$pairing]);
        }catch(Throwable $e){$this->json(['success'=>false,'message'=>$e->getMessage()],422);}
    }

    /** POST /atendimento-whatsapp/sincronizar — importa o histórico de conversas já existente na Evolution (antes do webhook existir). */
    public function sync(): void
    {
        $this->requireLogin();if(!Auth::hasRole(['admin','supervisor']))$this->json(['success'=>false],403);Csrf::verifyRequest();
        try{
            $connections=$this->connectionModel->active();
            if(empty($connections))$this->json(['success'=>false,'message'=>'Cadastre ao menos uma linha WhatsApp ativa.'],422);
            // Mapa remoteJid -> {pushName, profilePicUrl} dos contatos salvos, usado para
            // completar nome/foto quando o próprio registro do chat vem incompleto.
            $count=0;
            foreach($connections as $connection){
            $client=EvolutionClient::fromSettings()->withInstance($connection['instance_name']);
            $chats=$client->findChats();
            $contactsMap=[];
            try{foreach($client->findContacts() as $c){$jid=(string)($c['remoteJid']??'');if($jid!=='')$contactsMap[$jid]=$c;}}catch(Throwable $e){}
            foreach($chats as $chat){
                $remoteJid=(string)($chat['remoteJid']??'');
                if($remoteJid===''||str_ends_with($remoteJid,'@g.us')||str_ends_with($remoteJid,'@newsletter'))continue;
                $contact=$contactsMap[$remoteJid]??[];
                $pushName=(string)($chat['pushName']??($contact['pushName']??''));
                $avatarUrl=(string)($chat['profilePicUrl']??($contact['profilePicUrl']??''));
                $last=$chat['lastMessage']['message']??[];
                $lastContent=$this->extractContent($last);
                $lastAtRaw=(string)($chat['updatedAt']??'');
                $lastAt=$lastAtRaw!==''?date('Y-m-d H:i:s',strtotime($lastAtRaw)):null;
                $phone=str_ends_with($remoteJid,'@s.whatsapp.net')?preg_replace('/\D/','',explode('@',$remoteJid)[0]):null;

                $exists=$this->db->prepare('SELECT conversation_id FROM evolution_conversation_links WHERE instance_name=:instance AND remote_jid=:remote LIMIT 1');
                try{$exists->execute([':instance'=>$connection['instance_name'],':remote'=>$remoteJid]);$existing=$exists->fetch();}
                catch(Throwable $e){$exists=$this->db->prepare('SELECT conversation_id FROM evolution_conversation_links WHERE conversation_id=:id LIMIT 1');$exists->execute([':id'=>$remoteJid]);$existing=$exists->fetch();}
                $conversationId=$existing['conversation_id']??EvolutionClient::conversationKey($connection['instance_name'],$remoteJid);
                if($existing){
                    $this->db->prepare('UPDATE evolution_conversation_links SET contact_name=COALESCE(NULLIF(contact_name,\'\'),:name), contact_phone=COALESCE(contact_phone,:phone), avatar_url=COALESCE(:avatar,avatar_url), last_message=COALESCE(:msg,last_message), last_message_at=COALESCE(:msg_at,last_message_at), last_synced_at=NOW() WHERE conversation_id=:id')
                        ->execute([':name'=>$pushName?:null,':phone'=>$phone,':avatar'=>$avatarUrl?:null,':msg'=>$lastContent,':msg_at'=>$lastAt,':id'=>$conversationId]);
                }else{
                    $lead=$phone?$this->matchLeadByPhone($phone):null;
                    $insert=[':id'=>$conversationId,':instance'=>$connection['instance_name'],':remote'=>$remoteJid,':lead'=>$lead['id']??null,':assigned'=>$lead['assigned_to']??null,':name'=>$pushName?:null,':phone'=>$phone,':avatar'=>$avatarUrl?:null,':msg'=>$lastContent,':msg_at'=>$lastAt];
                    try{$this->db->prepare('INSERT INTO evolution_conversation_links(conversation_id,instance_name,remote_jid,lead_id,assigned_to,contact_name,contact_phone,avatar_url,last_message,last_message_at,unread_count,last_synced_at) VALUES(:id,:instance,:remote,:lead,:assigned,:name,:phone,:avatar,:msg,:msg_at,0,NOW())')->execute($insert);}
                    catch(Throwable $e){unset($insert[':instance'],$insert[':remote']);$insert[':id']=$remoteJid;$this->db->prepare('INSERT INTO evolution_conversation_links(conversation_id,lead_id,assigned_to,contact_name,contact_phone,avatar_url,last_message,last_message_at,unread_count,last_synced_at) VALUES(:id,:lead,:assigned,:name,:phone,:avatar,:msg,:msg_at,0,NOW())')->execute($insert);}
                }
                $count++;
            }
            }
            $this->json(['success'=>true,'message'=>$count.' conversa(s) sincronizada(s). Abra uma conversa para carregar o histórico completo de mensagens.']);
        }catch(Throwable $e){$this->json(['success'=>false,'message'=>$e->getMessage()],422);}
    }

    /** POST /atendimento-whatsapp/webhook/configurar — registra nossa URL de webhook automaticamente na instância. */
    public function configureWebhook(): void
    {
        $this->requireLogin();if(!Auth::hasRole(['admin']))$this->json(['success'=>false],403);Csrf::verifyRequest();
        $token=(string)(new Setting())->get('evolution_webhook_token','');
        if($token==='')$this->json(['success'=>false,'message'=>'Gere e salve o token secreto do webhook antes de configurar.'],422);
        try{
            $connections=$this->connectionModel->active();
            if(empty($connections))$this->json(['success'=>false,'message'=>'Cadastre uma linha WhatsApp ativa antes de configurar o webhook.'],422);
            $configured=[];
            foreach($connections as $connection){
                EvolutionClient::fromSettings()->withInstance($connection['instance_name'])->setWebhook(url('webhook/evolution').'?token='.rawurlencode($token),['MESSAGES_UPSERT']);
                $configured[]=$connection['label']?:$connection['instance_name'];
            }
            $this->json(['success'=>true,'message'=>'Webhook configurado para: '.implode(', ',$configured).'.']);
        }catch(Throwable $e){$this->json(['success'=>false,'message'=>$e->getMessage()],422);}
    }

    public function test(): void{
        $this->requireLogin();Csrf::verifyRequest();if(!Auth::hasRole(['admin']))$this->json(['success'=>false],403);
        try{
            $client=EvolutionClient::fromSettings();
            $instances=$client->fetchInstances();
            $connection=$this->selectedConnection();
            $configuredInstance=(string)($connection['instance_name']??'');
            if($configuredInstance!==''){
                $found=false;
                foreach($instances as $inst){if(($inst['name']??($inst['instanceName']??''))===$configuredInstance){$found=true;$state=(string)($inst['connectionStatus']??'desconhecido');$this->json(['success'=>true,'message'=>'Conectado. Instância "'.$configuredInstance.'" encontrada (status: '.$state.').']);}}
                if(!$found)$this->json(['success'=>false,'message'=>'Token válido, mas a instância "'.$configuredInstance.'" não foi encontrada nesta conta. Confira o nome em /manager.'],422);
            }else{
                $this->json(['success'=>true,'message'=>'Conexão OK ('.count($instances).' instância(s) encontrada(s)). Informe o nome da instância para usar no Atendimento WhatsApp.']);
            }
        }catch(Throwable $e){$this->json(['success'=>false,'message'=>$e->getMessage()],422);}
    }

    /**
     * POST /atendimento-whatsapp/{id}/atualizar-contato — "forçar puxamento" do nome/foto/número
     * direto da Evolution para ESTA conversa (sem esperar a próxima sincronização geral).
     * Números de contatos vindos de anúncio (JID @lid) não são resolvidos pela Meta em nenhuma
     * API — nesse caso, some/mostra apenas nome e foto; o número precisa ser informado manualmente
     * (ver updatePhone()).
     */
    public function refreshContact(string $id): void
    {
        $this->requireAccess();Csrf::verifyRequest();
        $link=$this->link($id);
        if(!$this->canSee($link))$this->json(['success'=>false,'message'=>'Sem acesso ao atendimento.'],403);
        try{[$client]=$this->clientForLink($link);}catch(Throwable $e){$this->json(['success'=>false,'message'=>$e->getMessage()],422);}
        $remoteJid=$this->remoteJidForLink($link);

        try{
            $name='';$avatar='';$phone=null;
            foreach($client->findContacts() as $c){
                if((string)($c['remoteJid']??'')===$remoteJid){$name=(string)($c['pushName']??'');$avatar=(string)($c['profilePicUrl']??'');break;}
            }
            if(str_ends_with($remoteJid,'@s.whatsapp.net'))$phone=preg_replace('/\D/','',explode('@',$remoteJid)[0]);
            if($name===''||$avatar===''){
                foreach($client->findChats() as $chat){
                    if((string)($chat['remoteJid']??'')===$remoteJid){$name=$name?:(string)($chat['pushName']??'');$avatar=$avatar?:(string)($chat['profilePicUrl']??'');break;}
                }
            }
            $this->db->prepare('UPDATE evolution_conversation_links SET contact_name=COALESCE(NULLIF(:name,\'\'),contact_name),contact_phone=COALESCE(:phone,contact_phone),avatar_url=COALESCE(NULLIF(:avatar,\'\'),avatar_url),last_synced_at=NOW() WHERE conversation_id=:id')
                ->execute([':id'=>$id,':name'=>$name,':phone'=>$phone,':avatar'=>$avatar,':id'=>$id]);
            $this->json(['success'=>true,'name'=>$name,'phone'=>$phone,'avatar'=>$avatar,'found'=>$name!==''||$avatar!==''||$phone!==null]);
        }catch(Throwable $e){$this->json(['success'=>false,'message'=>$e->getMessage()],422);}
    }

    /** POST /atendimento-whatsapp/{id}/telefone — corrige/informa manualmente o número (necessário para contatos @lid, que a Meta não expõe em nenhuma API). */
    public function updatePhone(string $id): void
    {
        $this->requireAccess();Csrf::verifyRequest();
        $link=$this->link($id);
        if(!$this->canSee($link))$this->json(['success'=>false,'message'=>'Sem acesso ao atendimento.'],403);
        $phone=preg_replace('/\D/','',(string)$this->input('phone',''));
        if($phone===''||strlen($phone)<10)$this->json(['success'=>false,'message'=>'Informe um número válido, com DDD.'],422);
        $this->db->prepare('UPDATE evolution_conversation_links SET contact_phone=:phone, last_synced_at=NOW() WHERE conversation_id=:id')->execute([':phone'=>$phone,':id'=>$id]);
        if(!empty($link['lead_id']))$this->db->prepare('UPDATE leads SET whatsapp=COALESCE(NULLIF(whatsapp,\'\'),:phone) WHERE id=:lead')->execute([':phone'=>$phone,':lead'=>$link['lead_id']]);
        $this->json(['success'=>true,'phone'=>$phone]);
    }

    /** POST /atendimento-whatsapp/notas/{messageId}/remover — apaga uma nota interna (nunca uma mensagem real do WhatsApp). */
    public function deleteNote(string $messageId): void
    {
        $this->requireLogin();Csrf::verifyRequest();
        $id=(int)$messageId;
        $s=$this->db->prepare('SELECT * FROM evolution_messages WHERE id=:id AND is_private=1');
        $s->execute([':id'=>$id]);
        $note=$s->fetch();
        if(!$note)$this->json(['success'=>false,'message'=>'Nota não encontrada.'],404);
        $link=$this->link($note['conversation_id']);
        if(!$this->canSee($link))$this->json(['success'=>false,'message'=>'Sem acesso.'],403);
        if((int)$note['user_id']!==Auth::id()&&!Auth::hasRole(['admin','supervisor']))$this->json(['success'=>false,'message'=>'Só quem criou a nota (ou a gestão) pode removê-la.'],403);
        $this->db->prepare('DELETE FROM evolution_messages WHERE id=:id')->execute([':id'=>$id]);
        $this->json(['success'=>true]);
    }

    public function saveMappings(): void
    {
        // Mantido por compatibilidade de rota; a Evolution API não tem conceito de agente/time externo.
        $this->requireLogin();if(!Auth::hasRole(['admin']))$this->json(['success'=>false],403);Csrf::verifyRequest();$this->json(['success'=>true]);
    }

    // ---- Suporte interno ----

    private function requireAccess(): void{$this->requireLogin();if(!Auth::can('evolution.view')&&!Auth::hasRole(['admin','supervisor'])){$this->redirect('dashboard');}}

    private function selectedConnection(): ?array
    {
        return $this->connectionModel->findActiveByName(trim((string)$this->input('instance_name','')));
    }

    /** @return array{0: EvolutionClient, 1: array} */
    private function clientForLink(?array $link): array
    {
        if(!$link)throw new RuntimeException('Atendimento não encontrado.');
        $connection=$this->connectionModel->findActiveByName((string)($link['instance_name']??''));
        if(!$connection)throw new RuntimeException('A linha WhatsApp desta conversa está desativada ou não foi configurada.');
        $client=EvolutionClient::fromSettings()->withInstance((string)$connection['instance_name']);
        if(!$client->isConfigured())throw new RuntimeException('Configure a URL e o token da Evolution API em Configurações.');
        return [$client,$connection];
    }

    private function remoteJidForLink(array $link): string
    {
        return (string)($link['remote_jid']??$link['conversation_id']??'');
    }

    /** Grava a saída localmente, mantendo compatibilidade antes da migration v3. */
    private function storeLocalMessage(string $conversationId,string $instanceName,bool $isPrivate,string $content,int $userId): int
    {
        $params=[':conversation'=>$conversationId,':instance'=>$instanceName?:null,':private'=>$isPrivate?1:0,':content'=>$content,':sender'=>Auth::user()['name']??'',':uid'=>$userId];
        try{
            $this->db->prepare("INSERT INTO evolution_messages(conversation_id,instance_name,from_me,is_private,content,message_type,sender_name,user_id,wa_timestamp) VALUES(:conversation,:instance,1,:private,:content,'conversation',:sender,:uid,NOW())")
                ->execute($params);
        }catch(Throwable $e){
            unset($params[':instance']);
            $this->db->prepare("INSERT INTO evolution_messages(conversation_id,from_me,is_private,content,message_type,sender_name,user_id,wa_timestamp) VALUES(:conversation,1,:private,:content,'conversation',:sender,:uid,NOW())")
                ->execute($params);
        }
        return (int)$this->db->lastInsertId();
    }

    private function flowStepPayload(array $flow,int $step): array
    {
        $steps=$this->flowModel->parsedSteps($flow);
        $current=$steps[$step]??[];
        return [
            'id' => (int) $flow['id'], 'name' => $flow['name'], 'step' => $step, 'total' => count($steps),
            'title' => (string) ($current['title'] ?? ''),
            'channel' => ($current['channel'] ?? 'whatsapp') === 'email' ? 'email' : 'whatsapp',
            'suggestion' => (string) ($current['suggestion'] ?? ''),
            'email_subject' => (string) ($current['email_subject'] ?? ''),
            'guidance' => (string) ($current['guidance'] ?? ''),
        ];
    }

    /** Busca o histórico de mensagens de UMA conversa direto na Evolution (chamado só na primeira abertura, quando ainda não temos nada localmente). */
    private function backfillMessages(string $conversationId,?array $link): void
    {
        try{[$client]=$this->clientForLink($link);}catch(Throwable $e){return;}
        $remoteJid=$this->remoteJidForLink($link??[]);
        try{$records=$client->findMessages($remoteJid);}catch(Throwable $e){error_log('EvolutionInboxController::backfillMessages - '.$e->getMessage());return;}
        if(!$records)return;
        // A API retorna do mais recente pro mais antigo: inverte para gravar em ordem cronológica.
        foreach(array_reverse($records) as $m){
            $waId=(string)($m['key']['id']??'');
            if($waId!==''){
                $dup=$this->db->prepare('SELECT id FROM evolution_messages WHERE wa_message_id=:wa LIMIT 1');
                $dup->execute([':wa'=>$waId]);
                if($dup->fetch())continue;
            }
            $content=$this->extractContent($m['message']??[]);
            if($content===null)continue;
            $timestamp=(int)($m['messageTimestamp']??0);
            $this->storeBackfillMessage($conversationId,(string)($link['instance_name']??''),[
                    ':wa'=>$waId?:null,
                    ':from_me'=>!empty($m['key']['fromMe'])?1:0,
                    ':content'=>$content,
                    ':type'=>(string)($m['messageType']??'conversation'),
                    ':sender'=>(string)($m['pushName']??'')?:null,
                    ':ts'=>$timestamp?date('Y-m-d H:i:s',$timestamp):date('Y-m-d H:i:s'),
                ]);
        }
    }

    private function storeBackfillMessage(string $conversationId,string $instanceName,array $params): void
    {
        $params=[':conversation'=>$conversationId,':instance'=>$instanceName?:null]+$params;
        try{$this->db->prepare("INSERT INTO evolution_messages(conversation_id,instance_name,wa_message_id,from_me,content,message_type,sender_name,wa_timestamp) VALUES(:conversation,:instance,:wa,:from_me,:content,:type,:sender,:ts)")->execute($params);}
        catch(Throwable $e){unset($params[':instance']);$this->db->prepare("INSERT INTO evolution_messages(conversation_id,wa_message_id,from_me,content,message_type,sender_name,wa_timestamp) VALUES(:conversation,:wa,:from_me,:content,:type,:sender,:ts)")->execute($params);}
    }

    /** Extrai o texto de tipos comuns de mensagem do Baileys. Retorna null para tipos ainda não suportados (mídia binária, protocolo interno etc). */
    private function extractContent(array $message): ?string
    {
        if(isset($message['conversation'])&&is_string($message['conversation']))return $message['conversation'];
        if(isset($message['extendedTextMessage']['text']))return (string)$message['extendedTextMessage']['text'];
        if(isset($message['imageMessage']))return '[Imagem]'.(!empty($message['imageMessage']['caption'])?' '.$message['imageMessage']['caption']:'');
        if(isset($message['videoMessage']))return '[Vídeo]'.(!empty($message['videoMessage']['caption'])?' '.$message['videoMessage']['caption']:'');
        if(isset($message['audioMessage']))return '[Áudio]';
        if(isset($message['documentMessage']))return '[Documento] '.(string)($message['documentMessage']['fileName']??'');
        if(isset($message['stickerMessage']))return '[Figurinha]';
        if(isset($message['interactiveResponseMessage']['body']['text']))return (string)$message['interactiveResponseMessage']['body']['text'];
        return null;
    }

    /** Lista de etiquetas já usadas (nas conversas + tags de leads), para sugestão no autocomplete. */
    private function suggestedLabels(): array
    {
        try{
            $rows=$this->db->query("SELECT labels FROM evolution_conversation_links WHERE labels IS NOT NULL AND labels<>''")->fetchAll();
            $set=[];
            foreach($rows as $row)foreach(explode(',',(string)$row['labels']) as $label){$label=trim($label);if($label!=='')$set[$label]=true;}
            $tags=$this->db->query('SELECT name FROM tags ORDER BY name ASC LIMIT 100')->fetchAll();
            foreach($tags as $t)if(!empty($t['name']))$set[$t['name']]=true;
            $labels=array_keys($set);
            sort($labels,SORT_FLAG_CASE|SORT_STRING);
            return $labels;
        }catch(Throwable $e){return [];}
    }

    private function matchLeadByPhone(string $digits): ?array
    {
        if(strlen($digits)<8)return null;
        $needle=substr($digits,-11);
        $s=$this->db->prepare("SELECT id,assigned_to FROM leads WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp,phone),'+',''),'(',''),')',''),'-',''),' ','') LIKE :phone ORDER BY updated_at DESC LIMIT 1");
        $s->execute([':phone'=>'%'.$needle]);
        return $s->fetch()?:null;
    }

    private function listConversations(string $filter,string $q,string $instanceName=''): array
    {
        $isManager=Auth::hasRole(['admin','supervisor']);
        $where=[];$params=[];
        if(!$isManager){$where[]='x.assigned_to=:uid';$params[':uid']=Auth::id();}
        if($filter==='unread')$where[]='x.unread_count>0';
        if($filter==='mine')$where[]='x.assigned_to=:mine';
        if($filter==='mine')$params[':mine']=Auth::id();
        if($filter==='unassigned')$where[]='x.assigned_to IS NULL';
        if($instanceName!==''){$where[]='x.instance_name=:instance';$params[':instance']=$instanceName;}
        if($q!==''){$where[]='(x.contact_name LIKE :q OR x.contact_phone LIKE :q OR x.last_message LIKE :q)';$params[':q']='%'.$q.'%';}
        $sql='SELECT x.*, l.name lead_name, u.name assigned_name FROM evolution_conversation_links x LEFT JOIN leads l ON l.id=x.lead_id LEFT JOIN users u ON u.id=x.assigned_to';
        if($where)$sql.=' WHERE '.implode(' AND ',$where);
        $sql.=' ORDER BY x.last_message_at DESC, x.updated_at DESC LIMIT 200';
        try{$s=$this->db->prepare($sql);$s->execute($params);}
        catch(Throwable $e){
            // Antes da v3 a tabela ainda não tem instance_name. Mantém a
            // listagem legada funcional até a migration ser aplicada.
            if($instanceName!=='')return $this->listConversations($filter,$q,'');
            throw $e;
        }
        return array_map([$this,'conversationFromLink'],$s->fetchAll());
    }

    private function conversationFromLink(array $link): array
    {
        $labels=array_values(array_filter(array_map('trim',explode(',',(string)($link['labels']??'')))));
        return [
            'id'=>(string)$link['conversation_id'],
            'name'=>(string)($link['lead_name']??$link['contact_name']??'Contato WhatsApp'),
            'phone'=>(string)($link['contact_phone']??''),
            'avatar'=>(string)($link['avatar_url']??''),
            'last_message'=>(string)($link['last_message']??''),
            'unread'=>(int)($link['unread_count']??0),
            'labels'=>$labels,
            'instance_name'=>(string)($link['instance_name']??''),
            'link'=>$link,
        ];
    }

    private function loadMessages(string $id): array
    {
        $s=$this->db->prepare('SELECT * FROM evolution_messages WHERE conversation_id=:id ORDER BY id ASC LIMIT 200');
        $s->execute([':id'=>$id]);
        return array_map([$this,'formatMessage'],$s->fetchAll());
    }

    private function formatMessage(array $m): array
    {
        $ts=$m['wa_timestamp']?strtotime($m['wa_timestamp']):false;
        return [
            'id'=>(int)$m['id'],
            'content'=>(string)($m['content']??''),
            'type'=>((int)($m['from_me']??0))===1?'outgoing':'incoming',
            'private'=>(bool)($m['is_private']??false),
            'sender'=>(string)($m['sender_name']??''),
            'user_id'=>$m['user_id']!==null?(int)$m['user_id']:null,
            'time'=>$ts?date('H:i',$ts):'',
            'date_label'=>$ts?chat_date_label(date('Y-m-d',$ts)):'',
        ];
    }

    private function markRead(string $id): void
    {
        $this->db->prepare('UPDATE evolution_conversation_links SET unread_count=0 WHERE conversation_id=:id')->execute([':id'=>$id]);
    }

    private function link(string $id): ?array{$s=$this->db->prepare("SELECT x.*,l.name lead_name,l.phone lead_phone,l.whatsapp lead_whatsapp,l.email lead_email,l.interest lead_interest,u.name assigned_name FROM evolution_conversation_links x LEFT JOIN leads l ON l.id=x.lead_id LEFT JOIN users u ON u.id=x.assigned_to WHERE x.conversation_id=:id");$s->execute([':id'=>$id]);return $s->fetch()?:null;}
    private function canSee(?array $link): bool{return Auth::hasRole(['admin','supervisor'])||($link&&(int)$link['assigned_to']===Auth::id());}
}
