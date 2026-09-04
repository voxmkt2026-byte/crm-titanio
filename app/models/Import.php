<?php
/**
 * app/models/Import.php
 * Registro de cada importação de leads via CSV (Fase 4).
 * Ver também app/models/ImportError.php e app/controllers/ImportController.php.
 */

require_once APP_PATH . '/core/Model.php';

class Import extends Model
{
    protected string $table = 'imports';

    public function create(?int $userId, ?string $filename, int $totalRows): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO imports (user_id, filename, total_rows, created_count, updated_count, error_count, status, created_at)
             VALUES (:user_id, :filename, :total_rows, 0, 0, 0, 'processando', NOW())"
        );
        $stmt->execute([
            ':user_id'    => $userId,
            ':filename'   => $filename,
            ':total_rows' => $totalRows,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function finish(int $id, int $totalRows, int $createdCount, int $updatedCount, int $errorCount): void
    {
        $status = $errorCount > 0 ? 'concluido_com_erros' : 'concluido';
        $stmt = $this->db->prepare(
            "UPDATE imports SET total_rows = :total, created_count = :created, updated_count = :updated, error_count = :errors, status = :status WHERE id = :id"
        );
        $stmt->execute([
            ':total'   => $totalRows,
            ':created' => $createdCount,
            ':updated' => $updatedCount,
            ':errors'  => $errorCount,
            ':status'  => $status,
            ':id'      => $id,
        ]);
    }

    public function markFailed(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE imports SET status = 'falhou' WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public function findWithUser(int $id)
    {
        $stmt = $this->db->prepare(
            "SELECT i.*, u.name AS user_name FROM imports i LEFT JOIN users u ON u.id = i.user_id WHERE i.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function recent(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->db->prepare(
            "SELECT i.*, u.name AS user_name FROM imports i
             LEFT JOIN users u ON u.id = i.user_id
             ORDER BY i.created_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
