<?php
/**
 * app/models/LossReason.php
 */

require_once APP_PATH . '/core/Model.php';

class LossReason extends Model
{
    protected string $table = 'loss_reasons';

    public function allActive(): array
    {
        $stmt = $this->db->query("SELECT * FROM loss_reasons WHERE active = 1 ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    public function create(string $name, bool $active = true): int
    {
        $stmt = $this->db->prepare("INSERT INTO loss_reasons (name, active) VALUES (:name, :active)");
        $stmt->execute([':name' => $name, ':active' => $active ? 1 : 0]);
        return (int) $this->db->lastInsertId();
    }

    public function updateReason(int $id, string $name, bool $active): bool
    {
        $stmt = $this->db->prepare("UPDATE loss_reasons SET name = :name, active = :active WHERE id = :id");
        return $stmt->execute([':name' => $name, ':active' => $active ? 1 : 0, ':id' => $id]);
    }

    /** Ranking de motivos de perda mais usados (join com leads.loss_reason_id) */
    public function rankingUsage(): array
    {
        $stmt = $this->db->query(
            "SELECT lr.id, lr.name, lr.active, COUNT(l.id) AS total
             FROM loss_reasons lr
             LEFT JOIN leads l ON l.loss_reason_id = lr.id
             GROUP BY lr.id, lr.name, lr.active
             ORDER BY total DESC, lr.name ASC"
        );
        return $stmt->fetchAll();
    }
}
