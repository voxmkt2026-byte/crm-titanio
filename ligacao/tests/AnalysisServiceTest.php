<?php

declare(strict_types=1);

use App\AnalysisService;
use App\AnalysisStore;
use App\AppException;
use Tests\Support\FakeAiAnalyzer;
use Tests\Support\FakeCallsGateway;
use Tests\Support\FakeRecordingProvider;

require_once __DIR__ . '/Support/FakeAiAnalyzer.php';
require_once __DIR__ . '/Support/FakeCallsGateway.php';
require_once __DIR__ . '/Support/FakeRecordingProvider.php';

function analysisFixture(?FakeAiAnalyzer $ai = null): array
{
    $directory = sys_get_temp_dir() . '/analysis-service-' . bin2hex(random_bytes(5));
    $store = new AnalysisStore($directory);
    $recordings = new FakeRecordingProvider();
    $ai = $ai ?: new FakeAiAnalyzer([
        'summary' => 'Nova análise',
        'analysis_version' => 2,
        'sentiment' => 'neutro',
        'topics' => [],
        'key_points' => [],
        'next_steps' => [],
        'risks' => [],
        'transcript' => 'Transcrição',
        'provider_model' => 'modelo-teste',
        'analyzed_at' => '2026-08-27T00:00:00Z',
    ]);
    $calls = new FakeCallsGateway([
        'call-1' => [
            'id' => 'call-1',
            'from' => '1000',
            'to' => '5548999999999',
            'duration' => 60,
            'record_url' => 'https://media.example/one.mp3',
        ],
    ]);
    $service = new AnalysisService($store, $recordings, $ai, $calls);

    return [$service, $store, $recordings, $ai, $directory];
}

test('serviço de análise retorna cache sem baixar ou cobrar IA', function (): void {
    [$service, $store, $recordings, $ai, $directory] = analysisFixture();
    try {
        $store->put('call-1', ['summary' => 'Em cache', 'analysis_version' => 2]);
        $result = $service->run('call-1');

        assertSameValue('Em cache', $result['summary']);
        assertSameValue(0, $ai->calls);
        assertSameValue(0, $recordings->downloads);
    } finally {
        removeTestDirectory($directory);
    }
});

test('serviço renova cache criado por uma versão antiga da análise', function (): void {
    [$service, $store, $recordings, $ai, $directory] = analysisFixture();
    try {
        $store->put('call-1', ['summary' => 'Formato antigo']);
        $result = $service->run('call-1');

        assertSameValue('Nova análise', $result['summary']);
        assertSameValue(2, $result['analysis_version']);
        assertSameValue(1, $ai->calls);
        assertSameValue(1, $recordings->downloads);
    } finally {
        removeTestDirectory($directory);
    }
});

test('serviço baixa, analisa, salva e remove o áudio temporário', function (): void {
    [$service, $store, $recordings, $ai, $directory] = analysisFixture();
    try {
        $result = $service->run('call-1');

        assertSameValue('Nova análise', $result['summary']);
        assertSameValue(1, $ai->calls);
        assertSameValue(1, $recordings->downloads);
        assertTrueValue(!is_file((string) $recordings->lastPath));
        assertSameValue('Nova análise', $store->get('call-1')['summary']);
    } finally {
        removeTestDirectory($directory);
    }
});

test('reanálise força nova IA e falha ainda remove áudio temporário', function (): void {
    [$service, $store, $recordings, $ai, $directory] = analysisFixture();
    try {
        $store->put('call-1', ['summary' => 'Antiga']);
        assertSameValue('Nova análise', $service->run('call-1', true)['summary']);
        assertSameValue(1, $ai->calls);
    } finally {
        removeTestDirectory($directory);
    }

    $errorAi = new FakeAiAnalyzer([], new AppException('Falha IA', 'AI_ANALYSIS_FAILED', 502));
    [$failingService, , $failingRecordings, , $failingDirectory] = analysisFixture($errorAi);
    try {
        assertThrows(fn () => $failingService->run('call-1'), AppException::class, 'AI_ANALYSIS_FAILED');
        assertTrueValue(!is_file((string) $failingRecordings->lastPath));
    } finally {
        removeTestDirectory($failingDirectory);
    }
});

test('serviço rejeita id inválido e análise concorrente', function (): void {
    [$service, $store, , , $directory] = analysisFixture();
    try {
        assertThrows(fn () => $service->run('../segredo'), AppException::class, 'INVALID_CALL_ID');

        $lock = fopen($store->lockPath('call-1'), 'c+');
        flock($lock, LOCK_EX);
        try {
            assertThrows(fn () => $service->run('call-1'), AppException::class, 'ANALYSIS_BUSY');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } finally {
        removeTestDirectory($directory);
    }
});
