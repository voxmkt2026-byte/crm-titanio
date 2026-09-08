<?php

declare(strict_types=1);

namespace Titanium\Calls\Media;

final class DownloadedFile
{
    private bool $deleted = false;

    public function __construct(
        private string $path,
        private string $mimeType,
        private int $size
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function delete(): void
    {
        if (!$this->deleted && is_file($this->path)) {
            @unlink($this->path);
        }
        $this->deleted = true;
    }
}
