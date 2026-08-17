<?php
/**
 * app/models/LeadHistory.php
 * Timeline/histórico de eventos de um lead.
 */

require_once APP_PATH . '/core/Model.php';

class LeadHistory extends Model
{
    protected string $table = 'lead_history';

    public function add(int $leadId, ?int $userId, string $type, string $description): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO lead_history (lead_id, user_id, type, description, created_at)
             VALUES (:lead_id, :user_id, :type, :description, NOW())"
        );
        $stmt->execute([
            ':lead_id'     => $leadId,
            ':user_id'     => $userId,
            ':type'        => $type,
            ':description' => $description,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function forLead(int $leadId): array
    {
        $stmt = $this->db->prepare(
            "SELECT h.*, u.name AS user_name
             FROM lead_history h
             LEFT JOIN users u ON u.id = h.user_id
             WHERE h.lead_id = :lead_id
             ORDER BY h.created_at DESC"
        );
        $stmt->execute([':lead_id' => $leadId]);
        return $stmt->fetchAll();
    }
}
