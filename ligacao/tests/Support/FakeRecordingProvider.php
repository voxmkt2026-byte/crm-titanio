<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DownloadedFile;
use App\RecordingProvider;

final class FakeRecordingProvider implements RecordingProvider
{
    public int $downloads = 0;
    public ?string $lastPath = null;

    public function download(string $callId): DownloadedFile
    {
        $this->downloads++;
        $path = tempnam(sys_get_temp_dir(), 'analysis-audio');
        file_put_contents($path, 'fake audio');
        $this->lastPath = $path;
        return new DownloadedFile($path, 'audio/mpeg', 10);
    }

    public function stream(
        string $callId,
        ?string $range,
        callable $headersCallback,
        callable $chunkCallback
    ): void {
        $headersCallback(200, ['content-type' => 'audio/mpeg']);
        $chunkCallback('fake audio');
    }
}

