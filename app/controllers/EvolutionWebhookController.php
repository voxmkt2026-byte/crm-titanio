<?php
/**
 * app/controllers/EvolutionWebhookController.php
 * Webhook PÚBLICO (sem login) que recebe os eventos da Evolution API em
 * tempo real (MESSAGES_UPSERT = mensagem recebida/enviada). É a única fonte
 * de mensagens novas do Atendimento WhatsApp: como a Evolution API não tem
 * conceito de "conversa", quem guarda o histórico e monta a lista de
 * atendimentos é o nosso próprio banco (evolution_conversation_links +
 * evolution_messages), atualizado aqui.
 *
 * Segurança: protegido por token secreto na URL (?token=...), comparado com
 * "evolution_webhook_token" em Configurações (mesmo padrão do WebhookController
 * de captação de leads). Cadastre esta URL no painel da Evolution (Configurações
 * > Atendimento WhatsApp > "Configurar webhook") ou manualmente em /manager.
 *
 * URL a cadastrar: {BASE_URL}/webhook/evolution?token=SEU_TOKEN
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/models/Setting.php';
require_once APP_PATH . '/models/Notification.php';
require_once APP_PATH . '/models/EvolutionConnection.php';
require_once APP_PATH . '/services/EvolutionClient.php';

class EvolutionWebhookController extends Controller
{
    private PDO $db;
    private Setting $settingModel;
    private EvolutionConnection $connectionModel;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->settingModel = new Setting();
        $this->connectionModel = new EvolutionConnection();
    }

    public function receive(): void
    {
        $expected = (string) $this->settingModel->get('evolution_webhook_token', '');
        $received = (string) ($_GET['token'] ?? '');
        if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
            $this->json(['success' => false, 'message' => 'Token de segurança do webhook inválido ou não configurado.'], 401);
            return;
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $this->json(['success' => true, 'received' => 0]);
            return;
        }

        $event = strtolower((string) ($payload['event'] ?? ''));
        // A Evolution API pode entregar um evento único (data como objeto) ou,
        // em alguns eventos, uma lista (data como array de objetos).
        $dataList = [];
        $rawData = $payload['data'] ?? null;
        if (is_array($rawData)) {
            $dataList = array_is_list($rawData) ? $rawData : [$rawData];
        }

        $processed = 0;
        if (strpos($event, 'messages') !== false || strpos($event, 'message') !== false) {
            $instanceName = trim((string) ($payload['instance'] ?? ($payload['data']['instanceName'] ?? '')));
            if ($instanceName === '') {
                $instanceName = (string) (($this->connectionModel->default()['instance_name'] ?? ''));
            }
            // Só processa linhas ativas cadastradas. Isso evita que um
            // webhook de uma instância externa crie conversas indevidas.
            if (!$this->connectionModel->findActiveByName($instanceName)) {
                $this->json(['success' => true, 'received' => count($dataList), 'processed' => 0, 'ignored' => 'instância não cadastrada']);
                return;
            }
            foreach ($dataList as $item) {
                if ($this->storeIncomingMessage($item, $instanceName)) {
                    $processed++;
                }
            }
        }

        // Nunca falha "alto" para a Evolution não ficar reenviando/desativando o webhook.
        $this->json(['success' => true, 'received' => count($dataList), 'processed' => $processed]);
    }

    private function storeIncomingMessage(array $data, string $instanceName): bool
    {
        $key = $data['key'] ?? [];
        $remoteJid = (string) ($key['remoteJid'] ?? '');
        if ($remoteJid === '' || str_ends_with($remoteJid, '@g.us')) {
            // Ignora grupos e eventos sem remetente identificável (fase inicial: só atendimento 1:1).
            return false;
        }

        $fromMe = (bool) ($key['fromMe'] ?? false);
        $conversationId = $this->conversationIdFor($instanceName, $remoteJid);
        $waMessageId = (string) ($key['id'] ?? '');
        $message = $data['message'] ?? [];
        $content = $this->extractContent($message);
        if ($content === null) {
            return false; // tipo de mensagem ainda não suportado (mídia, figurinha etc. na v1 deste módulo)
        }

        $timestamp = (int) ($data['messageTimestamp'] ?? time());
        $waDate = date('Y-m-d H:i:s', $timestamp);
        $pushName = (string) ($data['pushName'] ?? '');

        // Evita duplicar em reenvios do provedor.
        if ($waMessageId !== '') {
            $dup = $this->db->prepare('SELECT id FROM evolution_messages WHERE wa_message_id = :wa LIMIT 1');
            $dup->execute([':wa' => $waMessageId]);
            if ($dup->fetch()) {
                return false;
            }
        }

        $this->storeMessage($conversationId, $instanceName, [
            ':wa'      => $waMessageId ?: null,
            ':from_me' => $fromMe ? 1 : 0,
            ':content' => $content,
            ':type'    => (string) ($data['messageType'] ?? 'conversation'),
            ':sender'  => $pushName ?: null,
            ':ts'      => $waDate,
        ]);

        $this->upsertConversation($conversationId, $instanceName, $remoteJid, $pushName, $content, $waDate, $fromMe);

        if (!$fromMe) {
            $this->notifyAssignee($conversationId, $pushName, $content);
        }

        return true;
    }

    /** Extrai o texto de tipos comuns de mensagem do Baileys. Retorna null para tipos ainda não suportados. */
    private function extractContent(array $message): ?string
    {
        if (isset($message['conversation']) && is_string($message['conversation'])) {
            return $message['conversation'];
        }
        if (isset($message['extendedTextMessage']['text'])) {
            return (string) $message['extendedTextMessage']['text'];
        }
        if (isset($message['imageMessage'])) {
            return '[Imagem]' . (!empty($message['imageMessage']['caption']) ? ' ' . $message['imageMessage']['caption'] : '');
        }
        if (isset($message['videoMessage'])) {
            return '[Vídeo]' . (!empty($message['videoMessage']['caption']) ? ' ' . $message['videoMessage']['caption'] : '');
        }
        if (isset($message['audioMessage'])) {
            return '[Áudio]';
        }
        if (isset($message['documentMessage'])) {
            return '[Documento] ' . (string) ($message['documentMessage']['fileName'] ?? '');
        }
        if (isset($message['stickerMessage'])) {
            return '[Figurinha]';
        }
        return null;
    }

    private function upsertConversation(string $conversationId, string $instanceName, string $remoteJid, string $pushName, string $lastMessage, string $lastMessageAt, bool $fromMe): void
    {
        $existing = $this->db->prepare('SELECT conversation_id, lead_id, assigned_to FROM evolution_conversation_links WHERE conversation_id = :id');
        $existing->execute([':id' => $conversationId]);
        $link = $existing->fetch();

        $phoneDigits = preg_replace('/\D/', '', explode('@', $remoteJid)[0]) ?? '';

        if (!$link) {
            $leadId = null;
            $assignedTo = null;
            $lead = $this->matchLeadByPhone($phoneDigits);
            if ($lead) {
                $leadId = $lead['id'];
                $assignedTo = $lead['assigned_to'];
            }
            $params=[
                ':id' => $conversationId, ':instance' => $instanceName, ':remote' => $remoteJid,
                ':lead' => $leadId, ':assigned' => $assignedTo,
                ':name' => $pushName ?: null, ':phone' => $phoneDigits,
                ':msg' => $lastMessage, ':msg_at' => $lastMessageAt,
                ':unread' => $fromMe ? 0 : 1,
            ];
            try{
                $this->db->prepare(
                    'INSERT INTO evolution_conversation_links(conversation_id, instance_name, remote_jid, lead_id, assigned_to, contact_name, contact_phone, last_message, last_message_at, unread_count, last_synced_at)
                     VALUES(:id, :instance, :remote, :lead, :assigned, :name, :phone, :msg, :msg_at, :unread, NOW())'
                )->execute($params);
            }catch(Throwable $e){
                unset($params[':instance'],$params[':remote']);
                $this->db->prepare(
                    'INSERT INTO evolution_conversation_links(conversation_id, lead_id, assigned_to, contact_name, contact_phone, last_message, last_message_at, unread_count, last_synced_at)
                     VALUES(:id, :lead, :assigned, :name, :phone, :msg, :msg_at, :unread, NOW())'
                )->execute($params);
            }
            return;
        }

        $this->db->prepare(
            'UPDATE evolution_conversation_links
             SET last_message = :msg, last_message_at = :msg_at, last_synced_at = NOW(),
                 contact_name = COALESCE(NULLIF(contact_name, \'\'), :name),
                 unread_count = ' . ($fromMe ? '0' : 'unread_count + 1') . '
             WHERE conversation_id = :id'
        )->execute([':msg' => $lastMessage, ':msg_at' => $lastMessageAt, ':name' => $pushName ?: null, ':id' => $conversationId]);
    }

    private function matchLeadByPhone(string $digits): ?array
    {
        if (strlen($digits) < 8) {
            return null;
        }
        $needle = substr($digits, -11);
        $s = $this->db->prepare(
            "SELECT id, assigned_to FROM leads WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp,phone),'+',''),'(',''),')',''),'-',''),' ','') LIKE :phone ORDER BY updated_at DESC LIMIT 1"
        );
        $s->execute([':phone' => '%' . $needle]);
        return $s->fetch() ?: null;
    }

    private function conversationIdFor(string $instanceName, string $remoteJid): string
    {
        try {
            $stmt=$this->db->prepare('SELECT conversation_id FROM evolution_conversation_links WHERE instance_name=:instance AND remote_jid=:remote LIMIT 1');
            $stmt->execute([':instance'=>$instanceName,':remote'=>$remoteJid]);
            $existing=$stmt->fetch();
            return $existing['conversation_id']??EvolutionClient::conversationKey($instanceName,$remoteJid);
        } catch (Throwable $e) {
            // Compatibilidade com instalações antes da migration v3.
            return $remoteJid;
        }
    }

    private function storeMessage(string $conversationId,string $instanceName,array $params): void
    {
        $params=[':conversation'=>$conversationId,':instance'=>$instanceName]+$params;
        try{
            $this->db->prepare(
                'INSERT INTO evolution_messages(conversation_id, instance_name, wa_message_id, from_me, content, message_type, sender_name, wa_timestamp)
                 VALUES(:conversation, :instance, :wa, :from_me, :content, :type, :sender, :ts)'
            )->execute($params);
        }catch(Throwable $e){
            unset($params[':instance']);
            $this->db->prepare(
                'INSERT INTO evolution_messages(conversation_id, wa_message_id, from_me, content, message_type, sender_name, wa_timestamp)
                 VALUES(:conversation, :wa, :from_me, :content, :type, :sender, :ts)'
            )->execute($params);
        }
    }

    private function notifyAssignee(string $remoteJid, string $pushName, string $content): void
    {
        try {
            $s = $this->db->prepare('SELECT assigned_to, lead_id FROM evolution_conversation_links WHERE conversation_id = :id');
            $s->execute([':id' => $remoteJid]);
            $link = $s->fetch();
            $recipients = [];
            if (!empty($link['assigned_to'])) {
                $recipients[] = (int) $link['assigned_to'];
            } else {
                $admins = $this->db->query("SELECT id FROM users WHERE active=1 AND role IN ('admin','supervisor')")->fetchAll();
                foreach ($admins as $admin) {
                    $recipients[] = (int) $admin['id'];
                }
            }
            $notification = new Notification();
            $preview = mb_substr($content, 0, 140);
            foreach (array_unique($recipients) as $userId) {
                $notification->create($userId, 'Nova mensagem no WhatsApp', ($pushName ?: 'Contato') . ': ' . $preview, 'atendimento-whatsapp?conversa=' . rawurlencode($remoteJid));
            }
        } catch (Throwable $e) {
            error_log('EvolutionWebhookController::notifyAssignee - ' . $e->getMessage());
        }
    }
}

if (!function_exists('array_is_list')) {
    function array_is_list(array $arr): bool
    {
        return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
    }
}
