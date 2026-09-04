<?php
/**
 * Linhas de atendimento WhatsApp conectadas à mesma Evolution API.
 * A tabela é criada em migration_evolution_inbox_v3.sql. Até a migration ser
 * executada, o modelo mantém compatibilidade usando a instância legada das
 * configurações, para que o painel atual não deixe de funcionar.
 */
require_once APP_PATH . '/core/Model.php';
require_once APP_PATH . '/models/Setting.php';

class EvolutionConnection extends Model
{
    protected string $table = 'evolution_instances';

    public function active(): array
    {
        try {
            $stmt = $this->db->query(
                "SELECT * FROM evolution_instances WHERE active = 1 ORDER BY is_default DESC, label ASC, id ASC"
            );
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return $this->legacyConnection();
        }
    }

    public function allConnections(): array
    {
        try {
            return $this->db->query("SELECT * FROM evolution_instances ORDER BY is_default DESC, active DESC, label ASC, id ASC")->fetchAll();
        } catch (Throwable $e) {
            return $this->legacyConnection();
        }
    }

    public function findActiveByName(string $name): ?array
    {
        $name = trim($name);
        if ($name === '') {
            return $this->default();
        }
        try {
            $stmt = $this->db->prepare("SELECT * FROM evolution_instances WHERE instance_name = :name AND active = 1 LIMIT 1");
            $stmt->execute([':name' => $name]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            foreach ($this->legacyConnection() as $connection) {
                if ($connection['instance_name'] === $name) {
                    return $connection;
                }
            }
            return null;
        }
    }

    public function default(): ?array
    {
        try {
            $stmt = $this->db->query("SELECT * FROM evolution_instances WHERE active = 1 ORDER BY is_default DESC, id ASC LIMIT 1");
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (Throwable $e) {
            return $this->legacyConnection()[0] ?? null;
        }
    }

    public function saveConnection(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $payloadMode = in_array(($data['payload_mode'] ?? ''), ['auto', 'official', 'legacy_text'], true)
            ? $data['payload_mode'] : 'auto';
        $params = [
            ':name' => $data['instance_name'],
            ':label' => $data['label'] ?: $data['instance_name'],
            ':mode' => $payloadMode,
            ':active' => !empty($data['active']) ? 1 : 0,
            ':default' => !empty($data['is_default']) ? 1 : 0,
        ];

        if ($id > 0) {
            if ($params[':default']) {
                $this->db->prepare("UPDATE evolution_instances SET is_default = 0")->execute();
            }
            $params[':id'] = $id;
            $this->db->prepare(
                "UPDATE evolution_instances
                 SET instance_name = :name, label = :label, payload_mode = :mode, active = :active, is_default = :default
                 WHERE id = :id"
            )->execute($params);
            return $id;
        }

        if ($params[':default']) {
            $this->db->prepare("UPDATE evolution_instances SET is_default = 0")->execute();
        }
        $this->db->prepare(
            "INSERT INTO evolution_instances (instance_name, label, payload_mode, active, is_default, created_at, updated_at)
             VALUES (:name, :label, :mode, :active, :default, NOW(), NOW())"
        )->execute($params);
        return (int) $this->db->lastInsertId();
    }

    public function deactivate(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE evolution_instances SET active = 0, is_default = 0 WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    private function legacyConnection(): array
    {
        try {
            $name = trim((string) (new Setting())->get('evolution_instance_name', ''));
        } catch (Throwable $e) {
            $name = '';
        }
        if ($name === '') {
            return [];
        }
        return [[
            'id' => 0,
            'instance_name' => $name,
            'label' => 'WhatsApp principal',
            'payload_mode' => 'auto',
            'active' => 1,
            'is_default' => 1,
        ]];
    }
}
