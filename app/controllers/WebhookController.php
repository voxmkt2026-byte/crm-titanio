<?php
/**
 * app/controllers/WebhookController.php
 * Webhook público de captação automática de leads (Fase 3): recebe payloads
 * de Meta Lead Ads, Google Ads (lead form extensions) e um formato genérico
 * (qualquer ferramenta que consiga fazer um POST form-urlencoded/JSON).
 *
 * IMPORTANTE: estas rotas são PÚBLICAS (sem login, ver app/core/Router.php).
 * A segurança é feita via token secreto (?token=... na URL, comparado com o
 * valor salvo em Configurações > "Token do Webhook") e, no caso da Meta, também
 * pelo fluxo padrão de verificação hub.challenge/hub.verify_token.
 *
 * URLs a cadastrar (ver README.md para o passo a passo completo):
 *   POST {BASE_URL}/webhook/meta?token=SEU_TOKEN
 *   GET  {BASE_URL}/webhook/meta?token=SEU_TOKEN   (verificação da Meta)
 *   POST {BASE_URL}/webhook/google?token=SEU_TOKEN
 *   POST {BASE_URL}/webhook/generico?token=SEU_TOKEN
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/Lead.php';
require_once APP_PATH . '/models/LeadHistory.php';
require_once APP_PATH . '/models/LeadScore.php';
require_once APP_PATH . '/models/Setting.php';

class WebhookController extends Controller
{
    private Lead $leadModel;
    private LeadHistory $historyModel;
    private LeadScore $scoreModel;
    private Setting $settingModel;

    public function __construct()
    {
        $this->leadModel = new Lead();
        $this->historyModel = new LeadHistory();
        $this->scoreModel = new LeadScore();
        $this->settingModel = new Setting();
    }

    /**
     * GET /webhook/meta — verificação inicial exigida pela Meta ao cadastrar
     * o webhook (Lead Ads / WhatsApp Cloud API): responde com o valor de
     * hub.challenge em texto puro se hub.verify_token bater com o token
     * configurado em Configurações ("webhook_token").
     */
    public function verifyMeta(): void
    {
        // IMPORTANTE: o PHP substitui automaticamente "." por "_" nos nomes de
        // parâmetros de query string ao popular $_GET (comportamento nativo,
        // não é um bug), então "hub.mode" chega como "hub_mode" e assim por
        // diante. Aceitamos as duas grafias para não depender desse detalhe.
        $mode = $_GET['hub_mode'] ?? ($_GET['hub.mode'] ?? '');
        $verifyToken = $_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? '');
        $challenge = $_GET['hub_challenge'] ?? ($_GET['hub.challenge'] ?? '');

        $expectedToken = (string) $this->settingModel->get('webhook_token', '');

        if ($expectedToken === '' || $mode !== 'subscribe' || !hash_equals($expectedToken, (string) $verifyToken)) {
            http_response_code(403);
            echo 'Token de verificação inválido.';
            exit;
        }

        http_response_code(200);
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }

    /** POST /webhook/meta — recebe a notificação de novo lead (Meta Lead Ads). */
    public function meta(): void
    {
        if (!$this->checkToken()) {
            return;
        }

        $payload = $this->readJsonBody();

        // Formato oficial do webhook da Meta: entry[].changes[].value com leadgen_id.
        // Para obter os dados completos do lead é necessário chamar a Graph API
        // com o token de acesso da página (salvo em Configurações como
        // "integration_meta_token"). Se a chamada falhar (token não configurado,
        // ambiente sem acesso à internet, etc.), ainda assim registramos o lead
        // com os metadados disponíveis, para não perder o evento.
        $leadsData = [];

        if (isset($payload['entry']) && is_array($payload['entry'])) {
            foreach ($payload['entry'] as $entry) {
                $changes = $entry['changes'] ?? [];
                foreach ($changes as $change) {
                    if (($change['field'] ?? '') !== 'leadgen') {
                        continue;
                    }
                    $value = $change['value'] ?? [];
                    $leadgenId = $value['leadgen_id'] ?? null;

                    $fetched = $leadgenId ? $this->fetchMetaLeadDetails((string) $leadgenId) : null;
                    $leadsData[] = $fetched ?: [
                        'campaign' => $value['campaign_name'] ?? null,
                        'adset'    => $value['adgroup_name'] ?? null,
                        'ad'       => $value['ad_name'] ?? null,
                        'internal_notes' => 'Webhook Meta recebido, mas não foi possível buscar os dados completos do lead (leadgen_id: ' . ($leadgenId ?? 'desconhecido') . '). Verifique o token da Graph API em Configurações.',
                    ];
                }
            }
        } else {
            // Payload já "achatado" (ex: testes manuais ou integrações intermediárias
            // como Zapier/Make que já entregam os campos prontos).
            $leadsData[] = $payload;
        }

        $created = 0;
        foreach ($leadsData as $leadData) {
            if ($this->createOrUpdateLead($leadData, 'facebook')) {
                $created++;
            }
        }

        $this->json(['success' => true, 'received' => count($leadsData), 'created' => $created]);
    }

    /**
     * POST /webhook/google — recebe leads de Google Ads (Lead Form Extensions
     * / webhook de integração). Aceita tanto o formato oficial do Google
     * (user_column_data: [{column_id, column_name, string_value}, ...])
     * quanto um JSON/form já achatado.
     */
    public function google(): void
    {
        if (!$this->checkToken()) {
            return;
        }

        $payload = $this->readJsonBody();
        if (empty($payload)) {
            $payload = $_POST;
        }

        $mapped = [];

        if (isset($payload['user_column_data']) && is_array($payload['user_column_data'])) {
            foreach ($payload['user_column_data'] as $column) {
                $name = strtolower((string) ($column['column_name'] ?? $column['string_value'] ?? ''));
                $value = $column['string_value'] ?? null;
                if ($value === null) {
                    continue;
                }
                $mapped = array_merge($mapped, $this->mapGoogleColumn($name, $value));
            }
            $mapped['campaign'] = $payload['campaign_id'] ?? ($payload['campaign_name'] ?? null);
            $mapped['adset']    = $payload['adgroup_id'] ?? ($payload['adgroup_name'] ?? null);
            $mapped['utm_term'] = $payload['gcl_id'] ?? null;
        } else {
            $mapped = $payload;
        }

        $success = $this->createOrUpdateLead($mapped, 'google');
        $this->json(['success' => $success]);
    }

    /**
     * POST /webhook/generico — aceita JSON ou form-urlencoded com campos
     * já no formato usado pelo CRM (name, phone, whatsapp, email, city,
     * state, campaign, utm_source, utm_medium, utm_campaign, utm_content,
     * utm_term, interest, source [opcional, senão usa "outros"]).
     */
    public function generico(): void
    {
        if (!$this->checkToken()) {
            return;
        }

        $payload = $this->readJsonBody();
        if (empty($payload)) {
            $payload = $_POST;
        }

        $source = isset($payload['source']) && $payload['source'] !== '' ? (string) $payload['source'] : 'outros';
        $success = $this->createOrUpdateLead($payload, $source);

        $this->json(['success' => $success]);
    }

    // ---- Suporte interno ----

    /** Valida o token secreto (?token=) contra Configurações. Responde 401 e retorna false se inválido. */
    private function checkToken(): bool
    {
        $expected = (string) $this->settingModel->get('webhook_token', '');
        $received = (string) ($_GET['token'] ?? '');

        if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
            $this->json(['success' => false, 'message' => 'Token de segurança do webhook inválido ou não configurado.'], 401);
            return false;
        }

        return true;
    }

    /** Lê e decodifica o corpo bruto da requisição como JSON (retorna [] se não for JSON válido). */
    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Busca os dados completos de um lead do Meta Lead Ads via Graph API,
     * usando o token salvo em Configurações ("integration_meta_token").
     * Retorna null se não configurado ou se a chamada falhar.
     */
    private function fetchMetaLeadDetails(string $leadgenId): ?array
    {
        $token = (string) $this->settingModel->get('integration_meta_token', '');
        if ($token === '' || !function_exists('curl_init')) {
            return null;
        }

        $url = 'https://graph.facebook.com/v19.0/' . rawurlencode($leadgenId) . '?access_token=' . rawurlencode($token);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode < 200 || $httpCode >= 300 || !$response) {
                return null;
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded) || empty($decoded['field_data'])) {
                return null;
            }

            $mapped = [
                'campaign' => $decoded['campaign_name'] ?? null,
                'ad'       => $decoded['ad_name'] ?? null,
            ];

            foreach ($decoded['field_data'] as $field) {
                $key = strtolower((string) ($field['name'] ?? ''));
                $value = $field['values'][0] ?? null;
                if ($value === null) {
                    continue;
                }
                $mapped = array_merge($mapped, $this->mapGoogleColumn($key, $value));
            }

            return $mapped;
        } catch (Throwable $e) {
            error_log('WebhookController::fetchMetaLeadDetails - ' . $e->getMessage());
            return null;
        }
    }

    /** Mapeia um nome de campo comum (Meta/Google usam nomenclaturas parecidas) para o campo do CRM. */
    private function mapGoogleColumn(string $name, $value): array
    {
        $name = strtolower(trim($name));

        $map = [
            'full_name'     => 'name',
            'name'          => 'name',
            'first_name'    => 'name',
            'email'         => 'email',
            'phone_number'  => 'phone',
            'phone'         => 'phone',
            'whatsapp'      => 'whatsapp',
            'city'          => 'city',
            'state'         => 'state',
            'region'        => 'state',
            'post_code'     => 'zipcode',
            'zip_code'      => 'zipcode',
            'company_name'  => 'company',
            'job_title'     => 'profession',
        ];

        if (isset($map[$name])) {
            return [$map[$name] => $value];
        }

        return [];
    }

    /**
     * Cria (ou atualiza um duplicado existente) o lead a partir dos dados
     * mapeados, registra o evento na timeline e recalcula o Lead Score.
     */
    private function createOrUpdateLead(array $data, string $defaultSource): bool
    {
        $allowedSources = ['facebook', 'instagram', 'google', 'indicacao', 'site', 'landing_page', 'organico', 'outros'];
        $source = isset($data['source']) && in_array($data['source'], $allowedSources, true) ? $data['source'] : $defaultSource;

        $name = isset($data['name']) ? trim((string) $data['name']) : null;
        $phone = $this->normalizeDigits($data['phone'] ?? null);
        $whatsapp = $this->normalizeDigits($data['whatsapp'] ?? null) ?: $phone;
        $email = isset($data['email']) ? trim((string) $data['email']) : null;

        if (!$name && !$phone && !$whatsapp && !$email) {
            // Nada de aproveitável no payload recebido — não cria lead vazio.
            error_log('WebhookController: payload recebido sem nenhum campo aproveitável (' . $defaultSource . ').');
            return false;
        }

        // Evita duplicar leads em reenvios/retries do provedor.
        $duplicate = $this->leadModel->findDuplicate($phone, $whatsapp, null, $email);
        if ($duplicate) {
            $this->historyModel->add(
                (int) $duplicate['id'],
                null,
                'observacao',
                'Novo evento recebido via webhook (' . $defaultSource . ') para um lead já existente — nenhum novo cadastro criado.'
            );
            return true;
        }

        $leadFields = [
            'name'           => $name ?: null,
            'phone'          => $phone,
            'whatsapp'       => $whatsapp,
            'email'          => $email ?: null,
            'city'           => isset($data['city']) ? trim((string) $data['city']) : null,
            'state'          => isset($data['state']) ? strtoupper(substr(trim((string) $data['state']), 0, 2)) : null,
            'zipcode'        => $this->normalizeDigits($data['zipcode'] ?? null),
            'company'        => isset($data['company']) ? trim((string) $data['company']) : null,
            'profession'     => isset($data['profession']) ? trim((string) $data['profession']) : null,
            'source'         => $source,
            'campaign'       => isset($data['campaign']) ? (string) $data['campaign'] : null,
            'adset'          => isset($data['adset']) ? (string) $data['adset'] : null,
            'ad'             => isset($data['ad']) ? (string) $data['ad'] : null,
            'utm_source'     => isset($data['utm_source']) ? (string) $data['utm_source'] : null,
            'utm_medium'     => isset($data['utm_medium']) ? (string) $data['utm_medium'] : null,
            'utm_campaign'   => isset($data['utm_campaign']) ? (string) $data['utm_campaign'] : null,
            'utm_content'    => isset($data['utm_content']) ? (string) $data['utm_content'] : null,
            'utm_term'       => isset($data['utm_term']) ? (string) $data['utm_term'] : null,
            'interest'       => isset($data['interest']) ? (string) $data['interest'] : null,
            'internal_notes' => $data['internal_notes'] ?? null,
            'status'         => 'novo',
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
            'device'         => 'webhook',
            'browser'        => 'Webhook: ' . $defaultSource,
        ];

        // Remove chaves nulas para deixar o INSERT mais limpo (colunas aceitam NULL de qualquer forma)
        $leadFields = array_filter($leadFields, fn($v) => $v !== null && $v !== '');
        // status sempre deve ir, mesmo que não vazio (já garantido acima)
        $leadFields['status'] = 'novo';

        $leadId = $this->leadModel->create($leadFields);

        // Notifica o responsável (quando informado pela integração) e todos os
        // administradores quando um lead chega automaticamente.
        try {
            require_once APP_PATH . '/models/Notification.php';
            $notification = new Notification();
            $recipients = [];
            if (!empty($leadFields['assigned_to'])) $recipients[] = (int) $leadFields['assigned_to'];
            $admins = Database::getInstance()->query("SELECT id FROM users WHERE active=1 AND role IN ('admin','supervisor')")->fetchAll();
            foreach ($admins as $admin) $recipients[] = (int) $admin['id'];
            foreach (array_unique($recipients) as $recipient) {
                $notification->create($recipient, 'Novo lead recebido', ($leadFields['name'] ?? 'Novo lead') . ' chegou via ' . $defaultSource . '.', 'leads/' . $leadId);
            }
        } catch (Throwable $e) { error_log('Push novo lead: ' . $e->getMessage()); }

        $this->historyModel->add($leadId, null, 'criacao', 'Lead recebido automaticamente via webhook (' . $defaultSource . ').');

        $interactionCount = count($this->historyModel->forLead($leadId));
        $score = $this->scoreModel->calculate(array_merge($leadFields, ['interaction_count' => $interactionCount]));
        $this->leadModel->update($leadId, ['lead_score' => $score]);

        log_activity('lead_webhook_recebido', 'Lead #' . $leadId . ' criado via webhook (' . $defaultSource . ').');

        return true;
    }

    private function normalizeDigits($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $value);
        return $digits !== '' ? $digits : null;
    }
}
