<?php

declare(strict_types=1);

namespace App;

final class RecordingService implements RecordingProvider
{
    public function __construct(
        private CallsGateway $calls,
        private HttpClientInterface $http,
        private int $maxBytes
    ) {
    }

    public function download(string $callId): DownloadedFile
    {
        return $this->http->download($this->resolveUrl($callId), [], $this->maxBytes);
    }

    public function stream(
        string $callId,
        ?string $range,
        callable $headersCallback,
        callable $chunkCallback
    ): void {
        $this->http->stream(
            $this->resolveUrl($callId),
            [],
            $range,
            $headersCallback,
            $chunkCallback
        );
    }

    private function resolveUrl(string $callId): string
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $callId)) {
            throw new AppException('Identificador de ligação inválido.', 'INVALID_CALL_ID', 400);
        }

        $call = $this->calls->findCall($callId);
        if ($call === null) {
            throw new AppException('Ligação não encontrada.', 'CALL_NOT_FOUND', 404);
        }

        $url = isset($call['record_url']) && is_string($call['record_url'])
            ? trim($call['record_url'])
            : '';
        if ($url === '') {
            throw new AppException('Ligação sem gravação.', 'RECORDING_NOT_AVAILABLE', 404);
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new AppException('URL de gravação inválida.', 'INVALID_RECORDING_URL', 502);
        }

        return $url;
    }
}

