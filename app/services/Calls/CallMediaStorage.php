<?php
declare(strict_types=1);

final class CallMediaStorage
{
    public function __construct(private string $storageRoot) {}

    public function probe(): void
    {
        $temp=$this->tempDirectory().'/probe-'.bin2hex(random_bytes(12));
        $stored=null;
        try {
            $handle=@fopen($temp,'xb');
            if (!$handle) throw new RuntimeException('RECORDING_STORAGE_UNAVAILABLE');
            $written=fwrite($handle,'Titanium storage probe');
            fclose($handle);
            if ($written!==22) throw new RuntimeException('RECORDING_STORAGE_UNAVAILABLE');
            $stored=$this->store($temp);
        } finally {
            if ($stored!==null) @unlink($stored['absolute']);
            if (is_file($temp)) @unlink($temp);
        }
    }

    public function tempDirectory(): string
    {
        foreach (['calls-tmp', 'tmp'] as $folder) {
            $path = $this->storageRoot . '/' . $folder;
            if (!is_dir($path)) @mkdir($path, 0700, true);
            if (is_dir($path) && is_writable($path)) return $path;
        }
        throw new RuntimeException('RECORDING_STORAGE_UNAVAILABLE');
    }

    public function resolve(string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);
        if (preg_match('~^@private/([a-f0-9]{40}\.audio)$~D', $relative, $m)) {
            $root = realpath($this->storageRoot . '/call-recordings');
            $path = realpath($this->storageRoot . '/call-recordings/' . $m[1]);
        } else {
            if (!preg_match('~^(?:[0-9]{4}/[0-9]{2}/)?[a-f0-9]{40}\.audio$~D', $relative)) return null;
            $root = realpath($this->storageRoot . '/calls');
            $path = realpath($this->storageRoot . '/calls/' . $relative);
        }
        return $root && $path && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) && is_readable($path) ? $path : null;
    }

    /** Copies to an exclusively created final file; DB publication happens only after checksum verification. */
    public function store(string $source): array
    {
        $size = is_file($source) ? filesize($source) : false;
        if ($size === false || $size <= 0) throw new RuntimeException('AUDIO_UNAVAILABLE');
        $sourceHash = hash_file('sha256', $source);
        $name = bin2hex(random_bytes(20)) . '.audio';
        $folders = [date('Y/m') . '/' . $name => $this->storageRoot . '/calls/' . date('Y/m'),
            $name => $this->storageRoot . '/calls', '@private/' . $name => $this->storageRoot . '/call-recordings'];
        foreach ($folders as $relative => $folder) {
            if (!is_dir($folder)) @mkdir($folder, 0700, true);
            if (!is_dir($folder) || !is_writable($folder)) continue;
            $destination = $folder . '/' . $name;
            $out = @fopen($destination, 'xb');
            if ($out === false) continue;
            @chmod($destination, 0600);
            $in = @fopen($source, 'rb');
            $copied = $in === false ? false : stream_copy_to_stream($in, $out);
            $flushed = fflush($out);
            if (is_resource($in)) fclose($in);
            fclose($out);
            clearstatcache(true, $destination);
            if ($copied === $size && $flushed && hash_file('sha256', $destination) === $sourceHash) {
                return ['path'=>$relative,'absolute'=>$destination,'size'=>$size,'checksum'=>$sourceHash];
            }
            @unlink($destination);
        }
        throw new RuntimeException('RECORDING_STORE_FAILED');
    }
}
