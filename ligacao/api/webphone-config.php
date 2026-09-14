<?php

declare(strict_types=1);

use App\AppException;
use App\JsonResponse;
use App\LocalRequestGuard;

require dirname(__DIR__) . '/bootstrap.php';

try {
    $guard = new LocalRequestGuard();
    $guard->assertLocalRead($_SERVER);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        throw new AppException('Método inválido na configuração do webphone.', 'METHOD_NOT_ALLOWED', 405);
    }

    JsonResponse::emit(JsonResponse::success(app_factory()->webphone()->sipConfig()));
} catch (Throwable $error) {
    error_log($error->getMessage());
    $status = $error instanceof AppException ? $error->status() : 500;
    JsonResponse::emit(JsonResponse::fromThrowable($error), $status);
}
