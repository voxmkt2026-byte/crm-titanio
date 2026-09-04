<?php

declare(strict_types=1);

namespace App;

final class AnalysisStore
{
    public function __construct(private string $directory)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new AppException('Não foi possível preparar o cache de análises.', 'ANALYSIS_STORAGE_FAILED', 500);
        }
    }

    /** @return array<string, mixed>|null */
    public function get(string $callId): ?array
    {
        $path = $this->path($callId);
        $lock = $this->openLock($path);

        try {
            flock($lock, LOCK_SH);
            if (!is_file($path)) {
                return null;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                return null;
            }
            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                return null;
            }
            return is_array($decoded) ? $decoded : null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $analysis @return array<string, mixed> */
    public function put(string $callId, array $analysis): array
    {
        $hash = hash('sha256', $callId);
        $record = array_merge($analysis, [
            'call_id_hash' => $hash,
            'created_at' => gmdate('c'),
            'schema_version' => 1,
        ]);
        $json = json_encode(
            $record,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
        );
        $path = $this->path($callId);
        $lock = $this->openLock($path);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        try {
            flock($lock, LOCK_EX);
            if (file_put_contents($temporary, $json, LOCK_EX) === false) {
                throw new AppException('Não foi possível salvar a análise.', 'ANALYSIS_STORAGE_FAILED', 500);
            }
            if (is_file($path) && !@unlink($path)) {
                throw new AppException('Não foi possível substituir a análise.', 'ANALYSIS_STORAGE_FAILED', 500);
            }
            if (!@rename($temporary, $path)) {
                throw new AppException('Não foi possível publicar a análise.', 'ANALYSIS_STORAGE_FAILED', 500);
            }
        } finally {
            @unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $record;
    }

    public function delete(string $callId): void
    {
        $path = $this->path($callId);
        $lock = $this->openLock($path);
        try {
            flock($lock, LOCK_EX);
            if (is_file($path)) {
                @unlink($path);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function lockPath(string $callId): string
    {
        return $this->path($callId) . '.work.lock';
    }

    private function path(string $callId): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $callId) . '.json';
    }

    /** @return resource */
    private function openLock(string $path)
    {
        $lock = fopen($path . '.lock', 'c+');
        if ($lock === false) {
            throw new AppException('Não foi possível bloquear o cache.', 'ANALYSIS_STORAGE_FAILED', 500);
        }
        return $lock;
    }
}

