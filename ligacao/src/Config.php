<?php

declare(strict_types=1);

namespace App;

final class Config
{
    /** @param array<string, string> $values */
    private function __construct(private array $values)
    {
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            return new self([]);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new AppException('Não foi possível ler a configuração.', 'CONFIG_READ_FAILED', 500);
        }

        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ($key === '') {
                continue;
            }

            $length = strlen($value);
            if ($length >= 2) {
                $first = $value[0];
                $last = $value[$length - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $values[$key] = $value;
        }

        return new self($values);
    }

    /** @param array<string, scalar|null> $values */
    public static function fromArray(array $values): self
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[(string) $key] = $value === null ? '' : (string) $value;
        }

        return new self($normalized);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function required(string $key): string
    {
        $value = trim((string) $this->get($key, ''));
        if ($value === '') {
            throw new AppException("Configuração obrigatória ausente: {$key}.", 'CONFIG_MISSING', 503);
        }

        return $value;
    }

    public function int(string $key, int $default, int $min, int $max): int
    {
        $raw = $this->get($key);
        $value = $raw !== null && filter_var($raw, FILTER_VALIDATE_INT) !== false
            ? (int) $raw
            : $default;

        return max($min, min($max, $value));
    }
}
