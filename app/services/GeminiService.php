<?php
require_once APP_PATH.'/core/Database.php';
require_once APP_PATH.'/models/Setting.php';

class GeminiService
{
    private PDO $db;
    private array $settings;
    public function __construct(){ $this->db=Database::getInstance();$this->settings=(new Setting())->allAsMap(); }

    /** A análise de relatórios é opcional: só aparece quando existe chave configurada. */
    public function isConfigured(): bool
    {
        return trim((string) ($this->settings['gemini_api_key'] ?? getenv('GEMINI_API_KEY'))) !== '';
    }

    /**
     * Analisa exclusivamente um snapshot agregado construído pelo módulo de
     * relatórios. Não consulta leads, conversas, telefones ou mensagens.
     */
    public function analyzeReport(array $snapshot, string $question = ''): array
    {
        $key = trim((string) ($this->settings['gemini_api_key'] ?? getenv('GEMINI_API_KEY')));
        if ($key === '') {
            return ['success' => false, 'message' => 'Configure a API Key em Configurações > Assistente Gemini IA.'];
        }

        $question = trim($question) ?: 'Faça uma leitura executiva: principais resultados, riscos, oportunidades e três próximos passos priorizados.';
        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($snapshotJson === false) {
            return ['success' => false, 'message' => 'Não foi possível preparar os indicadores para análise.'];
        }

        $instructions = "Você é um analista comercial e operacional. Responda em português do Brasil, de forma objetiva e acionável. "
            . "Use APENAS os indicadores agregados recebidos abaixo; não invente dados, causas, metas ou percentuais. "
            . "Quando um dado estiver ausente ou for zero, deixe isso claro. Diferencie observação de recomendação. "
            . "Estruture em: resumo executivo, alertas/riscos, oportunidades e próximos passos priorizados. "
            . "Não solicite dados pessoais nem mencione contatos individuais de leads.";

        $payload = [
            'model' => trim((string) ($this->settings['gemini_model'] ?? 'gemini-3.6-flash')) ?: 'gemini-3.6-flash',
            'input' => $instructions . "\n\nINDICADORES AGREGADOS DO RELATÓRIO:\n" . mb_substr($snapshotJson, 0, 40000) . "\n\nPERGUNTA OPCIONAL DO USUÁRIO:\n" . mb_substr($question, 0, 1000),
            'store' => false,
        ];
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key, 'Api-Revision: 2026-05-20'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 45,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) {
            $data = json_decode((string) $raw, true);
            return ['success' => false, 'message' => $data['error']['message'] ?? ($error ?: 'A IA não respondeu agora.')];
        }

        $data = json_decode((string) $raw, true);
        $parts = [];
        foreach (($data['steps'] ?? []) as $step) {
            if (($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            foreach (($step['content'] ?? []) as $item) {
                if (($item['type'] ?? '') === 'text' && !empty($item['text'])) {
                    $parts[] = $item['text'];
                }
            }
        }
        $text = trim(implode("\n", $parts));
        return ['success' => $text !== '', 'text' => $text, 'message' => $text === '' ? 'Resposta sem conteúdo.' : null];
    }

    public function ask(string $question,int $userId,string $role,string $purpose='assistant'): array
    {
        $key=trim((string)($this->settings['gemini_api_key']??getenv('GEMINI_API_KEY')));
        if($key==='') return ['success'=>false,'message'=>'Configure a API Key em Configurações > Assistente Gemini IA.'];
        $context=$this->context($question,$userId,$role);
        $brand="Você é o assistente oficial da Titanium Consultoria. Responda em português do Brasil. Seja muito breve por padrão: entregue primeiro a resposta direta e só aprofunde quando necessário ou solicitado. Use Markdown limpo, parágrafos curtos, **negrito** apenas em pontos-chave, listas quando facilitarem a leitura e no máximo um emoji pertinente, nunca decorativo. Comunicação leve, objetiva, moderna, formal-jovem, convincente e simples. Em vendas e prospecção, use perguntas consultivas, rapport ético, técnicas avançadas de PNL sem manipulação, clareza de valor, quebra de objeções e um próximo passo direto. Nunca invente dados. Diferencie claramente fatos do CRM de sugestões. Respeite integralmente o escopo recebido; não peça nem revele dados fora dele.";
        if($purpose==='approach')$brand.=" Crie uma abordagem pronta para uso, curta, personalizada e com CTA claro.";
        if($purpose==='objection')$brand.=" Responda à objeção com acolhimento, investigação, reenquadramento de valor e fechamento leve.";
        $payload=['model'=>trim((string)($this->settings['gemini_model']??'gemini-3.6-flash'))?:'gemini-3.6-flash','input'=>$brand."\n\nDADOS AUTORIZADOS DO CRM:\n".$context."\n\nSOLICITAÇÃO:\n".$question,'store'=>false];
        $ch=curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key,'Api-Revision: 2026-05-20'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_TIMEOUT=>35]);$raw=curl_exec($ch);$error=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($raw===false||$code>=400){$data=json_decode((string)$raw,true);return ['success'=>false,'message'=>$data['error']['message']??($error?:'A IA não respondeu agora.')];}
        $data=json_decode((string)$raw,true);$parts=[];foreach(($data['steps']??[]) as $step){if(($step['type']??'')!=='model_output')continue;foreach(($step['content']??[]) as $item)if(($item['type']??'')==='text'&&!empty($item['text']))$parts[]=$item['text'];}
        $text=trim(implode("\n",$parts));return ['success'=>$text!=='','text'=>$text,'message'=>$text===''?'Resposta sem conteúdo.':null,'sources'=>$context===''?[]:['Leads e tarefas permitidos','Central de conhecimento']];
    }

    /**
     * Reorganiza o texto bruto extraído de uma página (WebsiteCrawlerService) em um
     * artigo de Wiki mais completo e legível: agrupa por tópicos, remove ruído
     * repetido e preserva TODAS as informações factuais encontradas (nunca inventa
     * dados novos). Usado por WorkspaceController::crawlKnowledgeSource() quando a
     * fonte tem "ai_enabled" ligado. Não usa o contexto do CRM (context()) — é uma
     * tarefa isolada de organização de texto, não uma pergunta sobre leads/tarefas.
     */
    public function analyzeCrawledContent(string $title, string $rawHtml): array
    {
        $key=trim((string)($this->settings['gemini_api_key']??getenv('GEMINI_API_KEY')));
        if($key==='')return ['success'=>false,'message'=>'Configure a API Key em Configurações > Assistente Gemini IA.'];
        $plainText=trim(preg_replace('/\s+/u',' ',strip_tags($rawHtml)));
        if($plainText==='')return ['success'=>false,'message'=>'Nada para analisar.'];
        $instructions="Você organiza conteúdo extraído de sites em artigos de Wiki interna. "
            ."Reescreva o texto abaixo (extraído automaticamente da página \"{$title}\") em HTML simples "
            ."(apenas tags h2, h3, p, ul, li, strong — sem <html>/<body>/estilos), agrupando por tópicos "
            ."com títulos claros. PRESERVE TODAS as informações factuais (números, preços, telefones, "
            ."endereços, horários, condições) exatamente como aparecem. NÃO invente nenhum dado novo. "
            ."Remova apenas ruído óbvio de navegação repetido (menus, \"leia mais\", cookies). "
            ."Responda só com o HTML do artigo, sem comentários.";
        $payload=['model'=>trim((string)($this->settings['gemini_model']??'gemini-3.6-flash'))?:'gemini-3.6-flash','input'=>$instructions."\n\nTEXTO EXTRAÍDO:\n".mb_substr($plainText,0,60000),'store'=>false];
        $ch=curl_init('https://generativelanguage.googleapis.com/v1beta/interactions');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key,'Api-Revision: 2026-05-20'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),CURLOPT_TIMEOUT=>50]);
        $raw=curl_exec($ch);$error=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($raw===false||$code>=400){$data=json_decode((string)$raw,true);return ['success'=>false,'message'=>$data['error']['message']??($error?:'A IA não respondeu agora.')];}
        $data=json_decode((string)$raw,true);$parts=[];foreach(($data['steps']??[]) as $step){if(($step['type']??'')!=='model_output')continue;foreach(($step['content']??[]) as $item)if(($item['type']??'')==='text'&&!empty($item['text']))$parts[]=$item['text'];}
        $text=trim(implode("\n",$parts));
        // A IA às vezes envolve a resposta em ```html ... ``` — remove se vier assim.
        $text=preg_replace('/^```(?:html)?\s*|\s*```$/','',$text);
        return ['success'=>$text!=='','content'=>$text,'message'=>$text===''?'Resposta sem conteúdo.':null];
    }

    private function context(string $question,int $uid,string $role): string
    {
        $all=in_array($role,['admin','supervisor'],true);$term=trim(preg_replace('/[^\pL\pN@.+ -]/u',' ',mb_substr($question,0,120)));$like='%'.preg_replace('/\s+/','%',$term).'%';$out=[];
        try{$sql="SELECT id,name,phone,whatsapp,email,status,interest,desired_value,next_contact_at,notes,assigned_to FROM leads WHERE (name LIKE :q OR phone LIKE :q OR whatsapp LIKE :q OR email LIKE :q OR status LIKE :q)".($all?'':' AND assigned_to=:uid')." ORDER BY updated_at DESC LIMIT 15";$s=$this->db->prepare($sql);$p=[':q'=>$like];if(!$all)$p[':uid']=$uid;$s->execute($p);$rows=$s->fetchAll();if(!$rows){$sql="SELECT id,name,phone,whatsapp,email,status,interest,desired_value,next_contact_at,notes,assigned_to FROM leads".($all?'':' WHERE assigned_to=:uid')." ORDER BY updated_at DESC LIMIT 10";$s=$this->db->prepare($sql);$s->execute($all?[]:[':uid'=>$uid]);$rows=$s->fetchAll();}$out[]="LEADS VISÍVEIS:\n".json_encode($rows,JSON_UNESCAPED_UNICODE); }catch(Throwable $e){}
        try{$sql="SELECT t.id,t.title,t.description,t.priority,t.status,t.due_at,t.lead_id,l.name lead_name,u.name assigned_name FROM tasks t LEFT JOIN leads l ON l.id=t.lead_id LEFT JOIN users u ON u.id=t.assigned_to".($all?'':' WHERE t.assigned_to=:uid OR t.creator_id=:uid2')." ORDER BY t.updated_at DESC LIMIT 20";$s=$this->db->prepare($sql);$s->execute($all?[]:[':uid'=>$uid,':uid2'=>$uid]);$out[]="TAREFAS VISÍVEIS:\n".json_encode($s->fetchAll(),JSON_UNESCAPED_UNICODE);}catch(Throwable $e){}
        try{$s=$this->db->prepare("SELECT title,category,tags,content FROM workspace_pages WHERE type='wiki' AND (tags IS NULL OR tags NOT LIKE '%no-ai%') AND (visibility='equipe' OR created_by=:uid OR assigned_to=:uid2) ORDER BY is_pinned DESC,updated_at DESC LIMIT 20");$s->execute([':uid'=>$uid,':uid2'=>$uid]);$wiki=array_map(function($p){$p['content']=trim(strip_tags($p['content']));return $p;},$s->fetchAll());$out[]="WIKI INTERNA:\n".json_encode($wiki,JSON_UNESCAPED_UNICODE);}catch(Throwable $e){}
        return implode("\n\n",$out);
    }
}
