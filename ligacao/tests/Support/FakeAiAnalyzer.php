<?php

declare(strict_types=1);

namespace Tests\Support;

use App\AiAnalyzer;
use App\DownloadedFile;
use Throwable;

final class FakeAiAnalyzer implements AiAnalyzer
{
    public int $calls = 0;

    /** @param array<string, mixed> $result */
    public function __construct(private array $result = [], private ?Throwable $error = null)
    {
    }

    public function analyze(DownloadedFile $audio, array $call): array
    {
        $this->calls++;
        if ($this->error instanceof Throwable) {
            throw $this->error;
        }
        return $this->result;
    }
}

