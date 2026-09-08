<?php
declare(strict_types=1);
require_once __DIR__ . '/CallFailure.php';

final class CallsApiClient
{
    public function __construct(private IntegrationConfig $config, private ?Closure $transport = null) {}

    public function page(int $page, array $where = []): array
    {
        $base = rtrim(trim((string) $this->config->get('api4com', 'base_url', 'https://api.api4com.com/api/v1')), '/');
        $parts = parse_url($base);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('API4COM_INVALID_URL');
        }
        $token = trim((string) $this->config->get('api4com', 'token', ''));
        if ($token === '' || strpbrk($token, "\r\n") !== false) throw new RuntimeException('API4COM_NOT_CONFIGURED');
        $filter = ['order' => 'started_at DESC', 'limit' => 20];
        if ($where) $filter['where'] = $where;
        $url = $base . '/calls?' . http_build_query(['page'=>max(1,$page),'filter'=>json_encode($filter, JSON_THROW_ON_ERROR)], '', '&', PHP_QUERY_RFC3986);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $this->transport ? ($this->transport)($url, $token) : $this->request($url, $token);
            $status = (int) ($response['status'] ?? 0);
            $errno = (int) ($response['errno'] ?? 0);
            $transient = in_array($status, [502,503,504], true) || in_array($errno, [7,28,52,56], true);
            if (!$transient || $attempt > 0) break;
        }
        if ($errno) {
            throw new RuntimeException(match ($errno) {
                6 => 'API4COM_DNS_FAILED', 28 => 'API4COM_TIMEOUT', 35,51,58,60,77 => 'API4COM_TLS_FAILED', default => 'API4COM_NETWORK_FAILED',
            });
        }
        if (in_array($status, [401,403], true)) throw new RuntimeException('API4COM_AUTH_FAILED');
        if ($status === 429) throw new RuntimeException('API4COM_RATE_LIMIT');
        if ($status < 200 || $status >= 300) throw new RuntimeException($status >= 500 ? 'API4COM_UNAVAILABLE' : 'API4COM_INVALID_RESPONSE');
        try { $payload = json_decode((string) ($response['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new RuntimeException('API4COM_INVALID_RESPONSE'); }
        if (!is_array($payload) || !is_array($payload['data'] ?? null)) throw new RuntimeException('API4COM_INVALID_RESPONSE');
        foreach ($payload['data'] as $row) if (!is_array($row)) throw new RuntimeException('API4COM_INVALID_RESPONSE');
        return array_values($payload['data']);
    }

    public function find(string $id): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $id)) throw new RuntimeException('CALL_NOT_FOUND');
        foreach ($this->page(1, ['id'=>$id]) as $call) {
            if ((string) ($call['id'] ?? '') === $id) return $call;
        }
        throw new RuntimeException('CALL_NOT_FOUND');
    }

    private function request(string $url, string $token): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Authorization: '.$token,'Accept: application/json'],CURLOPT_USERAGENT=>'TitaniumCRM/Calls']);
        $body = curl_exec($curl);
        $result = ['body'=>is_string($body)?$body:'','status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'errno'=>curl_errno($curl)];
        curl_close($curl);
        return $result;
    }
}
