<?php
/**
 * app/core/WhatsappClient.php
 * Cliente simples para a WhatsApp Cloud API (Meta), usando cURL nativo do
 * PHP (sem SDKs externas). Lê token/phone_number_id da tabela `settings`
 * (chaves: whatsapp_token, whatsapp_phone_id), configuráveis pela tela de
 * Configurações.
 *
 * Documentação oficial: https://developers.facebook.com/docs/whatsapp/cloud-api
 */

class WhatsappClient
{
    private const API_VERSION = 'v19.0';
    private const BASE_URL = 'https://graph.facebook.com';

    private ?string $token;
    private ?string $phoneNumberId;
    private int $timeout = 15;

    public function __construct(?string $token, ?string $phoneNumberId)
    {
        $this->token = $token ?: null;
        $this->phoneNumberId = $phoneNumberId ?: null;
    }

    /** Monta o cliente a partir das credenciais salvas em Configurações. */
    public static function fromSettings(): self
    {
        require_once APP_PATH . '/models/Setting.php';
        $settingModel = new Setting();
        $map = $settingModel->allAsMap();

        return new self(
            $map['whatsapp_token'] ?? null,
            $map['whatsapp_phone_id'] ?? null
        );
    }

    /** Indica se há credenciais mínimas configuradas para tentar o envio. */
    public function isConfigured(): bool
    {
        return !empty($this->token) && !empty($this->phoneNumberId);
    }

    /**
     * Envia uma mensagem de texto livre.
     * IMPORTANTE: a WhatsApp Cloud API só permite mensagens de texto livre
     * dentro da janela de 24h após a última mensagem do cliente. Fora dessa
     * janela, é necessário usar sendTemplateMessage() com um template
     * pré-aprovado pela Meta.
     *
     * @return array{success:bool, message:string, raw:?array}
     */
    public function sendTextMessage(string $to, string $message): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'text',
            'text'              => ['preview_url' => false, 'body' => $message],
        ];

        return $this->request($payload);
    }

    /**
     * Envia uma mensagem de template pré-aprovado (necessário para iniciar
     * conversa fora da janela de 24h, ex: notificações/lembretes).
     *
     * @param array $components Componentes do template (ver documentação Meta),
     *              ex: [['type' => 'body', 'parameters' => [['type'=>'text','text'=>'João']]]]
     */
    public function sendTemplateMessage(string $to, string $templateName, string $languageCode = 'pt_BR', array $components = []): array
    {
        $template = [
            'name'     => $templateName,
            'language' => ['code' => $languageCode],
        ];
        if (!empty($components)) {
            $template['components'] = $components;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $this->normalizePhone($to),
            'type'              => 'template',
            'template'          => $template,
        ];

        return $this->request($payload);
    }

    /** Normaliza telefone brasileiro para o formato E.164 sem "+" exigido pela API (ex: 5511987654321). */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        // Já tem código do país (55) com DDD + número
        if (strlen($digits) >= 12 && strpos($digits, '55') === 0) {
            return $digits;
        }

        // DDD + número (10 ou 11 dígitos) sem código do país
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '55' . $digits;
        }

        return $digits;
    }

    /**
     * @return array{success:bool, message:string, raw:?array}
     */
    private function request(array $payload): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'WhatsApp Cloud API não configurada. Preencha o Token e o Phone Number ID em Configurações.',
                'raw'     => null,
            ];
        }

        if (!function_exists('curl_init')) {
            return [
                'success' => false,
                'message' => 'A extensão cURL do PHP não está disponível neste servidor.',
                'raw'     => null,
            ];
        }

        $url = self::BASE_URL . '/' . self::API_VERSION . '/' . $this->phoneNumberId . '/messages';

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->token,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);

            $response = curl_exec($ch);
            $errorNumber = curl_errno($ch);
            $errorMessage = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errorNumber !== 0) {
                error_log('WhatsappClient: erro cURL - ' . $errorMessage);
                return [
                    'success' => false,
                    'message' => 'Falha de comunicação com a WhatsApp Cloud API: ' . $errorMessage,
                    'raw'     => null,
                ];
            }

            $decoded = json_decode((string) $response, true);

            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'message' => 'Mensagem enviada com sucesso.',
                    'raw'     => is_array($decoded) ? $decoded : null,
                ];
            }

            $apiError = $decoded['error']['message'] ?? ('Erro HTTP ' . $httpCode);
            return [
                'success' => false,
                'message' => 'WhatsApp Cloud API retornou erro: ' . $apiError,
                'raw'     => is_array($decoded) ? $decoded : null,
            ];
        } catch (Throwable $e) {
            error_log('WhatsappClient: exceção - ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Erro inesperado ao enviar mensagem via WhatsApp.',
                'raw'     => null,
            ];
        }
    }
}
