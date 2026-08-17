<?php
/**
 * app/models/Setting.php
 * Configurações gerais do sistema (tabela chave/valor "settings").
 */

require_once APP_PATH . '/core/Model.php';

class Setting extends Model
{
    protected string $table = 'settings';

    /** Retorna todas as configurações como array associativo [key => value] */
    public function allAsMap(): array
    {
        $stmt = $this->db->query("SELECT `key`, `value` FROM settings");
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['key']] = $row['value'];
        }
        return $map;
    }

    public function get(string $key, $default = null)
    {
        $stmt = $this->db->prepare("SELECT `value` FROM settings WHERE `key` = :key LIMIT 1");
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    }

    /** Insere ou atualiza uma configuração (UPSERT) */
    public function set(string $key, ?string $value): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO settings (`key`, `value`) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)"
        );
        return $stmt->execute([':key' => $key, ':value' => $value]);
    }

    /** Salva várias configurações de uma vez a partir de um array [key => value] */
    public function setMany(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->set((string) $key, $value === '' ? null : (string) $value);
        }
    }
}
