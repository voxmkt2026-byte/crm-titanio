<?php

declare(strict_types=1);

use App\Api4ComClient;
use App\AppException;
use App\Config;
use App\HttpResponse;
use Tests\Support\FakeHttpClient;

require_once __DIR__ . '/Support/FakeHttpClient.php';

function api4comClient(FakeHttpClient $http): Api4ComClient
{
    return new Api4ComClient(
        $http,
        Config::fromArray([
            'API4COM_TOKEN' => 'token-de-teste',
            'API4COM_BASE_URL' => 'https://api.api4com.com/api/v1',
            'CALLS_PER_PAGE' => '20',
        ])
    );
}

function api4comCallPayload(): array
{
    return [
        'id' => 'call-1',
        'domain' => 'example',
        'call_type' => 'outbound',
        'started_at' => '2026-08-27T10:00:00.000Z',
        'ended_at' => '2026-08-27T10:01:15.000Z',
        'from' => '1000',
        'to' => '+5548999999999',
        'duration' => 75,
        'hangup_cause' => 'NORMAL_CLEARING',
        'record_url' => 'https://media.example/call-1.mp3',
        'email' => 'agente@example.com',
        'first_name' => 'Ana',
        'last_name' => 'Silva',
        'BINA' => '554833330000',
        'minute_price' => 0.10,
        'call_price' => 0.20,
        'metadata' => ['ticket' => '123'],
    ];
}

test('cliente Api4Com autentica, filtra número e normaliza a listagem', function (): void {
    $http = new FakeHttpClient([
        new HttpResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => [api4comCallPayload()],
            'meta' => [
                'totalItemCount' => 1,
                'totalPageCount' => 1,
                'itemsPerPage' => 20,
                'currentPage' => 1,
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $result = api4comClient($http)->listCalls(1, '+55 (48) 99999-9999');

    assertSameValue('call-1', $result['data'][0]['id']);
    assertSameValue(true, $result['data'][0]['has_recording']);
    assertTrueValue(!array_key_exists('record_url', $result['data'][0]));
    assertSameValue('token-de-teste', $http->requests[0]['headers']['Authorization']);

    parse_str((string) parse_url($http->requests[0]['url'], PHP_URL_QUERY), $query);
    $filter = json_decode($query['filter'], true, 512, JSON_THROW_ON_ERROR);
    assertSameValue('%5548999999999%', $filter['where']['or'][0]['from']['like']);
    assertSameValue('started_at DESC', $filter['order']);
});

test('busca por id mantém URL da gravação apenas para serviços internos', function (): void {
    $http = new FakeHttpClient([
        new HttpResponse(200, [], json_encode([
            'data' => [api4comCallPayload()],
            'meta' => ['totalItemCount' => 1],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $call = api4comClient($http)->findCall('call-1');

    assertSameValue('https://media.example/call-1.mp3', $call['record_url']);
    parse_str((string) parse_url($http->requests[0]['url'], PHP_URL_QUERY), $query);
    $filter = json_decode($query['filter'], true, 512, JSON_THROW_ON_ERROR);
    assertSameValue('call-1', $filter['where']['id']);
});

test('cliente Api4Com converte token rejeitado em erro seguro', function (): void {
    $http = new FakeHttpClient([new HttpResponse(401, [], '{"error":"unauthorized"}')]);

    assertThrows(
        fn () => api4comClient($http)->listCalls(1, null),
        AppException::class,
        'API4COM_AUTH_FAILED'
    );
});

test('cliente Api4Com rejeita JSON inválido e id inseguro', function (): void {
    $http = new FakeHttpClient([new HttpResponse(200, [], 'não-json')]);

    assertThrows(
        fn () => api4comClient($http)->listCalls(1, null),
        AppException::class,
        'API4COM_INVALID_RESPONSE'
    );

    $unused = new FakeHttpClient();
    assertThrows(
        fn () => api4comClient($unused)->findCall('../segredo'),
        AppException::class,
        'INVALID_CALL_ID'
    );
});

