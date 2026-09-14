<?php

declare(strict_types=1);

namespace App;

final class Api4ComWebphoneClient implements WebphoneGateway
{
    private string $baseUrl;
    private string $token;

    public function __construct(private HttpClientInterface $http, Config $config)
    {
        $this->baseUrl = rtrim($config->get('API4COM_BASE_URL', 'https://api.api4com.com/api/v1') ?: '', '/');
        $this->token = $config->required('API4COM_TOKEN');
    }

    public function findExtension(string $extension): ?array
    {
        $filter = json_encode([
            'where' => ['ramal' => $extension],
            'limit' => 1,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $query = http_build_query(['filter' => $filter], '', '&', PHP_QUERY_RFC3986);
        $payload = $this->requestJson('GET', $this->baseUrl . '/extensions?' . $query);

        if ($payload === []) {
            return null;
        }

        $extensionData = array_values($payload)[0] ?? null;
        if (!is_array($extensionData)) {
            throw new AppException('Resposta inválida da Api4Com.', 'API4COM_INVALID_RESPONSE', 502);
        }

        return $extensionData;
    }

    public function testConnection(string $extension): array
    {
        $row = $this->findExtension($extension);
        if ($row === null || (string) ($row['ramal'] ?? '') !== $extension) {
            throw new AppException('Ramal configurado não encontrado nesta conta.', 'WEBPHONE_EXTENSION_NOT_FOUND', 404);
        }
        return ['connected' => true, 'extension' => $extension,
            'message' => 'Credenciais aceitas e ramal encontrado.', 'checked_at' => gmdate('c')];
    }

    /** Only public extension labels cross this boundary; SIP credentials stay private. */
    public function listExtensions(): array
    {
        $query = http_build_query(['filter' => json_encode(['limit' => 100], JSON_THROW_ON_ERROR)], '', '&', PHP_QUERY_RFC3986);
        $payload = $this->requestJson('GET', $this->baseUrl . '/extensions?' . $query);
        if ($payload !== [] && array_keys($payload) !== range(0, count($payload) - 1)) {
            throw new AppException('Resposta inválida da Api4Com.', 'API4COM_INVALID_RESPONSE', 502);
        }
        $result = [];
        foreach (array_slice($payload, 0, 100) as $row) {
            if (!is_array($row)) { continue; }
            $number = is_scalar($row['ramal'] ?? null) ? (string) $row['ramal'] : '';
            if (!preg_match('/^[0-9]{1,10}$/D', $number)) { continue; }
            $name = $row['nome'] ?? $row['name'] ?? '';
            $name = is_string($name) ? mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '', 0, 120) : '';
            $result[] = ['number' => $number, 'name' => $name];
        }
        return $result;
    }

    public function dial(string $extension, string $phone, array $metadata = []): array
    {
        return $this->requestJson(
            'POST',
            $this->baseUrl . '/dialer',
            json_encode([
                'extension' => $extension,
                'phone' => $phone,
                'metadata' => $metadata,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    public function hangup(string $callId): array
    {
        return $this->requestJson(
            'POST',
            $this->baseUrl . '/calls/' . rawurlencode($callId) . '/hangup',
            null,
            true
        );
    }

    /** @return array<string|int, mixed> */
    private function requestJson(
        string $method,
        string $url,
        ?string $body = null,
        bool $preserveNotFound = false
    ): array {
        $response = $this->http->request($method, $url, [
            'Authorization' => $this->token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $body);

        if (in_array($response->status(), [401, 403], true)) {
            throw new AppException('Token da Api4Com rejeitado.', 'API4COM_AUTH_FAILED', 502);
        }
        if ($response->status() === 429) {
            throw new AppException('Limite de requisições da Api4Com atingido.', 'API4COM_RATE_LIMIT', 503);
        }
        if ($preserveNotFound && $response->status() === 404) {
            throw new AppException('Ligação não encontrada ou já encerrada.', 'CALL_NOT_FOUND', 404);
        }
        if (!$response->isSuccessful()) {
            throw new AppException('Falha no provedor de telefonia.', 'WEBPHONE_PROVIDER_FAILED', 502);
        }

        try {
            return $response->json();
        } catch (AppException $error) {
            throw new AppException('Resposta inválida da Api4Com.', 'API4COM_INVALID_RESPONSE', 502, $error);
        }
    }
}
