<?php

declare(strict_types=1);

namespace App;

final class WebphoneService
{
    public function __construct(
        private WebphoneGateway $gateway,
        private string $extension
    ) {
    }

    public function sipConfig(): array
    {
        $extension = $this->gateway->findExtension($this->extension);
        if ($extension === null) {
            throw new AppException('Ramal do webphone não encontrado.', 'WEBPHONE_EXTENSION_NOT_FOUND', 503);
        }

        $number = trim((string) ($extension['ramal'] ?? ''));
        $domain = trim((string) ($extension['domain'] ?? ''));
        $password = (string) ($extension['senha'] ?? '');
        if ($number === '' || $domain === '' || $password === '') {
            throw new AppException('Configuração incompleta do webphone.', 'WEBPHONE_CONFIG_FAILED', 502);
        }

        return [
            'extension' => $number,
            'domain' => $domain,
            'password' => $password,
            'websocket_url' => 'wss://' . $domain . ':6443',
            'recording_enabled' => (bool) ($extension['gravar_audio'] ?? false),
        ];
    }

    public function dial(string $phone): array
    {
        $phone = trim($phone);
        $hasLeadingPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $length = strlen($digits);
        if ($length < 8 || $length > 15) {
            throw new AppException('Informe um telefone válido com DDD.', 'INVALID_PHONE', 422);
        }

        $normalizedPhone = ($hasLeadingPlus ? '+' : '') . $digits;
        $response = $this->gateway->dial(
            $this->extension,
            $normalizedPhone,
            ['gateway' => 'vox-insights']
        );
        $response['phone'] = $normalizedPhone;

        return $response;
    }

    public function hangup(string $callId): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $callId)) {
            throw new AppException('Identificador de ligação inválido.', 'INVALID_CALL_ID', 400);
        }

        return $this->gateway->hangup($callId);
    }
}
