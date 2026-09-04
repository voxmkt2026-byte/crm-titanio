<?php

declare(strict_types=1);

require_once APP_PATH . '/core/Model.php';
require_once APP_PATH . '/services/Integration/IntegrationConfig.php';

final class IntegrationCredential extends Model implements IntegrationCredentialStore
{
    protected string $table = 'integration_credentials';

    public function findCredential(string $provider, string $key): ?array
    {
        $statement = $this->db->prepare('SELECT config_value, is_secret FROM integration_credentials WHERE provider = :provider AND config_key = :config_key LIMIT 1');
        $statement->execute([':provider' => $provider, ':config_key' => $key]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function saveCredential(string $provider, string $key, string $value, bool $secret, int $userId): void
    {
        $statement = $this->db->prepare('INSERT INTO integration_credentials (provider, config_key, config_value, is_secret, updated_by) VALUES (:provider, :config_key, :config_value, :is_secret, :updated_by) ON DUPLICATE KEY UPDATE config_value = VALUES(config_value), is_secret = VALUES(is_secret), updated_by = VALUES(updated_by), updated_at = NOW()');
        $statement->execute([':provider' => $provider, ':config_key' => $key, ':config_value' => $value, ':is_secret' => $secret ? 1 : 0, ':updated_by' => $userId]);
    }

    public function deleteCredential(string $provider, string $key): void
    {
        $statement = $this->db->prepare('DELETE FROM integration_credentials WHERE provider = :provider AND config_key = :config_key');
        $statement->execute([':provider' => $provider, ':config_key' => $key]);
    }
}
