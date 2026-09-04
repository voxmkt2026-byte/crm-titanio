<?php

declare(strict_types=1);

require_once __DIR__ . '/AnalysisNormalizer.php';
require_once __DIR__ . '/OpenRouterAnalyzer.php';

final class GeminiCallAnalyzer
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        string $model,
        private int $timeout = 120,
        private int $maxAudioBytes = 14680064
    ) {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->apiKey = trim($apiKey);
        $this->model = trim($model) !== '' ? trim($model) : 'gemini-3.5-flash-lite';
    }

    public static function buildPayload(string $model, string $bytes, string $mime, array $call): array
    {
        $mime = strtolower(trim($mime));
        if ($mime === 'audio/mp3') $mime = 'audio/mpeg';
        if ($mime === 'video/webm') $mime = 'audio/webm';
        if (!str_starts_with($mime, 'audio/')) $mime = 'audio/mpeg';

        return [
            'model' => trim($model) !== '' ? trim($model) : 'gemini-3.5-flash-lite',
            'input' => [
                ['type' => 'text', 'text' => OpenRouterAnalyzer::prompt($call)],
                ['type' => 'audio', 'data' => base64_encode($bytes), 'mime_type' => $mime],
            ],
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => OpenRouterAnalyzer::schema(),
            ],
            'store' => false,
        ];
    }

    public function analyze(string $path, string $mime, array $call): array
    {
        if ($this->baseUrl === '' || $this->apiKey === '' || $this->model === '') {
            throw new RuntimeException('AI_NOT_CONFIGURED');
        }
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size <= 0) throw new RuntimeException('AUDIO_UNAVAILABLE');
        if ($size > $this->maxAudioBytes) throw new RuntimeException('AUDIO_TOO_LARGE');
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') throw new RuntimeException('AUDIO_UNAVAILABLE');

        $curl = curl_init($this->baseUrl . '/interactions');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'x-goog-api-key: ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(
                self::buildPayload($this->model, $bytes, $mime, $call),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
        ]);
        $raw = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $networkError = curl_error($curl);
        curl_close($curl);
        $body = is_string($raw) ? strtolower($raw) : '';
        if ($code === 429 || ($code >= 500 && str_contains($body, 'high demand'))) {
            throw new RuntimeException('AI_RATE_LIMIT');
        }
        if ($code === 413) throw new RuntimeException('AUDIO_TOO_LARGE');
        if (in_array($code, [401, 403], true)
            || ($code === 400 && (str_contains($body, 'api_key_invalid') || str_contains($body, 'api key not valid')))) {
            throw new RuntimeException('AI_NOT_CONFIGURED');
        }
        if (!is_string($raw) || $code < 200 || $code >= 300) {
            throw new RuntimeException($networkError !== '' ? 'AI_ANALYSIS_NETWORK_FAILED' : 'AI_ANALYSIS_FAILED');
        }

        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (($data['status'] ?? '') !== 'completed') throw new RuntimeException('AI_ANALYSIS_FAILED');
        $text = '';
        foreach (array_reverse(is_array($data['steps'] ?? null) ? $data['steps'] : []) as $step) {
            if (!is_array($step) || ($step['type'] ?? '') !== 'model_output') continue;
            foreach (array_reverse(is_array($step['content'] ?? null) ? $step['content'] : []) as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'text' && trim((string) ($item['text'] ?? '')) !== '') {
                    $text = trim((string) $item['text']);
                    break 2;
                }
            }
        }
        if ($text === '') throw new RuntimeException('AI_ANALYSIS_FAILED');
        $analysis = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($analysis)) throw new RuntimeException('AI_ANALYSIS_FAILED');
        return (new AnalysisNormalizer())->normalize($analysis, 'gemini', $this->model);
    }
}
