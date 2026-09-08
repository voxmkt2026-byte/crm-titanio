<?php

declare(strict_types=1);

namespace Titanium\Calls\Media;

final class HttpResponse
{
    /** @var array<string, string> */
    private array $headers;

    /** @param array<string, string> $headers */
    public function __construct(
        private int $status,
        array $headers,
        private string $body
    ) {
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new AppException('Resposta JSON inválida do serviço remoto.', 'HTTP_INVALID_JSON', 502, $error);
        }

        if (!is_array($decoded)) {
            throw new AppException('Resposta inesperada do serviço remoto.', 'HTTP_INVALID_JSON', 502);
        }

        return $decoded;
    }
}
