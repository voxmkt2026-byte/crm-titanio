<?php

declare(strict_types=1);

namespace Titanium\Calls\Media;

interface HttpClientInterface
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;

    /** @param array<string, string> $headers @param array<string, mixed> $fields */
    public function multipart(string $url, array $headers, array $fields): HttpResponse;

    /** @param array<string, string> $headers */
    public function download(string $url, array $headers, int $maxBytes): DownloadedFile;

    /**
     * @param array<string, string> $headers
     * @param callable(int, array<string, string>): void $headersCallback
     * @param callable(string): void $chunkCallback
     */
    public function stream(
        string $url,
        array $headers,
        ?string $range,
        callable $headersCallback,
        callable $chunkCallback
    ): void;
}
