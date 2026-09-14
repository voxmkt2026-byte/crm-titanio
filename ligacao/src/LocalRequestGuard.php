<?php

declare(strict_types=1);

namespace App;

final class LocalRequestGuard
{
    public function assertLocalRead(array $server): void
    {
        $this->assertAllowed((string) ($server['REMOTE_ADDR'] ?? ''));
        $host = (string) ($server['HTTP_HOST'] ?? '');
        if (!preg_match('/^(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]{1,5})?$/iD', $host)) {
            throw new AppException('Origem não autorizada.', 'WEBPHONE_ORIGIN_DENIED', 403);
        }
        $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
        $this->assertSameOriginJson('application/json', $origin, $host, (string) ($server['HTTP_SEC_FETCH_SITE'] ?? ''));
        $scheme = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off' ? 'https' : 'http';
        if ($origin !== '' && parse_url($origin, PHP_URL_SCHEME) !== $scheme) {
            throw new AppException('Origem não autorizada.', 'WEBPHONE_ORIGIN_DENIED', 403);
        }
    }

    public function assertSettingsRequest(array $server): void
    {
        $host = (string) ($server['HTTP_HOST'] ?? '');
        if (!preg_match('/^(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]{1,5})?$/iD', $host)) {
            throw new AppException('Origem não autorizada.', 'WEBPHONE_ORIGIN_DENIED', 403);
        }
        $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
        $fetchSite = (string) ($server['HTTP_SEC_FETCH_SITE'] ?? '');
        $this->assertSameOriginJson((string) ($server['CONTENT_TYPE'] ?? ''), $origin,
            (string) ($server['HTTP_HOST'] ?? ''), $fetchSite);
        $scheme = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off' ? 'https' : 'http';
        if (($origin === '' && $fetchSite !== 'same-origin')
            || ($origin !== '' && parse_url($origin, PHP_URL_SCHEME) !== $scheme)) {
            throw new AppException('Origem não autorizada.', 'WEBPHONE_ORIGIN_DENIED', 403);
        }
    }

    public function assertAllowed(string $remoteAddress): void
    {
        if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
            throw new AppException(
                'Acesso ao webphone permitido somente via loopback.',
                'WEBPHONE_LOCAL_ONLY',
                403
            );
        }
    }

    public function assertSameOriginJson(
        string $contentType,
        string $origin,
        string $host,
        string $fetchSite = ''
    ): void {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($mediaType !== 'application/json') {
            throw new AppException(
                'O webphone aceita somente requisições JSON.',
                'INVALID_CONTENT_TYPE',
                415
            );
        }

        $fetchSite = strtolower(trim($fetchSite));
        if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) {
            throw new AppException(
                'Origem não autorizada para o webphone.',
                'WEBPHONE_ORIGIN_DENIED',
                403
            );
        }

        $origin = trim($origin);
        if ($origin === '') {
            return;
        }

        $parts = parse_url($origin);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $originHost = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $originAuthority = $originHost;
        if (is_array($parts) && isset($parts['port'])) {
            $originAuthority .= ':' . (int) $parts['port'];
        }
        $expectedAuthority = strtolower(trim($host));

        if (!in_array($scheme, ['http', 'https'], true)
            || $originHost === ''
            || $expectedAuthority === ''
            || !hash_equals($expectedAuthority, $originAuthority)
        ) {
            throw new AppException(
                'Origem não autorizada para o webphone.',
                'WEBPHONE_ORIGIN_DENIED',
                403
            );
        }
    }
}
