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
        if (!is_string($bytes) || $bytes === '') throw new RuntimeException('AUDIO_UNAVAILABLE');
        $curl = curl_init(rtrim($this->baseUrl, '/') . '/chat/completions');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(self::buildPayload($this->model, $bytes, $mime, $call), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (in_array($status,[401,403],true)) throw new RuntimeException('AI_NOT_CONFIGURED');
        if ($status===429) throw new RuntimeException('AI_RATE_LIMIT');
        if ($status===413) throw new RuntimeException('AUDIO_TOO_LARGE');
        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException($error !== '' ? 'AI_ANALYSIS_NETWORK_FAILED' : 'AI_ANALYSIS_FAILED');
        }
        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $content = $response['choices'][0]['message']['content'] ?? '';
        $decoded = is_array($content) ? $content : json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('OPENROUTER_INVALID_RESPONSE');
        return (new AnalysisNormalizer())->normalize($decoded, 'openrouter', $this->model);
    }

    public static function prompt(array $call): string
    {
        return implode("\n", [
            'Atue como supervisor comercial e coach de vendas da Titanium Consultoria.',
            'A empresa trabalha com vendas e intermediação de cartas contempladas e soluções de crédito.',
            'Transcreva integralmente esta ligação em português do Brasil e produza uma análise comercial profunda.',
            'Baseie-se somente no áudio e no contexto fornecido. Não invente valores, condições, documentos, prazos, intenções ou falas.',
            'Identifique necessidades, momento de compra, sinais de interesse, objeções explícitas e implícitas e a etapa real do funil.',
            'Avalie abertura, descoberta, argumentação, proposta de valor, quebra de objeções, fechamento e planejamento de follow-up.',
            'Nas melhorias, explique o impacto, a ação prática e escreva uma frase que o vendedor poderia ter utilizado.',
            'Para cada objeção, registre a evidência observada, a estratégia recomendada e uma resposta natural e consultiva.',
            'Crie scripts personalizados para a próxima conversa, perguntas de diagnóstico e uma mensagem pronta de follow-up.',
            'Use nota de 0 a 10; use 0 apenas quando uma competência não foi observada ou não foi aplicável.',
            'A Titanium deve ser tratada como consultoria/intermediadora, sem presumir que seja a administradora do consórcio.',
            'Não dê parecer jurídico e não crie alertas genéricos sem relação com a conversa.',
            'Contexto:',
            json_encode([
                'origem' => $call['from'] ?? '',
                'destino' => $call['to'] ?? '',
                'duracao_segundos' => $call['duration'] ?? 0,
                'data_inicio' => $call['started_at'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
    }

    public static function schema(): array
    {
        $stringArray = ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 30];
        $stringObject = static function (array $fields): array {
            $properties = [];
            foreach ($fields as $field) $properties[$field] = ['type' => 'string'];
            return ['type' => 'object', 'properties' => $properties, 'required' => $fields, 'additionalProperties' => false];
        };
        $objectList = static function (array $fields) use ($stringObject): array {
            return ['type' => 'array', 'items' => $stringObject($fields), 'maxItems' => 5];
        };
        $scoreFields = ['overall','opening','discovery','argumentation','value_proposition','objection_handling','closing','follow_up'];
        $scoreProperties = [];
        foreach ($scoreFields as $field) $scoreProperties[$field] = ['type' => 'integer', 'minimum' => 0, 'maximum' => 10];
        $properties = [
            'transcript' => ['type' => 'string'], 'summary' => ['type' => 'string'],
            'sentiment' => ['type' => 'string', 'enum' => ['positivo','neutro','negativo','misto']],
            'lead_temperature' => ['type' => 'string', 'enum' => ['frio','morno','quente','indefinido']],
            'sales_stage' => ['type' => 'string', 'enum' => ['contato_inicial','qualificacao','proposta','follow_up','fechamento','sem_avanco']],
            'topics' => $stringArray, 'key_points' => $stringArray, 'customer_needs' => $stringArray,
            'buying_signals' => $stringArray, 'strengths' => $stringArray,
            'improvements' => $objectList(['point','why_it_matters','recommended_action','example_phrase']),
            'scores' => ['type' => 'object', 'properties' => $scoreProperties, 'required' => $scoreFields, 'additionalProperties' => false],
            'objections' => $objectList(['objection','evidence','response_strategy','suggested_response']),
            'recommended_approach' => ['type' => 'string'], 'discovery_questions' => $stringArray,
            'sales_script' => $stringObject(['opening','value_pitch','objection_handling','closing']),
            'follow_up_plan' => $stringObject(['timing','channel','objective','message']),
            'next_steps' => $stringArray, 'risks' => $stringArray, 'compliance_alerts' => $stringArray,
        ];
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }
}
