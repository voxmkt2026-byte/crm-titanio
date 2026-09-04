<?php
/**
 * app/models/TaskComment.php
 * Comentários/observações de uma tarefa (timeline de andamento).
 */

require_once APP_PATH . '/core/Model.php';

class TaskComment extends Model
{
    protected string $table = 'task_comments';

    public function add(int $taskId, int $userId, string $comment): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO task_comments (task_id, user_id, comment, created_at)
             VALUES (:task_id, :user_id, :comment, NOW())"
        );
        $stmt->execute([
            ':task_id' => $taskId,
            ':user_id' => $userId,
            ':comment' => $comment,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function forTask(int $taskId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, u.name AS user_name
             FROM task_comments c
             LEFT JOIN users u ON u.id = c.user_id
             WHERE c.task_id = :task_id
             ORDER BY c.created_at ASC"
        );
        $stmt->execute([':task_id' => $taskId]);
        return $stmt->fetchAll();
    }
}
