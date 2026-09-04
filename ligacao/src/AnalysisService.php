<?php

declare(strict_types=1);

namespace App;

final class AnalysisService
{
    private const REQUIRED_ANALYSIS_VERSION = 2;

    public function __construct(
        private AnalysisStore $store,
        private RecordingProvider $recordings,
        private AiAnalyzer $ai,
        private CallsGateway $calls
    ) {
    }

    /** @return array<string, mixed> */
    public function run(string $callId, bool $force = false): array
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $callId)) {
            throw new AppException('Identificador de ligação inválido.', 'INVALID_CALL_ID', 400);
        }

        if (!$force) {
            $cached = $this->store->get($callId);
            if ($this->isCurrentCache($cached)) {
                return $cached;
            }
        }

        $lock = fopen($this->store->lockPath($callId), 'c+');
        if ($lock === false) {
            throw new AppException('Não foi possível bloquear a análise.', 'ANALYSIS_STORAGE_FAILED', 500);
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new AppException('Análise já está em andamento.', 'ANALYSIS_BUSY', 409);
        }

        try {
            if (!$force) {
                $cached = $this->store->get($callId);
                if ($this->isCurrentCache($cached)) {
                    return $cached;
                }
            }

            $call = $this->calls->findCall($callId);
            if ($call === null) {
                throw new AppException('Ligação não encontrada.', 'CALL_NOT_FOUND', 404);
            }

            $audio = $this->recordings->download($callId);
            try {
                $analysis = $this->ai->analyze($audio, $call);
                return $this->store->put($callId, $analysis);
            } finally {
                $audio->delete();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed>|null $cached */
    private function isCurrentCache(?array $cached): bool
    {
        return $cached !== null
            && (int) ($cached['analysis_version'] ?? 0) >= self::REQUIRED_ANALYSIS_VERSION;
    }
}
