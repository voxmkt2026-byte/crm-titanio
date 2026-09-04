<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DownloadedFile;
use App\HttpClientInterface;
use App\HttpResponse;
use RuntimeException;
use Throwable;

final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, HttpResponse> */
    private array $responses;

    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    /** @var array<int, array<string, mixed>> */
    public array $downloads = [];

    /** @var array<int, array<string, mixed>> */
    public array $streams = [];

    public ?DownloadedFile $downloadedFile = null;

    public ?Throwable $requestError = null;

    /** @param array<int, HttpResponse> $responses */
    public function __construct(array $responses = [])
    {
        $this->responses = array_values($responses);
    }

    public function queue(HttpResponse $response): void
    {
        $this->responses[] = $response;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body') + ['type' => 'request'];
        if ($this->requestError instanceof Throwable) {
            throw $this->requestError;
        }
        return $this->nextResponse();
    }

    public function multipart(string $url, array $headers, array $fields): HttpResponse
    {
        $this->requests[] = compact('url', 'headers', 'fields') + ['method' => 'POST', 'type' => 'multipart'];
        return $this->nextResponse();
    }

    public function download(string $url, array $headers, int $maxBytes): DownloadedFile
    {
        $this->downloads[] = compact('url', 'headers', 'maxBytes');
        if (!$this->downloadedFile instanceof DownloadedFile) {
            throw new RuntimeException('Nenhum arquivo falso configurado.');
        }
        return $this->downloadedFile;
    }

    public function stream(
        string $url,
        array $headers,
        ?string $range,
        callable $headersCallback,
        callable $chunkCallback
    ): void {
        $this->streams[] = compact('url', 'headers', 'range');
        $headersCallback(206, [
            'content-type' => 'audio/mpeg',
            'content-range' => 'bytes 0-3/4',
            'content-length' => '4',
        ]);
        $chunkCallback('test');
    }

    private function nextResponse(): HttpResponse
    {
        if ($this->responses === []) {
            throw new RuntimeException('Nenhuma resposta falsa configurada.');
        }
        return array_shift($this->responses);
    }
}
