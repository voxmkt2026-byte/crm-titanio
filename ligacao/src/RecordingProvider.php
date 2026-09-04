<?php

declare(strict_types=1);

namespace App;

interface RecordingProvider
{
    public function download(string $callId): DownloadedFile;

    public function stream(
        string $callId,
        ?string $range,
        callable $headersCallback,
        callable $chunkCallback
    ): void;
}

