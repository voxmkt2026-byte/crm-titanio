<?php

declare(strict_types=1);

use App\AppException;
use App\JsonResponse;

require dirname(__DIR__) . '/bootstrap.php';

$streamStarted = false;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        throw new AppException('Método inválido em áudio.', 'METHOD_NOT_ALLOWED', 405);
    }

    $id = trim((string) ($_GET['id'] ?? ''));
    $range = isset($_SERVER['HTTP_RANGE']) ? (string) $_SERVER['HTTP_RANGE'] : null;

    app_factory()->recordings()->stream(
        $id,
        $range,
        function (int $status, array $headers) use (&$streamStarted): void {
            if ($status < 200 || $status >= 300) {
                return;
            }
            $streamStarted = true;
            http_response_code($status);
            header('Cache-Control: private, max-age=300');
            header('X-Content-Type-Options: nosniff');
            $headerNames = [
                'content-type' => 'Content-Type',
                'content-length' => 'Content-Length',
                'content-range' => 'Content-Range',
                'accept-ranges' => 'Accept-Ranges',
            ];
            foreach ($headerNames as $source => $target) {
                if (isset($headers[$source])) {
                    header($target . ': ' . str_replace(["\r", "\n"], '', (string) $headers[$source]));
                }
            }
        },
        static function (string $chunk): void {
            echo $chunk;
            if (function_exists('fastcgi_finish_request')) {
                @ob_flush();
            }
            flush();
        }
    );
} catch (Throwable $error) {
    error_log('[audio] ' . $error->getMessage());
    if (!$streamStarted) {
        $status = $error instanceof AppException ? $error->status() : 500;
        JsonResponse::emit(JsonResponse::fromThrowable($error), $status);
    }
}

