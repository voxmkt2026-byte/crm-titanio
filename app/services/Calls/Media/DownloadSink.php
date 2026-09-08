<?php
declare(strict_types=1);
namespace Titanium\Calls\Media;

final class DownloadSink
{
    private int $size=0;
    private ?string $failure=null;

    public function __construct(private $stream, private int $maxBytes) {}

    public function write(string $chunk): int
    {
        $length=strlen($chunk);
        if ($this->size+$length>$this->maxBytes) {
            $this->failure='AUDIO_TOO_LARGE';
            return 0;
        }
        $written=@fwrite($this->stream,$chunk);
        if ($written===false || $written!==$length) {
            $this->failure='RECORDING_STORAGE_UNAVAILABLE';
            return 0;
        }
        $this->size+=$written;
        return $written;
    }
    public function size():int { return $this->size; }
    public function failureCode():?string { return $this->failure; }
}
