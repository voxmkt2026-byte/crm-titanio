<?php
declare(strict_types=1);
use App\AccountRegistry;
use App\AppException;
use App\CallTrashStore;
use App\JsonResponse;
use App\LocalRequestGuard;
require dirname(__DIR__) . '/bootstrap.php';
try {
    $guard = new LocalRequestGuard(); $guard->assertLocalRead($_SERVER);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) { throw new AppException('Método inválido.', 'METHOD_NOT_ALLOWED', 405); }
    $id = AccountRegistry::validateId($_GET['account'] ?? '1');
    $store = new CallTrashStore(dirname(__DIR__) . '/storage/trash', $id);
    if ($method === 'POST') {
        $guard->assertSettingsRequest($_SERVER);
        try { $body = json_decode((string) file_get_contents('php://input'), true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new AppException('JSON inválido.', 'INVALID_JSON', 400); }
        if (!is_array($body)) { throw new AppException('JSON inválido.', 'INVALID_JSON', 400); }
        $callId = CallTrashStore::validateId($body['call_id'] ?? null);
        if (($body['action'] ?? '') === 'restore') { $store->restore($callId); }
        elseif (($body['action'] ?? '') === 'trash') {
            if (($body['confirmed'] ?? false) !== true) { throw new AppException('Confirme o envio para a lixeira.', 'TRASH_CONFIRMATION_REQUIRED', 400); }
            $reason = $body['reason'] ?? '';
            if (!is_string($reason) || mb_strlen($reason) > 500) { throw new AppException('Motivo inválido.', 'INVALID_TRASH_REASON', 400); }
            $call = app_factory()->calls()->findCall($callId);
            if ($call === null || ($call['id'] ?? '') !== $callId) { throw new AppException('Ligação não encontrada.', 'CALL_NOT_FOUND', 404); }
            $store->trash($callId, $call, $reason);
        } else { throw new AppException('Ação inválida.', 'INVALID_TRASH_ACTION', 400); }
    }
    $page = max(1, min(1000000, (int) ($_GET['page'] ?? 1)));
    JsonResponse::emit(JsonResponse::success($store->listing($page)));
} catch (Throwable $error) {
    JsonResponse::emit(JsonResponse::fromThrowable($error), $error instanceof AppException ? $error->status() : 500);
}
