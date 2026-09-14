<?php
declare(strict_types=1);
use App\AccountRegistry;
use App\AppException;
use App\AppFactory;
use App\JsonResponse;
use App\LocalRequestGuard;
require dirname(__DIR__) . '/bootstrap.php';
try {
    $guard = new LocalRequestGuard();
    $guard->assertLocalRead($_SERVER);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { throw new AppException('Método inválido.', 'METHOD_NOT_ALLOWED', 405); }
    $guard->assertSettingsRequest($_SERVER);
    try { $body = json_decode((string) file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new AppException('JSON inválido.', 'INVALID_JSON', 400); }
    if (!is_array($body)) { throw new AppException('JSON inválido.', 'INVALID_JSON', 400); }
    $id = AccountRegistry::validateId($_GET['account'] ?? '1');
    $config = app_accounts()->config($id, true);
    $factory = new AppFactory($config, null, $id);
    JsonResponse::emit(JsonResponse::success($factory->extensionClient()->testConnection($config->get('WEBPHONE_EXTENSION', '1000') ?: '1000')));
} catch (Throwable $error) {
    JsonResponse::emit(JsonResponse::fromThrowable($error), $error instanceof AppException ? $error->status() : 500);
}
