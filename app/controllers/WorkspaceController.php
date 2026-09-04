<?php
require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/models/Lead.php';
require_once APP_PATH . '/models/User.php';
require_once APP_PATH . '/models/Setting.php';
require_once APP_PATH . '/models/Notification.php';
require_once APP_PATH . '/services/GeminiService.php';
require_once APP_PATH . '/services/WebsiteCrawlerService.php';
require_once APP_PATH . '/services/AutomationRunner.php';

class WorkspaceController extends Controller
{
    private PDO $db;
    public function __construct() { $this->db = Database::getInstance(); }

    private function page(string $mode, string $title): void
    {
        $this->requireLogin();
        $data = [];
        try {
            if ($mode === 'calendar') {
                // Evita abrir a agenda com o formulário novo antes de a
                // migration correspondente ter criado os campos necessários.
                $columns = $this->db->query(
                    "SHOW COLUMNS FROM calendar_events WHERE Field IN ('event_type', 'person_name')"
                )->fetchAll();
                if (count($columns) < 2) {
                    $data['migrationPending'] = true;
                }
            } elseif ($mode === 'content') {
                $uid=(int)Auth::id();
                $stmt=$this->db->prepare("SELECT p.*,u.name creator_name,l.name lead_name,a.name assigned_name FROM workspace_pages p JOIN users u ON u.id=p.created_by LEFT JOIN leads l ON l.id=p.lead_id LEFT JOIN users a ON a.id=p.assigned_to WHERE p.visibility='equipe' OR p.created_by=:uid OR p.assigned_to=:uid2 ORDER BY p.is_pinned DESC,p.updated_at DESC");
                $stmt->execute([':uid'=>$uid,':uid2'=>$uid]);$data['pages']=$stmt->fetchAll();
                $data['attachments'] = $this->db->query("SELECT a.* FROM workspace_attachments a ORDER BY a.created_at DESC")->fetchAll();
                if(Auth::hasRole(['admin']))$data['knowledgeSources']=$this->db->query("SELECT s.*,p.title page_title FROM knowledge_sources s LEFT JOIN workspace_pages p ON p.id=s.page_id ORDER BY s.updated_at DESC")->fetchAll();
            } elseif ($mode === 'whiteboard') {
                $uid=(int)Auth::id();
                $stmt=$this->db->prepare("SELECT DISTINCT w.*,l.name lead_name,GROUP_CONCAT(wm.user_id) member_ids FROM whiteboards w LEFT JOIN leads l ON l.id=w.lead_id LEFT JOIN whiteboard_members wm ON wm.board_id=w.id WHERE w.created_by=:uid OR w.visibility='equipe' OR wm.user_id=:uid2 GROUP BY w.id ORDER BY w.updated_at DESC");
                $stmt->execute([':uid'=>$uid,':uid2'=>$uid]);$data['boards']=$stmt->fetchAll();
            } elseif ($mode === 'automations') {
                $data['flows'] = $this->db->query(
                    "SELECT f.*, COUNT(r.id) AS runs_30d,
                            SUM(CASE WHEN r.status = 'sucesso' THEN 1 ELSE 0 END) AS successes_30d,
                            SUM(CASE WHEN r.status IN ('parcial','erro') THEN 1 ELSE 0 END) AS issues_30d,
                            MAX(r.created_at) AS last_attempt_at
                     FROM automation_flows f
                     LEFT JOIN automation_runs r ON r.flow_id = f.id
                         AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                     GROUP BY f.id
                     ORDER BY f.is_template ASC, f.updated_at DESC"
                )->fetchAll();
                $data['automationRuns'] = $this->db->query(
                    "SELECT r.*, f.name AS flow_name, l.name AS lead_name, l.lead_code
                     FROM automation_runs r
                     JOIN automation_flows f ON f.id = r.flow_id
                     LEFT JOIN leads l ON l.id = r.lead_id
                     ORDER BY r.created_at DESC LIMIT 12"
                )->fetchAll();
                $data['automationTriggers'] = AutomationRunner::triggerCatalog();
                $data['automationActions'] = AutomationRunner::actionCatalog();
                $data['automationCanManage'] = Auth::hasRole(['admin', 'supervisor']);
            }
        } catch (Throwable $e) { $data['migrationPending'] = true; }
        $data['users'] = (new User())->allActive();
        $this->view('workspace/index', array_merge($data, ['mode'=>$mode,'pageTitle'=>$title]));
    }
    public function calendar(): void { $this->page('calendar','Calendário'); }
    public function content(): void { $this->page('content','Documentos e Wiki'); }
    public function whiteboard(): void { $this->page('whiteboard','Whiteboards'); }
    public function automations(): void { $this->page('automations','Automações'); }

    public function events(): void
    {
        $this->requireLogin();
        $rows=$this->db->query("SELECT e.*,l.name lead_name,u.name assigned_name FROM calendar_events e LEFT JOIN leads l ON l.id=e.lead_id LEFT JOIN users u ON u.id=e.assigned_to ORDER BY e.start_at")->fetchAll();
        $this->json(array_map(fn($e)=>[
            'id'=>(int)$e['id'],
            'title'=>$e['title'],
            'start'=>str_replace(' ','T',$e['start_at']),
            'end'=>$e['end_at']?str_replace(' ','T',$e['end_at']):null,
            'allDay'=>(bool)$e['all_day'],
            'backgroundColor'=>$e['color'],
            'extendedProps'=>[
                'description'=>$e['description'],
                'guidance'=>$e['guidance'],
                'lead'=>$e['lead_name'],
                'lead_id'=>$e['lead_id'],
                'person_name'=>$e['person_name'],
                'event_type'=>$e['event_type'] ?: 'reuniao',
                'assigned'=>$e['assigned_name'],
                'assigned_to'=>$e['assigned_to'],
                'priority'=>$e['priority'],
                'task_id'=>$e['task_id'],
            ],
        ],$rows));
    }
    public function saveEvent(): void
    {
        $this->requireLogin(); Csrf::verifyRequest();
        $id=(int)$this->input('id',0); $start=str_replace('T',' ',(string)$this->input('start_at','')); $end=str_replace('T',' ',(string)$this->input('end_at',''))?:null;
        if (!$start || !strtotime($start) || trim((string)$this->input('title',''))==='') $this->json(['success'=>false,'message'=>'Título e início são obrigatórios.'],422);
        $priority=in_array($this->input('priority'),['baixa','media','alta','urgente'],true)?$this->input('priority'):'media';
        $eventType=in_array($this->input('event_type'),['reuniao','ligacao','visita','follow_up','tarefa','outro'],true)?$this->input('event_type'):'reuniao';
        $personName=mb_substr(trim((string)$this->input('person_name','')),0,180);
        $params=[':title'=>trim((string)$this->input('title')),':description'=>trim((string)$this->input('description','')),':guidance'=>trim((string)$this->input('guidance','')),':start_at'=>$start,':end_at'=>$end,':all_day'=>(int)$this->input('all_day',0),':color'=>(string)$this->input('color','#3b82f6'),':priority'=>$priority,':event_type'=>$eventType,':lead_id'=>(int)$this->input('lead_id',0)?:null,':person_name'=>$personName?:null,':assigned_to'=>(int)$this->input('assigned_to',0)?:null];
        if($id){$params[':id']=$id;$stmt=$this->db->prepare("UPDATE calendar_events SET title=:title,description=:description,guidance=:guidance,start_at=:start_at,end_at=:end_at,all_day=:all_day,color=:color,priority=:priority,event_type=:event_type,lead_id=:lead_id,person_name=:person_name,assigned_to=:assigned_to WHERE id=:id");}
        else{$params[':created_by']=Auth::id();$stmt=$this->db->prepare("INSERT INTO calendar_events(title,description,guidance,start_at,end_at,all_day,color,priority,event_type,lead_id,person_name,assigned_to,created_by) VALUES(:title,:description,:guidance,:start_at,:end_at,:all_day,:color,:priority,:event_type,:lead_id,:person_name,:assigned_to,:created_by)");}
        $stmt->execute($params);$eventId=$id?:$this->db->lastInsertId();
        if((int)$this->input('create_task',0)===1){
            $check=$this->db->prepare("SELECT task_id FROM calendar_events WHERE id=:id");$check->execute([':id'=>$eventId]);$taskId=(int)($check->fetch()['task_id']??0);
            if($taskId<=0){$task=$this->db->prepare("INSERT INTO tasks(title,description,lead_id,creator_id,assigned_to,priority,status,due_at,created_at,updated_at) VALUES(:title,:description,:lead,:creator,:assigned,:priority,'pendente',:due,NOW(),NOW())");$task->execute([':title'=>$params[':title'],':description'=>$params[':guidance']?:$params[':description'],':lead'=>$params[':lead_id'],':creator'=>Auth::id(),':assigned'=>$params[':assigned_to']?:Auth::id(),':priority'=>$priority,':due'=>$start]);$taskId=(int)$this->db->lastInsertId();$this->db->prepare("UPDATE calendar_events SET task_id=:task WHERE id=:id")->execute([':task'=>$taskId,':id'=>$eventId]);}
        }
        if(!empty($params[':assigned_to']) && (int)$params[':assigned_to']!==Auth::id())(new Notification())->create((int)$params[':assigned_to'],'Novo evento atribuído',$params[':title'].' · '.format_date($start,true),'calendario');
        $this->json(['success'=>true,'id'=>$eventId]);
    }
    public function moveEvent(string $id): void
    {
        $this->requireLogin(); Csrf::verifyRequest();
        $stmt=$this->db->prepare("UPDATE calendar_events SET start_at=:start_at,end_at=:end_at WHERE id=:id");
        $stmt->execute([':start_at'=>str_replace('T',' ',(string)$this->input('start')),':end_at'=>$this->input('end')?str_replace('T',' ',(string)$this->input('end')):null,':id'=>(int)$id]);
        $this->json(['success'=>true]);
    }
    public function deleteEvent(string $id): void
    {
        $this->requireLogin(); Csrf::verifyRequest();
        $eventId=(int)$id;$stmt=$this->db->prepare("DELETE FROM calendar_events WHERE id=:id AND (created_by=:uid OR :is_admin=1)");
        $stmt->execute([':id'=>$eventId,':uid'=>Auth::id(),':is_admin'=>Auth::hasRole(['admin','supervisor'])?1:0]);
        $this->json(['success'=>$stmt->rowCount()>0,'message'=>$stmt->rowCount()>0?'Evento excluído.':'Você não pode excluir este evento.']);
    }
    public function savePage(): void
    {
        $this->requireLogin(); Csrf::verifyRequest(); $id=(int)$this->input('id',0);
        $visibility=$this->input('visibility')==='privado'?'privado':'equipe';
        $tags=trim((string)$this->input('tags',''));if((int)$this->input('ai_enabled',0)!==1)$tags=trim($tags.', no-ai',', ');
        $params=[':type'=>in_array($this->input('type'),['documento','wiki'],true)?$this->input('type'):'documento',':category'=>trim((string)$this->input('category','')),':title'=>trim((string)$this->input('title')),':content'=>(string)$this->input('content',''),':tags'=>$tags,':lead_id'=>(int)$this->input('lead_id',0)?:null,':assigned_to'=>(int)$this->input('assigned_to',0)?:null,':pinned'=>(int)$this->input('is_pinned',0),':visibility'=>$visibility,':uid'=>Auth::id()];
        if($id){$params[':id']=$id;$sql="UPDATE workspace_pages SET type=:type,category=:category,title=:title,content=:content,tags=:tags,lead_id=:lead_id,assigned_to=:assigned_to,is_pinned=:pinned,visibility=:visibility,updated_by=:uid WHERE id=:id";}else{$sql="INSERT INTO workspace_pages(type,category,title,content,tags,lead_id,assigned_to,is_pinned,visibility,created_by,updated_by) VALUES(:type,:category,:title,:content,:tags,:lead_id,:assigned_to,:pinned,:visibility,:uid,:uid)";}
        $this->db->prepare($sql)->execute($params); $this->json(['success'=>true,'id'=>$id?:$this->db->lastInsertId()]);
    }

    public function addKnowledgeSource(): void
    {
        $this->requireLogin();Csrf::verifyRequest();$this->requireKnowledgeAdmin();$url=trim((string)$this->input('url',''));
        try{(new WebsiteCrawlerService())->validate($url);}catch(Throwable $e){$this->json(['success'=>false,'message'=>$e->getMessage()],422);}
        $stmt=$this->db->prepare("INSERT INTO knowledge_sources(url,title,category,tags,ai_enabled,status,created_by) VALUES(:url,:title,:category,:tags,:ai,'novo',:uid) ON DUPLICATE KEY UPDATE category=VALUES(category),tags=VALUES(tags),ai_enabled=VALUES(ai_enabled),updated_at=NOW()");
        $stmt->execute([':url'=>$url,':title'=>parse_url($url,PHP_URL_HOST),':category'=>trim((string)$this->input('category','Site oficial'))?:'Site oficial',':tags'=>trim((string)$this->input('tags','site, crawler, titanium')),':ai'=>(int)$this->input('ai_enabled',0),':uid'=>Auth::id()]);$this->json(['success'=>true]);
    }

    public function crawlKnowledgeSource(string $id): void
    {
        $this->requireLogin();Csrf::verifyRequest();$this->requireKnowledgeAdmin();$sourceId=(int)$id;$stmt=$this->db->prepare("SELECT * FROM knowledge_sources WHERE id=:id");$stmt->execute([':id'=>$sourceId]);$source=$stmt->fetch();if(!$source)$this->json(['success'=>false,'message'=>'Fonte não encontrada.'],404);
        try{
            $result=(new WebsiteCrawlerService())->extract($source['url']);
            $content=$result['content'];
            $aiUsed=false;
            // Quando a fonte tem IA habilitada, reorganiza o texto bruto extraído num
            // artigo mais completo/legível (nunca inventa dados novos, ver GeminiService::
            // analyzeCrawledContent). Falha graciosamente: se a IA não estiver configurada
            // ou der erro, mantém o conteúdo bruto extraído (nunca bloqueia a análise).
            if((int)($source['ai_enabled']??0)===1){
                try{$ai=(new GeminiService())->analyzeCrawledContent($result['title'],$content);if($ai['success']&&trim((string)($ai['content']??''))!==''){$content=$ai['content'];$aiUsed=true;}}catch(Throwable $e){error_log('WorkspaceController::crawlKnowledgeSource - análise IA falhou: '.$e->getMessage());}
            }
            $hash=hash('sha256',$content);
            $this->db->prepare("UPDATE knowledge_sources SET title=:title,extracted_content=:content,content_hash=:hash,status='analisado',last_error=NULL,last_crawled_at=NOW() WHERE id=:id")->execute([':title'=>$result['title'],':content'=>$content,':hash'=>$hash,':id'=>$sourceId]);
            $this->json(['success'=>true,'title'=>$result['title'],'excerpt'=>mb_substr(trim(strip_tags($content)),0,900),'ai_used'=>$aiUsed]);
        }catch(Throwable $e){$this->db->prepare("UPDATE knowledge_sources SET status='erro',last_error=:error,last_crawled_at=NOW() WHERE id=:id")->execute([':error'=>mb_substr($e->getMessage(),0,1000),':id'=>$sourceId]);$this->json(['success'=>false,'message'=>$e->getMessage()],422);}
    }

    public function publishKnowledgeSource(string $id): void
    {
        $this->requireLogin();Csrf::verifyRequest();$this->requireKnowledgeAdmin();$sourceId=(int)$id;$stmt=$this->db->prepare("SELECT * FROM knowledge_sources WHERE id=:id");$stmt->execute([':id'=>$sourceId]);$s=$stmt->fetch();if(!$s||trim((string)$s['extracted_content'])==='')$this->json(['success'=>false,'message'=>'Analise a fonte antes de publicar.'],422);
        $content='<p><small>Fonte oficial: <a href="'.htmlspecialchars($s['url'],ENT_QUOTES,'UTF-8').'" target="_blank" rel="noopener">'.htmlspecialchars($s['url'],ENT_QUOTES,'UTF-8').'</a> · analisada em '.date('d/m/Y H:i').'</small></p>'.$s['extracted_content'];$tags=trim((string)$s['tags']);if(!(int)$s['ai_enabled'])$tags=trim($tags.', no-ai',', ');$common=[':title'=>$s['title'],':content'=>$content,':category'=>$s['category'],':tags'=>$tags,':uid'=>Auth::id()];
        $this->db->beginTransaction();try{if(!empty($s['page_id'])){$this->db->prepare("UPDATE workspace_pages SET title=:title,content=:content,category=:category,tags=:tags,updated_by=:uid WHERE id=:page")->execute($common+[':page'=>$s['page_id']]);$pageId=(int)$s['page_id'];}else{$this->db->prepare("INSERT INTO workspace_pages(type,category,title,content,tags,is_pinned,visibility,created_by,updated_by) VALUES('wiki',:category,:title,:content,:tags,0,'equipe',:uid,:uid)")->execute($common);$pageId=(int)$this->db->lastInsertId();}$this->db->prepare("UPDATE knowledge_sources SET page_id=:page,status='publicado' WHERE id=:id")->execute([':page'=>$pageId,':id'=>$sourceId]);$this->db->commit();$this->json(['success'=>true,'page_id'=>$pageId]);}catch(Throwable $e){$this->db->rollBack();$this->json(['success'=>false,'message'=>'Não foi possível publicar na Wiki: '.$e->getMessage()],500);}
    }

    public function deleteKnowledgeSource(string $id): void
    {
        $this->requireLogin();Csrf::verifyRequest();$this->requireKnowledgeAdmin();$sourceId=(int)$id;$stmt=$this->db->prepare("SELECT page_id FROM knowledge_sources WHERE id=:id");$stmt->execute([':id'=>$sourceId]);$pageId=(int)($stmt->fetchColumn()?:0);$this->db->beginTransaction();try{$this->db->prepare("DELETE FROM knowledge_sources WHERE id=:id")->execute([':id'=>$sourceId]);if($pageId)$this->db->prepare("DELETE FROM workspace_pages WHERE id=:id")->execute([':id'=>$pageId]);$this->db->commit();$this->json(['success'=>true]);}catch(Throwable $e){$this->db->rollBack();$this->json(['success'=>false,'message'=>'Não foi possível remover a fonte.'],500);}
    }

    private function requireKnowledgeAdmin(): void
    {
        if(!Auth::hasRole(['admin']))$this->json(['success'=>false,'message'=>'Recurso exclusivo para administradores.'],403);
    }
    public function saveBoard(): void
    {
        $this->requireLogin(); Csrf::verifyRequest(); $id=(int)$this->input('id',0); $json=(string)$this->input('board_json','[]'); json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if($id){$stmt=$this->db->prepare("UPDATE whiteboards SET title=:title,lead_id=:lead,board_json=:json WHERE id=:id");$p=[':id'=>$id];}else{$stmt=$this->db->prepare("INSERT INTO whiteboards(title,lead_id,board_json,created_by) VALUES(:title,:lead,:json,:uid)");$p=[':uid'=>Auth::id()];}
        $stmt->execute($p+[':title'=>trim((string)$this->input('title','Novo fluxo')),':lead'=>(int)$this->input('lead_id',0)?:null,':json'=>$json]);$boardId=$id?:$this->db->lastInsertId();
        $this->db->prepare("DELETE FROM whiteboard_members WHERE board_id=:id")->execute([':id'=>$boardId]);
        foreach((array)$this->input('member_ids',[]) as $uid){$uid=(int)$uid;if($uid>0)$this->db->prepare("INSERT IGNORE INTO whiteboard_members(board_id,user_id) VALUES(:board,:uid)")->execute([':board'=>$boardId,':uid'=>$uid]);}
        $this->json(['success'=>true,'id'=>$boardId]);
    }

    public function deleteBoard(string $id): void
    {
        $this->requireLogin(); Csrf::verifyRequest(); $boardId=(int)$id;
        $stmt=$this->db->prepare("DELETE FROM whiteboards WHERE id=:id AND (created_by=:uid OR :manager=1)");
        $stmt->execute([':id'=>$boardId,':uid'=>Auth::id(),':manager'=>Auth::hasRole(['admin','supervisor'])?1:0]);
        $this->json(['success'=>$stmt->rowCount()>0,'message'=>$stmt->rowCount()>0?'Whiteboard excluído.':'Sem permissão para excluir este whiteboard.']);
    }

    public function uploadAttachment(string $id): void
    {
        $this->requireLogin();Csrf::verifyRequest();$pageId=(int)$id;
        if(empty($_FILES['files']))$this->json(['success'=>false,'message'=>'Selecione ao menos um arquivo.'],422);
        $allowed=['application/pdf'=>'pdf','text/plain'=>'txt','application/vnd.ms-excel'=>'xls','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx','application/zip'=>'xlsx','application/octet-stream'=>'office','text/csv'=>'csv','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        $base=UPLOADS_PATH.'/workspace';if(!is_dir($base))@mkdir($base,0755,true);$saved=[];
        $files=$_FILES['files'];$count=is_array($files['name'])?count($files['name']):1;
        for($i=0;$i<$count;$i++){ $name=is_array($files['name'])?$files['name'][$i]:$files['name'];$tmp=is_array($files['tmp_name'])?$files['tmp_name'][$i]:$files['tmp_name'];$size=(int)(is_array($files['size'])?$files['size'][$i]:$files['size']);$error=(int)(is_array($files['error'])?$files['error'][$i]:$files['error']);if($error!==UPLOAD_ERR_OK||$size>15*1024*1024)continue;$mime=(string)mime_content_type($tmp);$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$validExt=['pdf','txt','xls','xlsx','csv','jpg','jpeg','png','webp','gif'];if(!isset($allowed[$mime])||!in_array($ext,$validExt,true))continue;$stored=bin2hex(random_bytes(16)).'.'.($ext==='jpeg'?'jpg':$ext);if(!move_uploaded_file($tmp,$base.'/'.$stored))continue;$stmt=$this->db->prepare("INSERT INTO workspace_attachments(page_id,original_name,stored_name,mime_type,size_bytes,uploaded_by) VALUES(:page,:original,:stored,:mime,:size,:uid)");$stmt->execute([':page'=>$pageId,':original'=>basename($name),':stored'=>$stored,':mime'=>$mime,':size'=>$size,':uid'=>Auth::id()]);$saved[]=['name'=>$name,'url'=>UPLOADS_URL.'/workspace/'.$stored];}
        $this->json(['success'=>(bool)$saved,'files'=>$saved,'message'=>$saved?'Arquivos enviados.':'Nenhum arquivo válido. Use PDF, TXT, Excel, CSV ou imagem até 15 MB.']);
    }
    public function saveFlow(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();
        $this->requireAutomationManager();

        $definition = $this->automationDefinitionFromRequest();
        $id = (int) $this->input('id', 0);
        $params = [
            ':name' => $definition['name'], ':description' => $definition['description'],
            ':trigger' => $definition['trigger_type'], ':config' => json_encode($definition['trigger_config'], JSON_UNESCAPED_UNICODE),
            ':actions' => json_encode($definition['actions'], JSON_UNESCAPED_UNICODE), ':uid' => Auth::id(),
        ];

        if ($id) {
            $params[':id'] = $id;
            unset($params[':uid']);
            $stmt = $this->db->prepare(
                'UPDATE automation_flows SET name=:name, description=:description, trigger_type=:trigger,
                 trigger_config=:config, actions_json=:actions, is_template=0, active=1 WHERE id=:id'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO automation_flows(name,description,trigger_type,trigger_config,actions_json,active,is_template,created_by)
                 VALUES(:name,:description,:trigger,:config,:actions,1,0,:uid)'
            );
        }
        $stmt->execute($params);
        $this->json(['success' => true, 'id' => $id ?: (int) $this->db->lastInsertId()]);
    }

    /** Testa somente os critérios: não envia mensagens nem altera nenhum lead. */
    public function previewFlow(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();
        $this->requireAutomationManager();
        $definition = $this->automationDefinitionFromRequest();
        $definition['id'] = (int) $this->input('id', 0);
        $this->json(['success' => true, 'preview' => (new AutomationRunner())->preview($definition)]);
    }
    public function toggleFlow(string $id): void
    {
        $this->requireLogin(); Csrf::verifyRequest();
        $this->requireAutomationManager();
        $this->db->prepare("UPDATE automation_flows SET active=IF(active=1,0,1) WHERE id=:id AND is_template=0")->execute([':id'=>(int)$id]);
        $this->json(['success'=>true]);
    }
    public function deleteFlow(string $id): void
    {
        $this->requireLogin(); Csrf::verifyRequest();
        $this->requireAutomationManager();
        $this->db->prepare("DELETE FROM automation_flows WHERE id=:id AND is_template=0")->execute([':id'=>(int)$id]);
        $this->json(['success'=>true]);
    }
    public function runFlow(string $id): void
    {
        $this->requireLogin(); Csrf::verifyRequest();
        $this->requireAutomationManager();
        $this->json(['success'=>true,'result'=>(new AutomationRunner())->run((int)$id)]);
    }

    private function requireAutomationManager(): void
    {
        if (!Auth::hasRole(['admin', 'supervisor'])) {
            $this->json(['success' => false, 'message' => 'Sem permissão para administrar automações.'], 403);
        }
    }

    /** Converte e valida o formulário antes que ele chegue ao motor. */
    private function automationDefinitionFromRequest(): array
    {
        $name = mb_substr(trim((string) $this->input('name', '')), 0, 180);
        if (mb_strlen($name) < 3) {
            $this->json(['success' => false, 'message' => 'Dê um nome de pelo menos 3 caracteres ao fluxo.'], 422);
        }
        $trigger = (string) $this->input('trigger_type', 'lead_stale');
        $triggers = AutomationRunner::triggerCatalog();
        if (!isset($triggers[$trigger])) {
            $this->json(['success' => false, 'message' => 'Gatilho de automação inválido.'], 422);
        }

        $actions = [];
        foreach ((array) $this->input('actions', []) as $action) {
            $action = AutomationRunner::normalizeAction((string) $action);
            if ($action && !in_array($action, $actions, true)) {
                $actions[] = $action;
            }
        }
        if (!$actions) {
            $this->json(['success' => false, 'message' => 'Selecione ao menos uma ação para o fluxo.'], 422);
        }

        $value = max(1, min($trigger === 'lead_score' ? 100 : 720, (int) $this->input('trigger_value', $triggers[$trigger]['default'])));
        $statuses = array_values(array_intersect(
            array_map('strval', (array) $this->input('trigger_statuses', [])),
            ['novo','primeiro_contato','tentando_contato','em_negociacao','documentacao','aguardando_cliente','aguardando_aprovacao','aprovado','fechado','perdido','sem_interesse','sem_entrada','numero_invalido','nao_responde','bloqueou','duplicado']
        ));
        $priorities = ['baixa','media','alta','urgente'];
        $sources = ['facebook','instagram','google','indicacao','site','landing_page','organico','outros','whatsapp','cadastro_manual'];
        $source = (string) $this->input('condition_source', '');
        $assigned = (int) $this->input('condition_assigned_to', 0);

        $config = [
            'days' => $trigger === 'lead_stale' ? min(365, $value) : 10,
            'window_hours' => $trigger === 'lead_stale' ? 24 : $value,
            'min_score' => $trigger === 'lead_score' ? $value : 70,
            'statuses' => $statuses,
            'source' => in_array($source, $sources, true) ? $source : '',
            'assigned_to' => $assigned > 0 ? $assigned : null,
            'only_unassigned' => (bool) $this->input('only_unassigned', false),
            'cooldown_hours' => max(1, min(720, (int) $this->input('cooldown_hours', 24))),
            'whatsapp_template' => mb_substr(trim((string) $this->input('whatsapp_template', '')), 0, 180),
            'whatsapp_language' => mb_substr(trim((string) $this->input('whatsapp_language', 'pt_BR')), 0, 20) ?: 'pt_BR',
            'email_subject' => mb_substr(trim((string) $this->input('email_subject', '')), 0, 180),
            'email_body' => trim((string) $this->input('email_body', '')),
            'priority_to' => in_array($this->input('priority_to', ''), $priorities, true) ? $this->input('priority_to') : 'alta',
            'status_to' => in_array($this->input('status_to', ''), ['novo','primeiro_contato','tentando_contato','em_negociacao','documentacao','aguardando_cliente','aguardando_aprovacao','aprovado','perdido','sem_interesse','sem_entrada','numero_invalido','nao_responde','bloqueou','duplicado'], true) ? $this->input('status_to') : '',
            'reassign_to' => max(0, (int) $this->input('reassign_to', 0)),
            'followup_hours' => max(1, min(720, (int) $this->input('followup_hours', 24))),
            'history_message' => trim((string) $this->input('history_message', '')),
            'tag' => [
                'name' => mb_substr(trim((string) $this->input('tag_name', 'Automação')), 0, 80) ?: 'Automação',
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->input('tag_color', '')) ? $this->input('tag_color') : '#7c3aed',
            ],
            'task' => [
                'title' => mb_substr(trim((string) $this->input('task_title', '')), 0, 200),
                'description' => trim((string) $this->input('task_description', '')),
                'priority' => in_array($this->input('task_priority', ''), $priorities, true) ? $this->input('task_priority') : 'alta',
                'due_hours' => max(1, min(720, (int) $this->input('task_due_hours', 24))),
                'assigned_to' => max(0, (int) $this->input('task_assigned_to', 0)),
            ],
            'event' => [
                'title' => mb_substr(trim((string) $this->input('event_title', '')), 0, 180),
                'description' => trim((string) $this->input('event_description', '')),
                'in_hours' => max(1, min(720, (int) $this->input('event_in_hours', 24))),
                'duration_minutes' => max(15, min(480, (int) $this->input('event_duration_minutes', 30))),
                'assigned_to' => max(0, (int) $this->input('event_assigned_to', 0)),
            ],
        ];
        if ($trigger === 'lead_status' && !$statuses) {
            $this->json(['success' => false, 'message' => 'Escolha ao menos um status para este gatilho.'], 422);
        }
        if (in_array('change_status', $actions, true) && $config['status_to'] === '') {
            $this->json(['success' => false, 'message' => 'Escolha o status de destino da ação selecionada.'], 422);
        }
        if (in_array('reassign_owner', $actions, true) && !$config['reassign_to']) {
            $this->json(['success' => false, 'message' => 'Escolha o novo responsável da ação selecionada.'], 422);
        }

        return [
            'name' => $name,
            'description' => trim((string) $this->input('description', '')),
            'trigger_type' => $trigger,
            'trigger_config' => $config,
            'actions' => $actions,
        ];
    }
    public function preferences(): void
    {
        $this->requireLogin(); Csrf::verifyRequest(); $json=(string)$this->input('preferences_json','{}');json_decode($json,true,512,JSON_THROW_ON_ERROR);
        $stmt=$this->db->prepare("INSERT INTO user_workspace_preferences(user_id,preferences_json) VALUES(:uid,:json) ON DUPLICATE KEY UPDATE preferences_json=VALUES(preferences_json)");$stmt->execute([':uid'=>Auth::id(),':json'=>$json]);$this->json(['success'=>true]);
    }
    public function assistant(): void
    {
        $this->requireLogin();Csrf::verifyRequest();$prompt=trim((string)$this->input('prompt',''));
        if($prompt==='')$this->json(['success'=>false,'message'=>'Digite uma pergunta.'],422);
        $purpose=in_array($this->input('purpose'),['assistant','approach','objection'],true)?$this->input('purpose'):'assistant';
        $result=(new GeminiService())->ask($prompt,(int)Auth::id(),(string)(Auth::user()['role']??''),$purpose);
        $this->json($result,$result['success']?200:502);
    }
}
