<?php

declare(strict_types=1);

namespace App;

final class Api4ComClient implements CallsGateway
{
    private string $baseUrl;
    private string $token;
    private int $perPage;

    public function __construct(private HttpClientInterface $http, Config $config)
    {
        $this->baseUrl = rtrim($config->get('API4COM_BASE_URL', 'https://api.api4com.com/api/v1') ?: '', '/');
        $this->token = $config->required('API4COM_TOKEN');
        $this->perPage = $config->int('CALLS_PER_PAGE', 20, 1, 100);
    }

    public function listCalls(int $page, ?string $number): array
    {
        $page = max(1, $page);
        $filter = [
            'order' => 'started_at DESC',
            'limit' => $this->perPage,
        ];

        $number = $this->sanitizeNumber($number);
        if ($number !== null) {
            $like = '%' . $number . '%';
            $filter['where'] = [
                'or' => [
                    ['from' => ['like' => $like]],
                    ['to' => ['like' => $like]],
                    ['BINA' => ['like' => $like]],
                ],
            ];
        }

        $payload = $this->getCalls($page, $filter);
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];
        $meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];

        return [
            'data' => array_values(array_map(fn (array $call): array => $this->normalizeCall($call, false), $data)),
            'meta' => $this->normalizeMeta($meta, $page, count($data)),
        ];
    }

    public function findCall(string $id): ?array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $id)) {
            throw new AppException('Identificador de ligação inválido.', 'INVALID_CALL_ID', 400);
        }

        $payload = $this->getCalls(1, [
            'where' => ['id' => $id],
            'limit' => 1,
        ]);
        $data = isset($payload['data']) && is_array($payload['data']) ? array_values($payload['data']) : [];
        if ($data === [] || !is_array($data[0])) {
            return null;
        }

        return $this->normalizeCall($data[0], true);
    }

    /** @param array<string, mixed> $filter @return array<string, mixed> */
    private function getCalls(int $page, array $filter): array
    {
        $query = http_build_query([
            'page' => $page,
            'filter' => json_encode($filter, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->http->request('GET', $this->baseUrl . '/calls?' . $query, [
            'Authorization' => $this->token,
            'Accept' => 'application/json',
        ]);

        if (in_array($response->status(), [401, 403], true)) {
            throw new AppException('Token da Api4Com rejeitado.', 'API4COM_AUTH_FAILED', 502);
        }
        if ($response->status() === 429) {
            throw new AppException('Limite de requisições da Api4Com atingido.', 'API4COM_RATE_LIMIT', 503);
        }
        if (!$response->isSuccessful()) {
            throw new AppException('Api4Com respondeu com status ' . $response->status() . '.', 'API4COM_UNAVAILABLE', 502);
        }

        try {
            $payload = $response->json();
        } catch (AppException $error) {
            throw new AppException('Resposta inválida da Api4Com.', 'API4COM_INVALID_RESPONSE', 502, $error);
        }

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            throw new AppException('Resposta sem lista de chamadas.', 'API4COM_INVALID_RESPONSE', 502);
        }

        return $payload;
    }

    private function sanitizeNumber(?string $number): ?string
    {
        if ($number === null || trim($number) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $number) ?: '';
        if ($digits === '') {
            throw new AppException('Informe um número válido.', 'INVALID_PHONE_FILTER', 400);
        }
        return substr($digits, 0, 20);
    }

    /** @param array<string, mixed> $call @return array<string, mixed> */
    private function normalizeCall(array $call, bool $includeRecordingUrl): array
    {
        $recordUrl = isset($call['record_url']) && is_string($call['record_url'])
            ? trim($call['record_url'])
            : '';
        $normalized = [
            'id' => (string) ($call['id'] ?? ''),
            'call_type' => (string) ($call['call_type'] ?? ''),
            'started_at' => (string) ($call['started_at'] ?? ''),
            'ended_at' => (string) ($call['ended_at'] ?? ''),
            'from' => (string) ($call['from'] ?? ''),
            'to' => (string) ($call['to'] ?? ''),
            'duration' => max(0, (int) ($call['duration'] ?? 0)),
            'hangup_cause' => (string) ($call['hangup_cause'] ?? ''),
            'email' => (string) ($call['email'] ?? ''),
            'first_name' => (string) ($call['first_name'] ?? ''),
            'last_name' => (string) ($call['last_name'] ?? ''),
            'bina' => (string) ($call['BINA'] ?? $call['bina'] ?? ''),
            'minute_price' => is_numeric($call['minute_price'] ?? null) ? (float) $call['minute_price'] : null,
            'call_price' => is_numeric($call['call_price'] ?? null) ? (float) $call['call_price'] : null,
            'metadata' => isset($call['metadata']) && is_array($call['metadata']) ? $call['metadata'] : [],
            'has_recording' => $recordUrl !== '',
        ];

        if ($includeRecordingUrl) {
            $normalized['record_url'] = $recordUrl;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $meta @return array<string, int|null> */
    private function normalizeMeta(array $meta, int $page, int $count): array
    {
        return [
            'totalItemCount' => max(0, (int) ($meta['totalItemCount'] ?? $count)),
            'totalPageCount' => max(0, (int) ($meta['totalPageCount'] ?? ($count > 0 ? 1 : 0))),
            'itemsPerPage' => max(1, (int) ($meta['itemsPerPage'] ?? $this->perPage)),
            'currentPage' => max(1, (int) ($meta['currentPage'] ?? $page)),
            'nextPage' => isset($meta['nextPage']) ? (int) $meta['nextPage'] : null,
            'previousPage' => isset($meta['previousPage']) ? (int) $meta['previousPage'] : null,
        ];
    }
}

