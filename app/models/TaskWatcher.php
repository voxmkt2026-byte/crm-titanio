<?php
/**
 * app/models/TaskWatcher.php
 * Usuários que acompanham uma tarefa (recebem notificação de qualquer
 * atualização) mesmo não sendo o responsável atual.
 */

require_once APP_PATH . '/core/Model.php';

class TaskWatcher extends Model
{
    protected string $table = 'task_watchers';

    public function add(int $taskId, int $userId): void
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO task_watchers (task_id, user_id) VALUES (:task_id, :user_id)"
        );
        $stmt->execute([':task_id' => $taskId, ':user_id' => $userId]);
    }

    /** IDs de todos os watchers de uma tarefa. */
    public function userIdsForTask(int $taskId): array
    {
        $stmt = $this->db->prepare("SELECT user_id FROM task_watchers WHERE task_id = :task_id");
        $stmt->execute([':task_id' => $taskId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'user_id'));
    }

    public function isWatching(int $taskId, int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM task_watchers WHERE task_id = :task_id AND user_id = :user_id LIMIT 1");
        $stmt->execute([':task_id' => $taskId, ':user_id' => $userId]);
        return (bool) $stmt->fetch();
    }
}
