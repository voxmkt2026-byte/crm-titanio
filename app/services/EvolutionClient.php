<?php
/**
 * app/services/EvolutionClient.php
 * Cliente para a Evolution API (gateway de WhatsApp self-hosted, o mesmo
 * produto do painel "/manager"). Diferente de uma API de CRM/inbox: aqui
 * não existe conceito de "conversa" — só instância, mensagens e chats.
 * O histórico de conversas/mensagens do nosso Atendimento WhatsApp é
 * responsabilidade do nosso próprio banco (ver evolution_conversation_links
 * e evolution_messages), alimentado pelo webhook (EvolutionWebhookController)
 * e pelo envio feito por este cliente.
 *
 * Autenticação: header "apikey" (chave global do .env ou token da instância).
 * Documentação: https://docs.evolutionfoundation.com.br/evolution-api
 */
require_once APP_PATH . '/models/Setting.php';

class EvolutionClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $instance;

    public function __construct(string $baseUrl, string $apiKey, string $instance = '')
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl !== '' && !preg_match('#^https://#i', $baseUrl)) {
            throw new InvalidArgumentException('A URL da Evolution API deve usar HTTPS.');
        }
        $this->baseUrl = $baseUrl;
        $this->apiKey = trim($apiKey);
        $this->instance = trim($instance);
    }

    public static function fromSettings(): self
    {
        $s = (new Setting())->allAsMap();
        return new self(
            (string) ($s['evolution_api_url'] ?? ''),
            (string) ($s['evolution_api_token'] ?? ''),
            (string) ($s['evolution_instance_name'] ?? '')
        );
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    public function hasInstance(): bool
    {
        return $this->instance !== '';
    }

    /** Cliente idêntico apontando para outra instância do mesmo servidor Evolution. */
    public function withInstance(string $instance): self
    {
        return new self($this->baseUrl, $this->apiKey, $instance);
    }

    /** Chave curta e estável para não misturar a mesma conversa em duas linhas. */
    public static function conversationKey(string $instance, string $remoteJid): string
    {
        return 'evo_' . substr(hash('sha256', trim($instance) . "\n" . trim($remoteJid)), 0, 64);
    }

    /** GET /instance/fetchInstances — lista instâncias visíveis para esta apikey (usado no "Testar conexão"). */
    public function fetchInstances(): array
    {
        $data = $this->request('GET', '/instance/fetchInstances');
        return is_array($data) && array_is_list($data) ? $data : ($data['data'] ?? $data);
    }

    /** POST /chat/findContacts/{instance} — contatos salvos (nome, foto de perfil), indexados por remoteJid. */
    public function findContacts(): array
    {
        $this->requireInstance();
        $data = $this->request('POST', '/chat/findContacts/' . rawurlencode($this->instance), [], []);
        return is_array($data) && array_is_list($data) ? $data : ($data['data'] ?? []);
    }

    /** GET /instance/connect/{instance} — gera QR code / pairing code para (re)conectar a instância. */
    public function connect(): array
    {
        $this->requireInstance();
        return $this->request('GET', '/instance/connect/' . rawurlencode($this->instance));
    }

    /** POST /webhook/set/{instance} — registra a URL do webhook para receber mensagens em tempo real. */
    public function setWebhook(string $url, array $events): array
    {
        $this->requireInstance();
        return $this->request('POST', '/webhook/set/' . rawurlencode($this->instance), [], [
            'webhook' => [
                'enabled' => true,
                'url'     => $url,
                'events'  => $events,
            ],
        ]);
    }

    /** POST /message/sendText/{instance} — envia mensagem de texto para um número (com ou sem sufixo @s.whatsapp.net). */
    public function sendText(string $number, string $text, string $payloadMode = 'auto'): array
    {
        $this->requireInstance();
        $path = '/message/sendText/' . rawurlencode($this->instance);
        $number = $this->normalizeNumber($number);
        $officialPayload = [
            'number'      => $number,
            'textMessage' => ['text' => $text],
            'delay'       => 350,
            'linkPreview' => true,
        ];
        $legacyPayload = [
            'number'      => $number,
            'text'        => $text,
            'delay'       => 350,
            'linkPreview' => true,
        ];

        // A documentação atual usa textMessage, mas há instalações v2 mais
        // antigas que validam apenas a chave "text". No modo automático só
        // tentamos o segundo formato quando a primeira resposta é uma falha
        // de validação — nunca repetimos um envio possivelmente aceito.
        if ($payloadMode === 'legacy_text') {
            return $this->request('POST', $path, [], $legacyPayload);
        }
        if ($payloadMode === 'official') {
            return $this->request('POST', $path, [], $officialPayload);
        }

        try {
            return $this->request('POST', $path, [], $officialPayload);
        } catch (RuntimeException $e) {
            if (!preg_match('/requires property ["\\\']text["\\\']|property ["\\\']text["\\\']|textMessage.+undefined/i', $e->getMessage())) {
                throw $e;
            }
            return $this->request('POST', $path, [], $legacyPayload);
        }
    }

    /** POST /chat/findChats/{instance} — lista todos os chats já existentes na instância (usado para sincronizar o histórico). */
    public function findChats(): array
    {
        $this->requireInstance();
        $data = $this->request('POST', '/chat/findChats/' . rawurlencode($this->instance), [], []);
        return is_array($data) && array_is_list($data) ? $data : ($data['data'] ?? []);
    }

    /** POST /chat/findMessages/{instance} — mensagens de um chat específico (usado para carregar o histórico ao abrir uma conversa). */
    public function findMessages(string $remoteJid): array
    {
        $this->requireInstance();
        $data = $this->request('POST', '/chat/findMessages/' . rawurlencode($this->instance), [], [
            'where' => ['key' => ['remoteJid' => $remoteJid]],
        ]);
        // A API não respeita "take"/paginação de forma confiável: vem tudo em messages.records.
        return $data['messages']['records'] ?? ($data['messages'] ?? []);
    }

    /** GET /label/findLabels/{instance} — etiquetas cadastradas na instância (definições, não as aplicadas por chat). */
    public function findLabels(): array
    {
        $this->requireInstance();
        $data = $this->request('GET', '/label/findLabels/' . rawurlencode($this->instance));
        return is_array($data) && array_is_list($data) ? $data : ($data['data'] ?? []);
    }

    /** POST /label/handleLabel/{instance} — adiciona/remove uma etiqueta de um chat. */
    public function handleLabel(string $remoteJid, string $labelId, string $action): array
    {
        $this->requireInstance();
        return $this->request('POST', '/label/handleLabel/' . rawurlencode($this->instance), [], [
            'id'     => $remoteJid,
            'name'   => $labelId,
            'type'   => 'chat',
            'action' => $action, // add|remove
        ]);
    }

    /** Normaliza telefone BR para o formato aceito pela Evolution (DDI+DDD+número, só dígitos). */
    private function normalizeNumber(string $phone): string
    {
        if (str_ends_with(strtolower($phone), '@lid')) {
            throw new RuntimeException('O WhatsApp não forneceu o número real deste contato (ID LID). Informe o número no painel lateral antes de responder.');
        }
        $digits = preg_replace('/\D/', '', explode('@', $phone)[0]) ?? '';
        if (strlen($digits) >= 12 && strpos($digits, '55') === 0) {
            return $digits;
        }
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '55' . $digits;
        }
        return $digits;
    }

    private function requireInstance(): void
    {
        if ($this->instance === '') {
            throw new RuntimeException('Configure o nome da instância da Evolution em Configurações.');
        }
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new RuntimeException('Configure a URL e o token (apikey) da Evolution API em Configurações.');
        }

        $url = $this->baseUrl . $path . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => [
                'apikey: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Falha de conexão com a Evolution API: ' . $error);
        }

        $data = json_decode((string) $raw, true);

        if ($code === 404) {
            throw new RuntimeException('Evolution API retornou 404 (não encontrado) para ' . $path . '. Confira a URL base e o nome da instância em Configurações.');
        }
        if ($code < 200 || $code >= 300) {
            $message = is_array($data) ? ($data['message'] ?? $data['error'] ?? json_encode($data)) : ('HTTP ' . $code);
            if (is_array($message)) {
                // Algumas versões retornam a lista de validações dentro de
                // outra lista. JSON preserva o texto real (ex.: "requires
                // property text"), que também permite o fallback seguro de
                // compatibilidade no sendText().
                $message = json_encode($message, JSON_UNESCAPED_UNICODE) ?: 'Erro de validação da Evolution API';
            }
            throw new RuntimeException('Evolution API respondeu HTTP ' . $code . ': ' . $message);
        }

        return is_array($data) ? $data : [];
    }
}

if (!function_exists('array_is_list')) {
    // Compat: array_is_list() só existe a partir do PHP 8.1.
    function array_is_list(array $arr): bool
    {
        return $arr === [] || array_keys($arr) === range(0, count($arr) - 1);
    }
}
