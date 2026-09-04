<?php
/**
 * app/models/ActivityLog.php
 * Log de auditoria geral do sistema (tabela activity_log).
 */

require_once APP_PATH . '/core/Model.php';

class ActivityLog extends Model
{
    protected string $table = 'activity_log';

    public function add(?int $userId, string $action, ?string $details = null, ?string $ip = null): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO activity_log (user_id, action, details, ip_address, created_at)
             VALUES (:user_id, :action, :details, :ip_address, NOW())"
        );
        $stmt->execute([
            ':user_id'    => $userId,
            ':action'     => $action,
            ':details'    => $details,
            ':ip_address' => $ip,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Busca paginada com filtros por usuário, ação (busca parcial) e período.
     *
     * @param array $filters ['user_id','action','date_from','date_to']
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT l.*, u.name AS user_name
                FROM activity_log l
                LEFT JOIN users u ON u.id = l.user_id
                {$where}
                ORDER BY l.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        $countSql = "SELECT COUNT(*) AS total FROM activity_log l {$where}";
        $countStmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['user_id'])) {
            $conditions[] = "l.user_id = :user_id";
            $params[':user_id'] = $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $conditions[] = "l.action LIKE :action";
            $params[':action'] = '%' . $filters['action'] . '%';
        }
        if (!empty($filters['date_from'])) {
            $conditions[] = "DATE(l.created_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $conditions[] = "DATE(l.created_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }

        $where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return [$where, $params];
    }
}
