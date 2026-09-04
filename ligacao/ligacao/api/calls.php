<?php

declare(strict_types=1);

use App\AppException;
use App\JsonResponse;

require dirname(__DIR__) . '/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        throw new AppException('Método inválido em chamadas.', 'METHOD_NOT_ALLOWED', 405);
    }

    $page = max(1, min(1000000, (int) ($_GET['page'] ?? 1)));
    $number = isset($_GET['number']) ? mb_substr(trim((string) $_GET['number']), 0, 40) : null;
    $result = app_factory()->calls()->listCalls($page, $number);

    JsonResponse::emit(JsonResponse::success($result));
} catch (Throwable $error) {
    error_log('[calls] ' . $error->getMessage());
    $status = $error instanceof AppException ? $error->status() : 500;
    JsonResponse::emit(JsonResponse::fromThrowable($error), $status);
}

