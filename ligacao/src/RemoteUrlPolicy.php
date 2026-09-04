<?php

declare(strict_types=1);

namespace App;

final class RemoteUrlPolicy
{
    /** @var array<int, string> */
    private array $allowedSuffixes;

    /** @param array<int, string> $allowedSuffixes */
    public function __construct(array $allowedSuffixes)
    {
        $this->allowedSuffixes = array_values(array_filter(array_map(
            static fn (string $suffix): string => ltrim(strtolower(trim($suffix)), '.'),
            $allowedSuffixes
        )));
    }

    public function assertAllowed(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($scheme !== 'https' || $host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            throw new AppException('URL de gravação fora da política.', 'INVALID_RECORDING_URL', 502);
        }

        foreach ($this->allowedSuffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return;
            }
        }

        throw new AppException('Host de gravação não autorizado.', 'INVALID_RECORDING_URL', 502);
    }

    public function resolve(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            throw new AppException('Redirecionamento de gravação vazio.', 'INVALID_RECORDING_URL', 502);
        }
        if (parse_url($location, PHP_URL_SCHEME)) {
            return $location;
        }

        $scheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);
        $host = (string) parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        if ($scheme === '' || $host === '') {
            throw new AppException('Base de redirecionamento inválida.', 'INVALID_RECORDING_URL', 502);
        }
        $authority = $scheme . '://' . $host . ($port ? ':' . $port : '');
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }

        $path = (string) parse_url($baseUrl, PHP_URL_PATH);
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $authority . ($directory ? $directory . '/' : '/') . $location;
    }
}

