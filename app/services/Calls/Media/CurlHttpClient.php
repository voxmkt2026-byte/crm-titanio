<?php

declare(strict_types=1);

namespace Titanium\Calls\Media;

final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private int $connectTimeout = 10,
        private int $timeout = 30,
        private ?string $tempDirectory = null,
        private int $maxRedirects = 3,
        private ?RemoteUrlPolicy $recordingUrlPolicy = null
    ) {
        $this->tempDirectory = $tempDirectory ?: dirname(__DIR__) . '/storage/tmp';
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $responseHeaders = [];
        $handle = $this->createHandle($url, $headers);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($curl, string $line) use (&$responseHeaders): int {
            $this->captureHeader($line, $responseHeaders);
            return strlen($line);
        });
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $this->throwCurlError($handle);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($status, $responseHeaders, (string) $responseBody);
    }

    public function multipart(string $url, array $headers, array $fields): HttpResponse
    {
        $responseHeaders = [];
        $handle = $this->createHandle($url, $headers);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($curl, string $line) use (&$responseHeaders): int {
            $this->captureHeader($line, $responseHeaders);
            return strlen($line);
        });

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $this->throwCurlError($handle);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new HttpResponse($status, $responseHeaders, (string) $responseBody);
    }

    public function download(string $url, array $headers, int $maxBytes): DownloadedFile
    {
        if ($this->recordingUrlPolicy instanceof RemoteUrlPolicy) {
            $this->recordingUrlPolicy->assertAllowed($url);
        }
        $this->ensureTempDirectory();
        $path = tempnam($this->tempDirectory, 'audio-');
        if ($path === false) {
            throw new AppException('Não foi possível criar arquivo temporário.', 'TEMP_FILE_FAILED', 500);
        }

        $stream = fopen($path, 'wb');
        if ($stream === false) {
            @unlink($path);
            throw new AppException('Não foi possível abrir arquivo temporário.', 'TEMP_FILE_FAILED', 500);
        }

        $sink = new DownloadSink($stream, $maxBytes);
        $redirectError = null;
        $responseHeaders = [];
        $handle = $this->createHandle($url, $headers);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($handle, CURLOPT_MAXREDIRS, $this->maxRedirects);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($curl, string $line) use (&$responseHeaders, &$redirectError): int {
            $this->captureHeader($line, $responseHeaders);
            if (stripos($line, 'Location:') === 0 && $this->recordingUrlPolicy instanceof RemoteUrlPolicy) {
                try {
                    $location = trim(substr($line, strlen('Location:')));
                    $target = $this->recordingUrlPolicy->resolve(
                        (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
                        $location
                    );
                    $this->recordingUrlPolicy->assertAllowed($target);
                } catch (AppException $error) {
                    $redirectError = $error;
                    return 0;
                }
            }
            return strlen($line);
        });
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($curl, string $chunk) use ($sink): int {
            return $sink->write($chunk);
        });

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($stream);

        if ($redirectError instanceof AppException) {
            @unlink($path);
            throw $redirectError;
        }
        if ($sink->failureCode() !== null) {
            @unlink($path);
            throw new AppException('Falha ao escrever o download.', $sink->failureCode(), $sink->failureCode() === 'AUDIO_TOO_LARGE' ? 413 : 500);
        }
        if ($ok === false || $status < 200 || $status >= 300) {
            @unlink($path);
            throw new AppException('Não foi possível baixar a gravação. ' . $error, 'RECORDING_DOWNLOAD_FAILED', 502);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path) ?: ($responseHeaders['content-type'] ?? 'application/octet-stream');

        return new DownloadedFile($path, $mime, $sink->size());
    }

    public function stream(
        string $url,
        array $headers,
        ?string $range,
        callable $headersCallback,
        callable $chunkCallback
    ): void {
        if ($this->recordingUrlPolicy instanceof RemoteUrlPolicy) {
            $this->recordingUrlPolicy->assertAllowed($url);
        }
        if ($range !== null && $range !== '') {
            $headers['Range'] = $range;
        }

        $responseHeaders = [];
        $status = 0;
        $headersSent = false;
        $bodyAllowed = false;
        $redirectError = null;
        $handle = $this->createHandle($url, $headers);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($handle, CURLOPT_MAXREDIRS, $this->maxRedirects);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($curl, string $line) use (
            &$responseHeaders,
            &$status,
            &$headersSent,
            &$bodyAllowed,
            &$redirectError,
            $headersCallback
        ): int {
            $trimmed = trim($line);
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $trimmed, $matches)) {
                $status = (int) $matches[1];
                $responseHeaders = [];
                $headersSent = false;
            } elseif ($trimmed === '') {
                $bodyAllowed = $status >= 200 && $status < 300;
                if (!$headersSent) {
                    $headersCallback($status, $this->safeStreamHeaders($responseHeaders));
                    $headersSent = true;
                }
            } else {
                $this->captureHeader($line, $responseHeaders);
                if (stripos($line, 'Location:') === 0 && $this->recordingUrlPolicy instanceof RemoteUrlPolicy) {
                    try {
                        $location = trim(substr($line, strlen('Location:')));
                        $target = $this->recordingUrlPolicy->resolve(
                            (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
                            $location
                        );
                        $this->recordingUrlPolicy->assertAllowed($target);
                    } catch (AppException $error) {
                        $redirectError = $error;
                        return 0;
                    }
                }
            }
            return strlen($line);
        });
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($curl, string $chunk) use (&$bodyAllowed, $chunkCallback): int {
            if ($bodyAllowed) {
                $chunkCallback($chunk);
            }
            return strlen($chunk);
        });

        $ok = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);
        if ($redirectError instanceof AppException) {
            throw $redirectError;
        }
        if ($ok === false || $status < 200 || $status >= 300) {
            throw new AppException('Não foi possível transmitir a gravação. ' . $error, 'RECORDING_STREAM_FAILED', 502);
        }
    }

    /** @param array<string, string> $headers */
    private function createHandle(string $url, array $headers)
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new AppException('URL remota inválida.', 'INVALID_REMOTE_URL', 502);
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new AppException('Não foi possível iniciar a conexão.', 'HTTP_INIT_FAILED', 500);
        }

        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Api4Com-Call-Insights/1.0',
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
        ]);

        return $handle;
    }

    /** @param array<string, string> $headers @return array<int, string> */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }
        return $formatted;
    }

    /** @param array<string, string> $headers */
    private function captureHeader(string $line, array &$headers): void
    {
        if (!str_contains($line, ':')) {
            return;
        }
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    /** @param array<string, string> $headers @return array<string, string> */
    private function safeStreamHeaders(array $headers): array
    {
        return array_intersect_key($headers, array_flip([
            'content-type',
            'content-length',
            'content-range',
            'accept-ranges',
        ]));
    }

    private function ensureTempDirectory(): void
    {
        if (!is_dir($this->tempDirectory) && !mkdir($this->tempDirectory, 0775, true) && !is_dir($this->tempDirectory)) {
            throw new AppException('Não foi possível preparar arquivos temporários.', 'TEMP_DIRECTORY_FAILED', 500);
        }
    }

    private function throwCurlError($handle): void
    {
        $message = curl_error($handle);
        curl_close($handle);
        throw new AppException('Falha na comunicação com serviço remoto. ' . $message, 'HTTP_REQUEST_FAILED', 502);
    }
}
