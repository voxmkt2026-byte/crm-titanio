<?php

declare(strict_types=1);

namespace App;

use Throwable;

final class JsonResponse
{
    /** @var array<string, string> */
    private const MESSAGES = [
        'METHOD_NOT_ALLOWED' => 'Método não permitido.',
        'INVALID_JSON' => 'O conteúdo enviado não é um JSON válido.',
        'INVALID_CALL_ID' => 'Identificador de ligação inválido.',
        'INVALID_PHONE_FILTER' => 'Informe um número válido.',
        'CALL_NOT_FOUND' => 'Ligação não encontrada.',
        'RECORDING_NOT_AVAILABLE' => 'Esta ligação não possui gravação.',
        'INVALID_RECORDING_URL' => 'A gravação possui um endereço inválido.',
        'RECORDING_DOWNLOAD_FAILED' => 'Não foi possível baixar a gravação.',
        'RECORDING_STREAM_FAILED' => 'Não foi possível reproduzir a gravação.',
        'AUDIO_TOO_LARGE' => 'A gravação excede o tamanho permitido.',
        'API4COM_AUTH_FAILED' => 'O token da Api4Com é inválido ou não possui permissão.',
        'API4COM_RATE_LIMIT' => 'A Api4Com recebeu muitas solicitações. Tente novamente em instantes.',
        'API4COM_INVALID_RESPONSE' => 'A Api4Com retornou dados em formato inesperado.',
        'API4COM_UNAVAILABLE' => 'A Api4Com está indisponível no momento.',
        'CONFIG_MISSING' => 'O sistema ainda não foi completamente configurado.',
        'AI_NOT_CONFIGURED' => 'A análise de IA ainda não foi configurada.',
        'AI_TRANSCRIPTION_FAILED' => 'Não foi possível transcrever esta gravação.',
        'AI_ANALYSIS_FAILED' => 'Não foi possível analisar esta ligação.',
        'AI_RATE_LIMIT' => 'O serviço de IA recebeu muitas solicitações. Tente novamente em instantes.',
        'ANALYSIS_BUSY' => 'Esta ligação já está sendo analisada.',
        'INTERNAL_ERROR' => 'Ocorreu um erro inesperado. Tente novamente.',
    ];

    public static function success(mixed $data): array
    {
        return ['ok' => true, 'data' => $data];
    }

    public static function failure(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    public static function fromException(AppException $error): array
    {
        $code = $error->publicCode();
        return self::failure($code, self::MESSAGES[$code] ?? self::MESSAGES['INTERNAL_ERROR']);
    }

    public static function fromThrowable(Throwable $error): array
    {
        if ($error instanceof AppException) {
            return self::fromException($error);
        }
        return self::failure('INTERNAL_ERROR', self::MESSAGES['INTERNAL_ERROR']);
    }

    public static function emit(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}

