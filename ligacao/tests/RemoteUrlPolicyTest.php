<?php

declare(strict_types=1);

use App\AppException;
use App\RemoteUrlPolicy;

test('política de gravação aceita HTTPS em subdomínios autorizados', function (): void {
    $policy = new RemoteUrlPolicy(['api4com.com']);

    $policy->assertAllowed('https://listener.api4com.com/recording/123');
    $policy->assertAllowed('https://fs7.api4com.com/audio/file.mp3');
    assertSameValue(
        'https://fs7.api4com.com/audio/file.mp3',
        $policy->resolve('https://listener.api4com.com/recording/123', 'https://fs7.api4com.com/audio/file.mp3')
    );
});

test('política de gravação bloqueia redirecionamento externo ou sem TLS', function (): void {
    $policy = new RemoteUrlPolicy(['api4com.com']);

    assertThrows(
        fn () => $policy->assertAllowed('https://evil-api4com.com/file.mp3'),
        AppException::class,
        'INVALID_RECORDING_URL'
    );
    assertThrows(
        fn () => $policy->assertAllowed('http://fs7.api4com.com/file.mp3'),
        AppException::class,
        'INVALID_RECORDING_URL'
    );
    assertThrows(
        fn () => $policy->assertAllowed('https://127.0.0.1/file.mp3'),
        AppException::class,
        'INVALID_RECORDING_URL'
    );
});

