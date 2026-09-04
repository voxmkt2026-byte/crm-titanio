<?php

declare(strict_types=1);

namespace App;

final class GeminiClient implements AiAnalyzer
{
    private const ANALYSIS_VERSION = 2;
    private const MAX_STRUCTURED_ITEMS = 5;

    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private int $maxInlineAudioBytes;

    public function __construct(private HttpClientInterface $http, Config $config)
    {
        $this->baseUrl = rtrim(
            $config->get('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta') ?: '',
            '/'
        );
        $this->apiKey = trim((string) $config->get('GEMINI_API_KEY', ''));
        $this->model = trim((string) $config->get('GEMINI_MODEL', 'gemini-3.5-flash-lite'));
        $this->maxInlineAudioBytes = $config->int(
            'GEMINI_MAX_INLINE_AUDIO_BYTES',
            14680064,
            1,
            15728640
        );
    }

    public function analyze(DownloadedFile $audio, array $call): array
    {
        $this->assertConfigured();
        if ($audio->size() > $this->maxInlineAudioBytes) {
            throw new AppException(
                'O áudio excede o limite da requisição inline do Gemini.',
                'AUDIO_TOO_LARGE',
                413
            );
        }

        $bytes = file_get_contents($audio->path());
        if ($bytes === false || $bytes === '') {
            throw new AppException('Não foi possível ler o áudio.', 'AI_TRANSCRIPTION_FAILED', 502);
        }

        try {
            $response = $this->http->request(
                'POST',
                $this->baseUrl . '/interactions',
                [
                    'x-goog-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                json_encode(
                    $this->requestBody($audio, $bytes, $call),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
        } catch (AppException $error) {
            if ($error->publicCode() === 'HTTP_REQUEST_FAILED') {
                throw new AppException(
                    'Falha de rede durante a análise do Gemini.',
                    'AI_ANALYSIS_FAILED',
                    502,
                    $error
                );
            }
            throw $error;
        }

        $this->assertGeminiResponse($response);

        try {
            $payload = $response->json();
            if (($payload['status'] ?? '') !== 'completed') {
                throw new \RuntimeException('Interação do Gemini não foi concluída.');
            }
            $decoded = json_decode($this->extractOutputText($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new AppException('Resposta estruturada do Gemini inválida.', 'AI_ANALYSIS_FAILED', 502, $error);
        }

        if (!is_array($decoded)) {
            throw new AppException('Análise do Gemini não é um objeto JSON.', 'AI_ANALYSIS_FAILED', 502);
        }

        return $this->normalizeAnalysis($decoded);
    }

    private function assertConfigured(): void
    {
        if ($this->apiKey === '' || $this->baseUrl === '' || $this->model === '') {
            throw new AppException('Configuração do Gemini incompleta.', 'AI_NOT_CONFIGURED', 503);
        }
    }

    /** @param array<string, mixed> $call @return array<string, mixed> */
    private function requestBody(DownloadedFile $audio, string $bytes, array $call): array
    {
        $context = [
            'origem' => (string) ($call['from'] ?? ''),
            'destino' => (string) ($call['to'] ?? ''),
            'duracao_segundos' => max(0, (int) ($call['duration'] ?? 0)),
            'data_inicio' => (string) ($call['started_at'] ?? ''),
        ];

        $prompt = implode("\n", [
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
            'Em alertas de conformidade, sinalize somente quando houver evidência de promessa sem respaldo, omissão de custos ou condições, pressão indevida, garantia de contemplação futura, aprovação, transferência ou liberação sem validação.',
            'Considere que custos, condições contratuais, documentação, garantias e eventual avaliação da administradora devem ser explicados com transparência quando relevantes.',
            'Não confunda uma carta comprovadamente já contemplada com promessa de contemplação futura.',
            'Não dê parecer jurídico e não crie alertas genéricos sem relação com a conversa.',
            'Contexto:',
            json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        return [
            'model' => $this->model,
            'input' => [
                ['type' => 'text', 'text' => $prompt],
                [
                    'type' => 'audio',
                    'data' => base64_encode($bytes),
                    'mime_type' => $this->normalizeMimeType($audio->mimeType()),
                ],
            ],
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $this->analysisSchema(),
            ],
            'store' => false,
        ];
    }

    private function assertGeminiResponse(HttpResponse $response): void
    {
        $body = strtolower($response->body());
        if ($response->status() === 429
            || ($response->status() >= 500 && str_contains($body, 'high demand'))) {
            throw new AppException('Limite gratuito do Gemini atingido.', 'AI_RATE_LIMIT', 503);
        }
        if ($response->status() === 413) {
            throw new AppException('Áudio grande demais para o Gemini.', 'AUDIO_TOO_LARGE', 413);
        }

        $invalidKey = in_array($response->status(), [401, 403], true)
            || ($response->status() === 400 && (
                str_contains($body, 'api_key_invalid')
                || str_contains($body, 'api key not valid')
            ));
        if ($invalidKey) {
            throw new AppException('Chave do Gemini rejeitada.', 'AI_NOT_CONFIGURED', 503);
        }
        if (!$response->isSuccessful()) {
            throw new AppException(
                'Gemini respondeu com status ' . $response->status() . '.',
                'AI_ANALYSIS_FAILED',
                502
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function extractOutputText(array $payload): string
    {
        $steps = isset($payload['steps']) && is_array($payload['steps']) ? array_reverse($payload['steps']) : [];
        foreach ($steps as $step) {
            if (!is_array($step) || ($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            $contentItems = isset($step['content']) && is_array($step['content'])
                ? array_reverse($step['content'])
                : [];
            foreach ($contentItems as $content) {
                if (is_array($content)
                    && ($content['type'] ?? '') === 'text'
                    && is_string($content['text'] ?? null)
                    && trim($content['text']) !== '') {
                    return trim($content['text']);
                }
            }
        }

        throw new AppException('Resposta do Gemini sem texto.', 'AI_ANALYSIS_FAILED', 502);
    }

    /** @param array<string, mixed> $analysis @return array<string, mixed> */
    private function normalizeAnalysis(array $analysis): array
    {
        $transcript = isset($analysis['transcript']) && is_string($analysis['transcript'])
            ? trim($analysis['transcript'])
            : '';
        if ($transcript === '') {
            throw new AppException('Transcrição vazia.', 'AI_TRANSCRIPTION_FAILED', 502);
        }

        $summary = isset($analysis['summary']) && is_string($analysis['summary'])
            ? trim($analysis['summary'])
            : '';
        if ($summary === '') {
            throw new AppException('Análise sem resumo.', 'AI_ANALYSIS_FAILED', 502);
        }

        $sentiment = isset($analysis['sentiment']) && is_string($analysis['sentiment'])
            ? strtolower(trim($analysis['sentiment']))
            : 'misto';
        if (!in_array($sentiment, ['positivo', 'neutro', 'negativo', 'misto'], true)) {
            $sentiment = 'misto';
        }

        return [
            'analysis_version' => self::ANALYSIS_VERSION,
            'summary' => $summary,
            'sentiment' => $sentiment,
            'lead_temperature' => $this->enumValue(
                $analysis['lead_temperature'] ?? null,
                ['frio', 'morno', 'quente', 'indefinido'],
                'indefinido'
            ),
            'sales_stage' => $this->enumValue(
                $analysis['sales_stage'] ?? null,
                ['contato_inicial', 'qualificacao', 'proposta', 'follow_up', 'fechamento', 'sem_avanco'],
                'sem_avanco'
            ),
            'topics' => $this->stringList($analysis['topics'] ?? null),
            'key_points' => $this->stringList($analysis['key_points'] ?? null),
            'customer_needs' => $this->stringList($analysis['customer_needs'] ?? null),
            'buying_signals' => $this->stringList($analysis['buying_signals'] ?? null),
            'strengths' => $this->stringList($analysis['strengths'] ?? null),
            'improvements' => $this->objectList(
                $analysis['improvements'] ?? null,
                ['point', 'why_it_matters', 'recommended_action', 'example_phrase']
            ),
            'scores' => $this->normalizeScores($analysis['scores'] ?? null),
            'objections' => $this->objectList(
                $analysis['objections'] ?? null,
                ['objection', 'evidence', 'response_strategy', 'suggested_response']
            ),
            'recommended_approach' => $this->requiredString(
                $analysis['recommended_approach'] ?? null,
                'Abordagem recomendada ausente.'
            ),
            'discovery_questions' => $this->stringList($analysis['discovery_questions'] ?? null),
            'sales_script' => $this->stringObject(
                $analysis['sales_script'] ?? null,
                ['opening', 'value_pitch', 'objection_handling', 'closing']
            ),
            'follow_up_plan' => $this->stringObject(
                $analysis['follow_up_plan'] ?? null,
                ['timing', 'channel', 'objective', 'message']
            ),
            'next_steps' => $this->stringList($analysis['next_steps'] ?? null),
            'risks' => $this->stringList($analysis['risks'] ?? null),
            'compliance_alerts' => $this->stringList($analysis['compliance_alerts'] ?? null),
            'transcript' => $transcript,
            'provider_model' => $this->model,
            'analyzed_at' => gmdate('c'),
        ];
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            throw new AppException('Lista inválida na análise.', 'AI_ANALYSIS_FAILED', 502);
        }

        $items = [];
        foreach (array_slice($value, 0, 30) as $item) {
            if (!is_string($item)) {
                throw new AppException('Item inválido na análise.', 'AI_ANALYSIS_FAILED', 502);
            }
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }
        return $items;
    }

    /** @param array<int, string> $allowed */
    private function enumValue(mixed $value, array $allowed, string $default): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function requiredString(mixed $value, string $errorMessage): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            throw new AppException($errorMessage, 'AI_ANALYSIS_FAILED', 502);
        }
        return $value;
    }

    /** @param array<int, string> $fields @return array<string, string> */
    private function stringObject(mixed $value, array $fields): array
    {
        if (!is_array($value)) {
            throw new AppException('Objeto inválido na análise.', 'AI_ANALYSIS_FAILED', 502);
        }

        $normalized = [];
        foreach ($fields as $field) {
            $normalized[$field] = $this->requiredString(
                $value[$field] ?? null,
                'Campo obrigatório ausente na análise.'
            );
        }
        return $normalized;
    }

    /** @param array<int, string> $fields @return array<int, array<string, string>> */
    private function objectList(mixed $value, array $fields): array
    {
        if (!is_array($value)) {
            throw new AppException('Lista estruturada inválida na análise.', 'AI_ANALYSIS_FAILED', 502);
        }

        $items = [];
        foreach (array_slice($value, 0, self::MAX_STRUCTURED_ITEMS) as $item) {
            $items[] = $this->stringObject($item, $fields);
        }
        return $items;
    }

    /** @return array<string, int> */
    private function normalizeScores(mixed $value): array
    {
        if (!is_array($value)) {
            throw new AppException('Pontuações inválidas na análise.', 'AI_ANALYSIS_FAILED', 502);
        }

        $fields = [
            'overall',
            'opening',
            'discovery',
            'argumentation',
            'value_proposition',
            'objection_handling',
            'closing',
            'follow_up',
        ];
        $scores = [];
        foreach ($fields as $field) {
            if (!is_numeric($value[$field] ?? null)) {
                throw new AppException('Pontuação ausente na análise.', 'AI_ANALYSIS_FAILED', 502);
            }
            $scores[$field] = max(0, min(10, (int) round((float) $value[$field])));
        }
        return $scores;
    }

    /** @return array<string, mixed> */
    private function analysisSchema(): array
    {
        $stringList = [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'maxItems' => 30,
        ];

        $structuredList = static function (array $properties, array $required): array {
            return [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                    'additionalProperties' => false,
                ],
                'maxItems' => self::MAX_STRUCTURED_ITEMS,
            ];
        };
        $stringObject = static function (array $fields): array {
            $properties = [];
            foreach ($fields as $field) {
                $properties[$field] = ['type' => 'string'];
            }
            return [
                'type' => 'object',
                'properties' => $properties,
                'required' => $fields,
                'additionalProperties' => false,
            ];
        };
        $scoreFields = [
            'overall',
            'opening',
            'discovery',
            'argumentation',
            'value_proposition',
            'objection_handling',
            'closing',
            'follow_up',
        ];
        $scoreProperties = [];
        foreach ($scoreFields as $field) {
            $scoreProperties[$field] = ['type' => 'integer', 'minimum' => 0, 'maximum' => 10];
        }

        return [
            'type' => 'object',
            'properties' => [
                'transcript' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'sentiment' => [
                    'type' => 'string',
                    'enum' => ['positivo', 'neutro', 'negativo', 'misto'],
                ],
                'lead_temperature' => [
                    'type' => 'string',
                    'enum' => ['frio', 'morno', 'quente', 'indefinido'],
                ],
                'sales_stage' => [
                    'type' => 'string',
                    'enum' => ['contato_inicial', 'qualificacao', 'proposta', 'follow_up', 'fechamento', 'sem_avanco'],
                ],
                'topics' => $stringList,
                'key_points' => $stringList,
                'customer_needs' => $stringList,
                'buying_signals' => $stringList,
                'strengths' => $stringList,
                'improvements' => $structuredList([
                    'point' => ['type' => 'string'],
                    'why_it_matters' => ['type' => 'string'],
                    'recommended_action' => ['type' => 'string'],
                    'example_phrase' => ['type' => 'string'],
                ], ['point', 'why_it_matters', 'recommended_action', 'example_phrase']),
                'scores' => [
                    'type' => 'object',
                    'properties' => $scoreProperties,
                    'required' => $scoreFields,
                    'additionalProperties' => false,
                ],
                'objections' => $structuredList([
                    'objection' => ['type' => 'string'],
                    'evidence' => ['type' => 'string'],
                    'response_strategy' => ['type' => 'string'],
                    'suggested_response' => ['type' => 'string'],
                ], ['objection', 'evidence', 'response_strategy', 'suggested_response']),
                'recommended_approach' => ['type' => 'string'],
                'discovery_questions' => $stringList,
                'sales_script' => $stringObject(['opening', 'value_pitch', 'objection_handling', 'closing']),
                'follow_up_plan' => $stringObject(['timing', 'channel', 'objective', 'message']),
                'next_steps' => $stringList,
                'risks' => $stringList,
                'compliance_alerts' => $stringList,
            ],
            'required' => [
                'transcript',
                'summary',
                'sentiment',
                'lead_temperature',
                'sales_stage',
                'topics',
                'key_points',
                'customer_needs',
                'buying_signals',
                'strengths',
                'improvements',
                'scores',
                'objections',
                'recommended_approach',
                'discovery_questions',
                'sales_script',
                'follow_up_plan',
                'next_steps',
                'risks',
                'compliance_alerts',
            ],
            'additionalProperties' => false,
        ];
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $mimeType = strtolower(trim($mimeType));
        if ($mimeType === 'audio/mp3') {
            return 'audio/mpeg';
        }
        if ($mimeType === 'video/webm') {
            return 'audio/webm';
        }
        return str_starts_with($mimeType, 'audio/') ? $mimeType : 'audio/mpeg';
    }
}
