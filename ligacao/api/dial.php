<?php

declare(strict_types=1);

use App\AppException;
use App\JsonResponse;
use App\LocalRequestGuard;

require dirname(__DIR__) . '/bootstrap.php';

try {
    $guard = new LocalRequestGuard();
    $guard->assertLocalRead($_SERVER);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new AppException('Método inválido ao iniciar chamada.', 'METHOD_NOT_ALLOWED', 405);
    }
    $guard->assertSettingsRequest($_SERVER);

    $raw = file_get_contents('php://input');
    try {
        $payload = json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new AppException('JSON inválido ao iniciar chamada.', 'INVALID_JSON', 400, $error);
    }
    if (!is_array($payload)) {
        throw new AppException('Corpo inválido ao iniciar chamada.', 'INVALID_JSON', 400);
    }

    $phone = is_string($payload['phone'] ?? null) ? trim($payload['phone']) : '';
    JsonResponse::emit(JsonResponse::success(app_factory()->webphone()->dial($phone)));
} catch (Throwable $error) {
    error_log($error->getMessage());
    $status = $error instanceof AppException ? $error->status() : 500;
    JsonResponse::emit(JsonResponse::fromThrowable($error), $status);
}
