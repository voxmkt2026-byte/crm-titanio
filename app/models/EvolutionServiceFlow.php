<?php
/** Fluxos de atendimento conduzidos pelo time, com etapas e textos sugeridos. */
require_once APP_PATH . '/core/Model.php';

class EvolutionServiceFlow extends Model
{
    protected string $table = 'evolution_service_flows';

    public function activeForInstance(string $instanceName): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM evolution_service_flows
                 WHERE active = 1 AND (instance_name IS NULL OR instance_name = '' OR instance_name = :instance)
                 ORDER BY name ASC"
            );
            $stmt->execute([':instance' => $instanceName]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function allFlows(): array
    {
        try {
            return $this->db->query("SELECT * FROM evolution_service_flows ORDER BY active DESC, name ASC")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function saveFlow(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $params = [
            ':name' => $data['name'],
            ':description' => $data['description'] ?: null,
            ':instance' => $data['instance_name'] ?: null,
            ':steps' => json_encode($data['steps'], JSON_UNESCAPED_UNICODE),
            ':active' => !empty($data['active']) ? 1 : 0,
        ];
        if ($id > 0) {
            $params[':id'] = $id;
            $this->db->prepare(
                "UPDATE evolution_service_flows
                 SET name=:name, description=:description, instance_name=:instance, steps_json=:steps, active=:active, updated_at=NOW()
                 WHERE id=:id"
            )->execute($params);
            return $id;
        }
        $this->db->prepare(
            "INSERT INTO evolution_service_flows (name, description, instance_name, steps_json, active, created_at, updated_at)
             VALUES (:name, :description, :instance, :steps, :active, NOW(), NOW())"
        )->execute($params);
        return (int) $this->db->lastInsertId();
    }

    public function parsedSteps(?array $flow): array
    {
        if (!$flow) {
            return [];
        }
        $steps = json_decode((string) ($flow['steps_json'] ?? '[]'), true);
        return is_array($steps) ? array_values($steps) : [];
    }
}
