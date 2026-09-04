<?php

declare(strict_types=1);

use App\AppException;
use App\Config;
use App\DownloadedFile;
use App\GeminiClient;
use App\HttpResponse;
use Tests\Support\FakeHttpClient;

require_once __DIR__ . '/Support/FakeHttpClient.php';

function configuredGeminiClient(FakeHttpClient $http, int $maxBytes = 14680064): GeminiClient
{
    return new GeminiClient($http, Config::fromArray([
        'GEMINI_API_KEY' => 'chave-gemini-teste',
        'GEMINI_BASE_URL' => 'https://generativelanguage.googleapis.com/v1beta',
        'GEMINI_MODEL' => 'gemini-3.7-flash',
        'GEMINI_MAX_INLINE_AUDIO_BYTES' => (string) $maxBytes,
    ]));
}

function geminiTemporaryAudio(string $contents = 'fake mp3 bytes', ?int $reportedSize = null): DownloadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'gemini-audio-test');
    file_put_contents($path, $contents);
    return new DownloadedFile($path, 'audio/mpeg', $reportedSize ?? filesize($path));
}

function validGeminiAnalysis(): array
{
    return [
        'transcript' => 'Cliente pediu segunda via.',
        'summary' => 'Solicitação de segunda via.',
        'sentiment' => 'neutro',
        'lead_temperature' => 'morno',
        'sales_stage' => 'follow_up',
        'topics' => ['segunda via'],
        'key_points' => ['Cliente aguarda documento'],
        'customer_needs' => ['Crédito para aquisição de imóvel'],
        'buying_signals' => ['Perguntou sobre prazo de liberação'],
        'strengths' => ['Vendedor confirmou o objetivo do cliente'],
        'improvements' => [[
            'point' => 'Explorar orçamento',
            'why_it_matters' => 'Permite apresentar uma opção compatível.',
            'recommended_action' => 'Perguntar faixa de parcela e entrada.',
            'example_phrase' => 'Qual faixa de investimento mensal faz sentido para você?',
        ]],
        'scores' => [
            'overall' => 8,
            'opening' => 8,
            'discovery' => 7,
            'argumentation' => 8,
            'value_proposition' => 7,
            'objection_handling' => 6,
            'closing' => 7,
            'follow_up' => 9,
        ],
        'objections' => [[
            'objection' => 'Receio sobre o prazo',
            'evidence' => 'Cliente perguntou quando o crédito seria liberado.',
            'response_strategy' => 'Explicar etapas, documentos e validações sem prometer prazo não confirmado.',
            'suggested_response' => 'Vou detalhar cada etapa e confirmar o prazo aplicável ao seu caso.',
        ]],
        'recommended_approach' => 'Retomar o objetivo do cliente e apresentar a opção compatível com clareza de custos e etapas.',
        'discovery_questions' => [
            'Qual é o objetivo principal para utilizar o crédito?',
            'Qual prazo e faixa de investimento seriam adequados?',
        ],
        'sales_script' => [
            'opening' => 'Olá, aqui é da Titanium Consultoria. Quero entender seu objetivo para indicar a melhor opção.',
            'value_pitch' => 'Vamos comparar crédito, prazo, parcelas e condições de forma transparente.',
            'objection_handling' => 'Faz sentido ter essa dúvida. Vou explicar as etapas e condições antes de avançarmos.',
            'closing' => 'Posso enviar a simulação e combinarmos um retorno para revisar juntos?',
        ],
        'follow_up_plan' => [
            'timing' => 'Em até 24 horas',
            'channel' => 'WhatsApp',
            'objective' => 'Enviar simulação e confirmar interesse.',
            'message' => 'Olá! Conforme conversamos, envio a simulação da Titanium. Posso esclarecer algum ponto?',
        ],
        'next_steps' => ['Enviar documento'],
        'risks' => [],
        'compliance_alerts' => ['Não prometer liberação antes da validação das condições e documentos.'],
    ];
}

function geminiInteractionResponse(array $analysis, string $model = 'gemini-3.7-flash'): HttpResponse
{
    return new HttpResponse(200, [], json_encode([
        'id' => 'interaction-1',
        'model' => $model,
        'status' => 'completed',
        'steps' => [[
            'type' => 'model_output',
            'status' => 'done',
            'content' => [[
                'type' => 'text',
                'text' => json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

test('Gemini recebe o áudio e devolve transcrição e análise em uma requisição estruturada', function (): void {
    $http = new FakeHttpClient([geminiInteractionResponse(validGeminiAnalysis())]);
    $audio = geminiTemporaryAudio('audio-binario');

    try {
        $result = configuredGeminiClient($http)->analyze($audio, [
            'id' => 'call-1',
            'from' => '1000',
            'to' => '+5548999999999',
            'duration' => 75,
            'started_at' => '2026-08-28T10:00:00-03:00',
        ]);
    } finally {
        $audio->delete();
    }

    assertSameValue('Cliente pediu segunda via.', $result['transcript']);
    assertSameValue('neutro', $result['sentiment']);
    assertSameValue('Enviar documento', $result['next_steps'][0]);
    assertSameValue(2, $result['analysis_version']);
    assertSameValue(8, $result['scores']['overall']);
    assertSameValue('Receio sobre o prazo', $result['objections'][0]['objection']);
    assertSameValue('WhatsApp', $result['follow_up_plan']['channel']);
    assertSameValue('Explorar orçamento', $result['improvements'][0]['point']);
    assertSameValue('gemini-3.7-flash', $result['provider_model']);
    assertSameValue(1, count($http->requests));
    assertSameValue('https://generativelanguage.googleapis.com/v1beta/interactions', $http->requests[0]['url']);
    assertSameValue('chave-gemini-teste', $http->requests[0]['headers']['x-goog-api-key']);

    $body = json_decode($http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
    assertSameValue('gemini-3.7-flash', $body['model']);
    assertSameValue('audio', $body['input'][1]['type']);
    assertSameValue('audio/mpeg', $body['input'][1]['mime_type']);
    assertSameValue(base64_encode('audio-binario'), $body['input'][1]['data']);
    assertSameValue('application/json', $body['response_format']['mime_type']);
    assertTrueValue(in_array('transcript', $body['response_format']['schema']['required'], true));
    assertTrueValue(in_array('scores', $body['response_format']['schema']['required'], true));
    assertTrueValue(in_array('objections', $body['response_format']['schema']['required'], true));
    assertTrueValue(in_array('follow_up_plan', $body['response_format']['schema']['required'], true));
    assertSameValue(5, $body['response_format']['schema']['properties']['improvements']['maxItems']);
    assertSameValue(5, $body['response_format']['schema']['properties']['objections']['maxItems']);
    assertSameValue(false, $body['store']);
});

test('Gemini bloqueia análise quando a chave não foi configurada', function (): void {
    $client = new GeminiClient(new FakeHttpClient(), Config::fromArray([
        'GEMINI_API_KEY' => '',
        'GEMINI_MODEL' => 'gemini-3.7-flash',
    ]));
    $audio = geminiTemporaryAudio();

    try {
        assertThrows(fn () => $client->analyze($audio, []), AppException::class, 'AI_NOT_CONFIGURED');
    } finally {
        $audio->delete();
    }
});

test('Gemini usa o modelo gratuito de menor demanda quando nenhum modelo é informado', function (): void {
    $http = new FakeHttpClient([
        geminiInteractionResponse(validGeminiAnalysis(), 'gemini-3.5-flash-lite'),
    ]);
    $client = new GeminiClient($http, Config::fromArray([
        'GEMINI_API_KEY' => 'chave-gemini-teste',
        'GEMINI_BASE_URL' => 'https://generativelanguage.googleapis.com/v1beta',
    ]));
    $audio = geminiTemporaryAudio();

    try {
        $result = $client->analyze($audio, []);
    } finally {
        $audio->delete();
    }

    $body = json_decode($http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
    assertSameValue('gemini-3.5-flash-lite', $body['model']);
    assertSameValue('gemini-3.5-flash-lite', $result['provider_model']);
});

test('Gemini rejeita áudio acima do limite de requisição inline sem chamar a API', function (): void {
    $http = new FakeHttpClient();
    $audio = geminiTemporaryAudio('pequeno', 101);

    try {
        assertThrows(
            fn () => configuredGeminiClient($http, 100)->analyze($audio, []),
            AppException::class,
            'AUDIO_TOO_LARGE'
        );
        assertSameValue(0, count($http->requests));
    } finally {
        $audio->delete();
    }
});

test('Gemini rejeita resposta sem JSON estruturado ou sem transcrição', function (): void {
    $invalidHttp = new FakeHttpClient([new HttpResponse(200, [], json_encode([
        'status' => 'completed',
        'steps' => [[
            'type' => 'model_output',
            'content' => [['type' => 'text', 'text' => '{inválido']],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))]);
    $audio = geminiTemporaryAudio();
    try {
        assertThrows(
            fn () => configuredGeminiClient($invalidHttp)->analyze($audio, []),
            AppException::class,
            'AI_ANALYSIS_FAILED'
        );
    } finally {
        $audio->delete();
    }

    $missingTranscript = validGeminiAnalysis();
    $missingTranscript['transcript'] = '';
    $emptyHttp = new FakeHttpClient([geminiInteractionResponse($missingTranscript)]);
    $audio = geminiTemporaryAudio();
    try {
        assertThrows(
            fn () => configuredGeminiClient($emptyHttp)->analyze($audio, []),
            AppException::class,
            'AI_TRANSCRIPTION_FAILED'
        );
    } finally {
        $audio->delete();
    }
});

test('Gemini converte chave inválida e limite gratuito em erros seguros', function (): void {
    $invalidKey = new FakeHttpClient([new HttpResponse(400, [], json_encode([
        'error' => [
            'code' => 400,
            'message' => 'API key not valid. Please pass a valid API key.',
            'status' => 'INVALID_ARGUMENT',
            'details' => [['reason' => 'API_KEY_INVALID']],
        ],
    ], JSON_THROW_ON_ERROR))]);
    $audio = geminiTemporaryAudio();
    try {
        assertThrows(
            fn () => configuredGeminiClient($invalidKey)->analyze($audio, []),
            AppException::class,
            'AI_NOT_CONFIGURED'
        );
    } finally {
        $audio->delete();
    }

    $rateLimit = new FakeHttpClient([new HttpResponse(429, [], '{"error":{"status":"RESOURCE_EXHAUSTED"}}')]);
    $audio = geminiTemporaryAudio();
    try {
        assertThrows(
            fn () => configuredGeminiClient($rateLimit)->analyze($audio, []),
            AppException::class,
            'AI_RATE_LIMIT'
        );
    } finally {
        $audio->delete();
    }
});

test('Gemini trata alta demanda temporária como limite do serviço', function (): void {
    $http = new FakeHttpClient([new HttpResponse(500, [], json_encode([
        'error' => [
            'message' => 'gemini-3.7-flash is currently experiencing high demand',
            'code' => 'api_error',
        ],
    ], JSON_THROW_ON_ERROR))]);
    $audio = geminiTemporaryAudio();

    try {
        assertThrows(
            fn () => configuredGeminiClient($http)->analyze($audio, []),
            AppException::class,
            'AI_RATE_LIMIT'
        );
    } finally {
        $audio->delete();
    }
});

test('Gemini converte falha de rede em erro específico de análise', function (): void {
    $http = new FakeHttpClient();
    $http->requestError = new AppException(
        'Operation timed out after 30000 milliseconds.',
        'HTTP_REQUEST_FAILED',
        502
    );
    $audio = geminiTemporaryAudio();

    try {
        assertThrows(
            fn () => configuredGeminiClient($http)->analyze($audio, []),
            AppException::class,
            'AI_ANALYSIS_FAILED'
        );
    } finally {
        $audio->delete();
    }
});
