<?php
declare(strict_types=1);
use App\AppException;
use App\JsonResponse;
use App\LocalRequestGuard;
require dirname(__DIR__) . '/bootstrap.php';
try {
    (new LocalRequestGuard())->assertLocalRead($_SERVER);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { throw new AppException('Método inválido.', 'METHOD_NOT_ALLOWED', 405); }
    JsonResponse::emit(JsonResponse::success(['extensions' => app_factory()->extensionClient()->listExtensions()]));
} catch (Throwable $error) {
    JsonResponse::emit(JsonResponse::fromThrowable($error), $error instanceof AppException ? $error->status() : 500);
}
