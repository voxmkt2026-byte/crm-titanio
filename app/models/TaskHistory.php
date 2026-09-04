<?php
/**
 * app/models/TaskHistory.php
 * Timeline/histórico de auditoria de uma tarefa (mesmo padrão de LeadHistory).
 */

require_once APP_PATH . '/core/Model.php';

class TaskHistory extends Model
{
    protected string $table = 'task_history';

    public function add(int $taskId, ?int $userId, string $type, string $description): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO task_history (task_id, user_id, type, description, created_at)
             VALUES (:task_id, :user_id, :type, :description, NOW())"
        );
        $stmt->execute([
            ':task_id'     => $taskId,
            ':user_id'     => $userId,
            ':type'        => $type,
            ':description' => $description,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function forTask(int $taskId): array
    {
        $stmt = $this->db->prepare(
            "SELECT h.*, u.name AS user_name
             FROM task_history h
             LEFT JOIN users u ON u.id = h.user_id
             WHERE h.task_id = :task_id
             ORDER BY h.created_at DESC"
        );
        $stmt->execute([':task_id' => $taskId]);
        return $stmt->fetchAll();
    }
}
