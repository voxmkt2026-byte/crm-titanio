<?php
declare(strict_types=1);

final class CallMediaTransport
{
    public static function http(string $tempDirectory): \Titanium\Calls\Media\CurlHttpClient
    {
        // Load only classes; do not execute the standalone app or load a second .env.
        spl_autoload_register(static function (string $class): void {
            $prefix='Titanium\\Calls\\Media\\';
            if (!str_starts_with($class, $prefix)) return;
            $relative = substr($class, strlen($prefix));
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $relative)) return;
            $path = __DIR__ . '/Media/' . $relative . '.php';
            if (is_file($path)) require_once $path;
        });
        return new \Titanium\Calls\Media\CurlHttpClient(10, 90, $tempDirectory, 3, new \Titanium\Calls\Media\RemoteUrlPolicy(['api4com.com']));
    }

    public static function assertAudio(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) throw new RuntimeException('RECORDING_INVALID_CONTENT');
        $head = fread($handle, 512);
        fclose($handle);
        if (!is_string($head) || $head === '') throw new RuntimeException('RECORDING_INVALID_CONTENT');
        $trimmed = ltrim($head);
        if (preg_match('/^(?:<!doctype|<html|<\?xml|<Error|\{|\[)/i', $trimmed)) throw new RuntimeException('RECORDING_INVALID_CONTENT');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (is_string($mime) && (str_starts_with($mime, 'text/') || in_array($mime, ['application/json','application/xml'], true))) {
            throw new RuntimeException('RECORDING_INVALID_CONTENT');
        }
    }
}
