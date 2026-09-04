<?php

declare(strict_types=1);

final class CallRecordingArchive
{
    private const MAX_BYTES = 104857600;

    public function __construct(private PDO $db, private IntegrationConfig $config) {}

    public function archive(int $callId): array
    {
        $query = $this->db->prepare('SELECT * FROM call_records WHERE id=:id LIMIT 1');
        $query->execute([':id' => $callId]);
        $call = $query->fetch(PDO::FETCH_ASSOC);
        if (!$call) throw new RuntimeException('CALL_NOT_FOUND');

        $existing = $this->storedPath((string) ($call['recording_path'] ?? ''));
        if (($call['recording_status'] ?? '') === 'stored' && $existing !== null) {
            $call['absolute_recording_path'] = $existing;
            return $call;
        }

        $remote = $this->fetchRemote((string) $call['external_id']);
        $url = trim((string) ($remote['record_url'] ?? ''));
        $this->assertAllowedUrl($url);
        $tmpDir = STORAGE_PATH . '/tmp';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) throw new RuntimeException('RECORDING_STORAGE_UNAVAILABLE');
        $tmp = tempnam($tmpDir, 'call-');
        if ($tmp === false) throw new RuntimeException('RECORDING_STORAGE_UNAVAILABLE');

        try {
            $handle = fopen($tmp, 'wb');
            if ($handle === false) throw new RuntimeException('RECORDING_STORAGE_UNAVAILABLE');
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_FILE => $handle, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120, CURLOPT_MAXFILESIZE => self::MAX_BYTES,
            ]);
            $ok = curl_exec($curl);
            $mime = strtolower(trim(explode(';', (string) (curl_getinfo($curl, CURLINFO_CONTENT_TYPE) ?: 'audio/mpeg'))[0]));
            $effectiveUrl = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
            curl_close($curl);
            fclose($handle);
            $this->assertAllowedUrl($effectiveUrl);
            $size = is_file($tmp) ? (int) filesize($tmp) : 0;
            if (!$ok || $size <= 0 || $size > self::MAX_BYTES) throw new RuntimeException('RECORDING_DOWNLOAD_FAILED');

            $relativeDirectory = date('Y/m');
            $directory = STORAGE_PATH . '/calls/' . $relativeDirectory;
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('RECORDING_STORAGE_UNAVAILABLE');
            $name = bin2hex(random_bytes(20)) . '.audio';
            $destination = $directory . '/' . $name;
            if (!rename($tmp, $destination)) throw new RuntimeException('RECORDING_STORE_FAILED');

            $days = $this->retentionDays();
            $expiresAt = $days === 0 ? null : date('Y-m-d H:i:s', time() + $days * 86400);
            $update = $this->db->prepare("UPDATE call_records SET recording_status='stored',recording_path=:path,recording_mime=:mime,recording_size=:size,recording_checksum=:checksum,recording_expires_at=:expires,last_error_code=NULL WHERE id=:id");
            $update->execute([
                ':path' => $relativeDirectory . '/' . $name,
                ':mime' => str_starts_with($mime, 'audio/') ? $mime : 'audio/mpeg',
                ':size' => $size, ':checksum' => hash_file('sha256', $destination),
                ':expires' => $expiresAt, ':id' => $callId,
            ]);
            $query->execute([':id' => $callId]);
            $stored = $query->fetch(PDO::FETCH_ASSOC);
            $stored['absolute_recording_path'] = $destination;
            return $stored;
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private function fetchRemote(string $externalId): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $externalId)) throw new RuntimeException('INVALID_CALL_ID');
        $filter = json_encode(['order' => 'started_at DESC', 'limit' => 20, 'where' => ['id' => $externalId]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $url = rtrim((string) $this->config->get('api4com', 'base_url', 'https://api.api4com.com/api/v1'), '/') . '/calls?' . http_build_query(['page' => 1, 'filter' => $filter]);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $this->config->get('api4com', 'token', ''), 'Accept: application/json'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($body) || $status < 200 || $status >= 300) throw new RuntimeException('API4COM_SYNC_FAILED');
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($payload['data'][0] ?? null) ? $payload['data'][0] : [];
    }

    private function assertAllowedUrl(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($scheme !== 'https' || $host === '' || filter_var($host, FILTER_VALIDATE_IP)
            || !($host === 'api4com.com' || str_ends_with($host, '.api4com.com'))) {
            throw new RuntimeException('INVALID_RECORDING_URL');
        }
    }

    private function storedPath(string $relative): ?string
    {
        if ($relative === '') return null;
        $root = realpath(STORAGE_PATH . '/calls');
        $path = realpath(STORAGE_PATH . '/calls/' . ltrim(str_replace('\\', '/', $relative), '/'));
        return $root && $path && str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR)) && is_file($path) ? $path : null;
    }

    private function retentionDays(): int
    {
        try {
            $query = $this->db->prepare("SELECT value FROM settings WHERE `key`='calls_audio_retention_days' LIMIT 1");
            $query->execute();
            return max(0, min(3650, (int) ($query->fetchColumn() ?: 365)));
        } catch (Throwable) {
            return 365;
        }
    }
}
