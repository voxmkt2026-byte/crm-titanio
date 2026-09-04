<?php

declare(strict_types=1);

use App\AppException;
use App\JsonResponse;

test('resposta JSON de sucesso possui envelope estável', function (): void {
    assertSameValue(
        ['ok' => true, 'data' => ['id' => 'call-1']],
        JsonResponse::success(['id' => 'call-1'])
    );
});

test('resposta JSON de erro não expõe mensagem interna', function (): void {
    $error = new AppException(
        'Falha interna contendo detalhes privados.',
        'CALL_NOT_FOUND',
        404
    );

    assertSameValue(
        [
            'ok' => false,
            'error' => [
                'code' => 'CALL_NOT_FOUND',
                'message' => 'Ligação não encontrada.',
            ],
        ],
        JsonResponse::fromException($error)
    );
});

