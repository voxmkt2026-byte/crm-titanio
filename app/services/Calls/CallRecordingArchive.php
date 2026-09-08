<?php
declare(strict_types=1);
require_once __DIR__ . '/CallsApiClient.php';
require_once __DIR__ . '/CallMediaStorage.php';
require_once __DIR__ . '/CallMediaTransport.php';

final class CallRecordingArchive
{
    private CallMediaStorage $storage;
    private CallsApiClient $api;

    public function __construct(private PDO $db, private IntegrationConfig $config,
        ?CallMediaStorage $storage = null, ?CallsApiClient $api = null, private ?Closure $downloader = null)
    {
        $this->storage = $storage ?? new CallMediaStorage(STORAGE_PATH);
        $this->api = $api ?? new CallsApiClient($config);
    }

    public function archive(int $callId): array
    {
        $query = $this->db->prepare('SELECT * FROM call_records WHERE id=:id LIMIT 1');
        $query->execute([':id'=>$callId]);
        $call = $query->fetch(PDO::FETCH_ASSOC);
        if (!$call) throw new RuntimeException('CALL_NOT_FOUND');
        $cached=self::cached($call,$this->storage);
        if ($cached!==null) return $cached;
        $remote = $this->api->find((string) $call['external_id']);
        $url = trim((string) ($remote['record_url'] ?? ''));
        if ($url === '') throw new RuntimeException('RECORDING_NOT_AVAILABLE');
        $http = CallMediaTransport::http($this->storage->tempDirectory());
        $audio = $this->downloader ? ($this->downloader)($url) : $http->download($url, [], 104857600);
        $stored = null;
        try {
            CallMediaTransport::assertAudio($audio->path());
            $stored = $this->storage->store($audio->path());
            $mime = strtolower(trim(explode(';', $audio->mimeType())[0]));
            if (!str_starts_with($mime, 'audio/')) $mime = 'audio/mpeg';
            $days = $this->retentionDays();
            $expires = $days === 0 ? null : date('Y-m-d H:i:s', time() + $days * 86400);
            $update = $this->db->prepare("UPDATE call_records SET recording_status='stored',recording_path=:path,recording_mime=:mime,recording_size=:size,recording_checksum=:checksum,recording_expires_at=:expires WHERE id=:id");
            $update->execute([':path'=>$stored['path'],':mime'=>$mime,':size'=>$stored['size'],':checksum'=>$stored['checksum'],':expires'=>$expires,':id'=>$callId]);
            $call = array_merge($call, ['recording_status'=>'stored','recording_path'=>$stored['path'],'recording_mime'=>$mime,
                'recording_size'=>$stored['size'],'recording_checksum'=>$stored['checksum'],'recording_expires_at'=>$expires,
                'absolute_recording_path'=>$stored['absolute']]);
            return $call;
        } catch (Throwable $error) {
            if ($stored !== null) @unlink($stored['absolute']);
            throw $error;
        } finally {
            $audio->delete();
        }
    }

    public static function cached(array $call, CallMediaStorage $storage): ?array
    {
        if (in_array($call['recording_status'] ?? '', ['expired','discarded'], true)) throw new RuntimeException('RECORDING_EXPIRED');
        if (!empty($call['recording_expires_at']) && strtotime($call['recording_expires_at']) <= time()) throw new RuntimeException('RECORDING_EXPIRED');
        $existing =  $storage->resolve((string) ($call['recording_path'] ?? ''));
        if ($existing !== null && ($call['recording_status'] ?? '') === 'stored') {
            try {
                CallMediaTransport::assertAudio($existing);
                $validSize = empty($call['recording_size']) || filesize($existing) === (int) $call['recording_size'];
                $validHash = empty($call['recording_checksum']) || hash_file('sha256', $existing) === $call['recording_checksum'];
                if ($validSize && $validHash) {
                    $call['absolute_recording_path'] = $existing;
                    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($existing);
                    $call['recording_mime']=is_string($mime) && str_starts_with($mime,'audio/') ? $mime : 'audio/mpeg';
                    $call['recording_size']=filesize($existing);
                    return $call;
                }
            } catch (RuntimeException) { /* Recover invalid files previously saved from HTTP error pages. */ }
        }
        return null;
    }

    private function retentionDays(): int
    {
        $query = $this->db->prepare("SELECT value FROM settings WHERE `key`='calls_audio_retention_days' LIMIT 1");
        $query->execute();
        $value = $query->fetchColumn();
        return $value === false || $value === null || $value === '' ? 365 : max(0, min(3650, (int) $value));
    }
}
