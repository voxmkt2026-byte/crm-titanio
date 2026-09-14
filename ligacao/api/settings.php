<?php
declare(strict_types=1);
use App\AppException;
use App\JsonResponse;
use App\LocalRequestGuard;
require dirname(__DIR__) . '/bootstrap.php';
try {
    $guard = new LocalRequestGuard();
    $guard->assertAllowed((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new AppException('Método inválido.', 'METHOD_NOT_ALLOWED', 405);
    }
    $guard->assertSettingsRequest($_SERVER);
    try { $payload = json_decode((string) file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new AppException('JSON inválido.', 'INVALID_JSON', 400); }
    if (!is_array($payload)) { throw new AppException('JSON inválido.', 'INVALID_JSON', 400); }
    JsonResponse::emit(JsonResponse::success(['accounts' => app_accounts()->save($payload)]));
} catch (Throwable $error) {
    JsonResponse::emit(JsonResponse::fromThrowable($error), $error instanceof AppException ? $error->status() : 500);
}
