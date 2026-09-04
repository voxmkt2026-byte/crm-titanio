<?php

declare(strict_types=1);

use App\AppException;
use App\JsonResponse;

require dirname(__DIR__) . '/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new AppException('Método inválido em análise.', 'METHOD_NOT_ALLOWED', 405);
    }

    $raw = file_get_contents('php://input');
    try {
        $payload = json_decode((string) $raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new AppException('JSON inválido na análise.', 'INVALID_JSON', 400, $error);
    }
    if (!is_array($payload)) {
        throw new AppException('Corpo de análise inválido.', 'INVALID_JSON', 400);
    }

    $callId = trim((string) ($payload['call_id'] ?? ''));
    $force = ($payload['force'] ?? false) === true;
    $result = app_factory()->analysis()->run($callId, $force);

    JsonResponse::emit(JsonResponse::success($result));
} catch (Throwable $error) {
    error_log('[analyze] ' . $error->getMessage());
    $status = $error instanceof AppException ? $error->status() : 500;
    JsonResponse::emit(JsonResponse::fromThrowable($error), $status);
}

