<?php

declare(strict_types=1);

require_once __DIR__ . '/SecretVault.php';

interface IntegrationCredentialStore
{
    public function findCredential(string $provider, string $key): ?array;
    public function saveCredential(string $provider, string $key, string $value, bool $secret, int $userId): void;
    public function deleteCredential(string $provider, string $key): void;
}

final class IntegrationConfig
{
    public function __construct(private IntegrationCredentialStore $store, private SecretVault $vault, private array $environment = []) {}

    public static function legacyEnvironment(string $path): array
    {
        if (!is_file($path)) return [];
        $values = parse_ini_file($path, false, INI_SCANNER_RAW);
        if (!is_array($values)) return [];
        return array_intersect_key($values, array_flip(['API4COM_BASE_URL','API4COM_TOKEN','GEMINI_BASE_URL','GEMINI_API_KEY','GEMINI_MODEL']));
    }

    public function get(string $provider, string $key, ?string $default = null): ?string
    {
        $this->assertName($provider);
        $this->assertName($key);
        $row = $this->store->findCredential($provider, $key);
        if ($row !== null) {
            $value = (string) ($row['config_value'] ?? '');
            return (int) ($row['is_secret'] ?? 0) === 1 ? $this->vault->decrypt($value) : $value;
        }
        $environmentKey = strtoupper($provider . '_' . $key);
        $value = $this->environment[$environmentKey] ?? getenv($environmentKey);
        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function isConfigured(string $provider, string $key): bool
    {
        $value = $this->get($provider, $key);
        return $value !== null && $value !== '';
    }

    public function masked(string $provider, string $key): ?string { return null; }

    public function setSecret(string $provider, string $key, string $value, int $userId): void
    {
        if ($value === '') return;
        $this->store->saveCredential($provider, $key, $this->vault->encrypt($value), true, $userId);
    }

    public function setPublic(string $provider, string $key, string $value, int $userId): void
    {
        $this->store->saveCredential($provider, $key, $value, false, $userId);
    }

    public function remove(string $provider, string $key): void { $this->store->deleteCredential($provider, $key); }

    private function assertName(string $value): void
    {
        if (!preg_match('/^[a-z0-9_]{1,100}$/', $value)) throw new InvalidArgumentException('Nome de configuração inválido.');
    }
}
