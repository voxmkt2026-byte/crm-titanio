<?php

declare(strict_types=1);

use App\AppException;
use App\DownloadedFile;
use App\RecordingService;
use Tests\Support\FakeCallsGateway;
use Tests\Support\FakeHttpClient;

require_once __DIR__ . '/Support/FakeHttpClient.php';
require_once __DIR__ . '/Support/FakeCallsGateway.php';

function recordingService(array $calls, ?FakeHttpClient $http = null): array
{
    $http = $http ?: new FakeHttpClient();
    $path = tempnam(sys_get_temp_dir(), 'recording-test');
    file_put_contents($path, 'audio');
    $http->downloadedFile = new DownloadedFile($path, 'audio/mpeg', 5);

    return [new RecordingService(new FakeCallsGateway($calls), $http, 1024), $http, $path];
}

test('gravação é resolvida pelo id usando apenas URL retornada pela Api4Com', function (): void {
    [$service, $http, $path] = recordingService([
        'call-1' => ['id' => 'call-1', 'record_url' => 'https://media.example/one.mp3'],
    ]);

    try {
        $file = $service->download('call-1');
        assertSameValue('https://media.example/one.mp3', $http->downloads[0]['url']);
        assertSameValue(1024, $http->downloads[0]['maxBytes']);
        assertSameValue($path, $file->path());
    } finally {
        @unlink($path);
    }
});

test('gravação ausente e URL insegura são rejeitadas', function (): void {
    [$missingService, , $missingPath] = recordingService([]);
    try {
        assertThrows(fn () => $missingService->download('call-1'), AppException::class, 'CALL_NOT_FOUND');
    } finally {
        @unlink($missingPath);
    }

    [$unsafeService, , $unsafePath] = recordingService([
        'call-1' => ['id' => 'call-1', 'record_url' => 'file:///etc/passwd'],
    ]);
    try {
        assertThrows(fn () => $unsafeService->download('call-1'), AppException::class, 'INVALID_RECORDING_URL');
    } finally {
        @unlink($unsafePath);
    }
});

test('proxy de áudio encaminha intervalo para a origem', function (): void {
    [$service, $http, $path] = recordingService([
        'call-1' => ['id' => 'call-1', 'record_url' => 'https://media.example/one.mp3'],
    ]);
    $receivedStatus = null;
    $receivedBody = '';

    try {
        $service->stream(
            'call-1',
            'bytes=0-3',
            function (int $status) use (&$receivedStatus): void { $receivedStatus = $status; },
            function (string $chunk) use (&$receivedBody): void { $receivedBody .= $chunk; }
        );

        assertSameValue('bytes=0-3', $http->streams[0]['range']);
        assertSameValue(206, $receivedStatus);
        assertSameValue('test', $receivedBody);
    } finally {
        @unlink($path);
    }
});

