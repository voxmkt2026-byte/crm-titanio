<?php

declare(strict_types=1);

namespace App;

interface AiAnalyzer
{
    /** @param array<string, mixed> $call @return array<string, mixed> */
    public function analyze(DownloadedFile $audio, array $call): array;
}

