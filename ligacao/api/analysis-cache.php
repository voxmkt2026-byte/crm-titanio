<?php
declare(strict_types=1);
use App\AppException;
use App\JsonResponse;
use App\LocalRequestGuard;
require dirname(__DIR__) . '/bootstrap.php';
try {
    (new LocalRequestGuard())->assertLocalRead($_SERVER);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        throw new AppException('Método inválido.', 'METHOD_NOT_ALLOWED', 405);
    }
    $id = $_GET['id'] ?? '';
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $id)) {
        throw new AppException('Identificador inválido.', 'INVALID_CALL_ID', 400);
    }
    JsonResponse::emit(JsonResponse::success(['analysis' => app_factory()->analysisStore()->get($id)]));
} catch (Throwable $error) {
    JsonResponse::emit(JsonResponse::fromThrowable($error), $error instanceof AppException ? $error->status() : 500);
}
