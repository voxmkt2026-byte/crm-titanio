<?php

declare(strict_types=1);

require_once __DIR__ . '/AnalysisNormalizer.php';

final class OpenRouterAnalyzer
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private string $model,
        private int $timeout = 120
    ) {
    }

    public static function buildPayload(string $model, string $bytes, string $mime, array $call): array
    {
        $format = match (strtolower($mime)) {
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/flac' => 'flac',
            'audio/ogg' => 'ogg',
            'audio/mp4', 'audio/m4a' => 'm4a',
            'audio/webm' => 'webm',
            'audio/aac' => 'aac',
            default => 'mp3',
        };
        return [
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => self::prompt($call)],
                    ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode($bytes), 'format' => $format]],
                ],
            ]],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'call_analysis', 'strict' => true, 'schema' => self::schema()],
            ],
        ];
    }

    public function analyze(string $path, string $mime, array $call): array
    {
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') throw new RuntimeException('Áudio indisponível para análise.');
        $curl = curl_init(rtrim($this->baseUrl, '/') . '/chat/completions');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(self::buildPayload($this->model, $bytes, $mime, $call), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException('OPENROUTER_REQUEST_FAILED' . ($error !== '' ? ': ' . $error : ''));
        }
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $content = $response['choices'][0]['message']['content'] ?? '';
        $decoded = is_array($content) ? $content : json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('OPENROUTER_INVALID_RESPONSE');
        return (new AnalysisNormalizer())->normalize($decoded, 'openrouter', $this->model);
    }

    public static function prompt(array $call): string
    {
        return 'Atue como supervisor comercial da Titanium Consultoria. Transcreva integralmente a ligação em português do Brasil e produza análise comercial baseada somente no áudio. Classifique call_outcome; use voicemail para caixa postal. Retorne resumo, sentimento, temperatura, etapa, notas, necessidades, objeções, melhorias, roteiro, follow-up, riscos e alertas. Contexto: ' .
            json_encode(['origem' => $call['from'] ?? '', 'destino' => $call['to'] ?? '', 'duracao' => $call['duration'] ?? 0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function schema(): array
    {
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string']];
        $properties = [
            'transcript' => ['type' => 'string'], 'summary' => ['type' => 'string'],
            'sentiment' => ['type' => 'string'], 'lead_temperature' => ['type' => 'string'],
            'sales_stage' => ['type' => 'string'], 'call_outcome' => ['type' => 'string'],
            'topics' => $stringArray, 'key_points' => $stringArray, 'customer_needs' => $stringArray,
            'buying_signals' => $stringArray, 'strengths' => $stringArray,
            'improvements' => ['type' => 'array', 'items' => ['type' => 'object']],
            'scores' => ['type' => 'object'], 'objections' => ['type' => 'array', 'items' => ['type' => 'object']],
            'recommended_approach' => ['type' => 'string'], 'discovery_questions' => $stringArray,
            'sales_script' => ['type' => 'object'], 'follow_up_plan' => ['type' => 'object'],
            'next_steps' => $stringArray, 'risks' => $stringArray, 'compliance_alerts' => $stringArray,
        ];
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
