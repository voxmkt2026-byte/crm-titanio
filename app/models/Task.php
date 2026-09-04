<?php
/**
 * app/models/Task.php
 * Tarefas internas (demandas de trabalho entre colaboradores), podendo
 * ou não estar vinculadas a um lead específico. Ver database/sql/migration_tasks.sql.
 */

require_once APP_PATH . '/core/Model.php';

class Task extends Model
{
    protected string $table = 'tasks';

    /** Colunas permitidas para ordenação (evita SQL injection via ORDER BY) */
    private array $sortableColumns = [
        'id', 'title', 'priority', 'status', 'due_at', 'created_at', 'updated_at',
    ];

    /**
     * Busca uma tarefa com dados relacionados (responsável, criador, lead).
     */
    public function findWithRelations(int $id)
    {
        $stmt = $this->db->prepare(
            "SELECT t.*,
                    a.name AS assigned_name, a.email AS assigned_email,
                    c.name AS creator_name,
                    l.name AS lead_name, l.lead_code AS lead_code
             FROM tasks t
             LEFT JOIN users a ON a.id = t.assigned_to
             LEFT JOIN users c ON c.id = t.creator_id
             LEFT JOIN leads l ON l.id = t.lead_id
             WHERE t.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Listagem paginada com filtros. $scope define o "escopo de visibilidade":
     *  - 'all'      -> todas as tarefas (só deve ser usado se Auth::can('tasks.view_all'))
     *  - 'mine'     -> tarefas atribuídas ao usuário logado
     *  - 'created'  -> tarefas criadas pelo usuário logado
     *  - 'visible'  -> tarefas onde o usuário é criador, responsável ou watcher
     *                  (usado como base para quem não tem tasks.view_all)
     *
     * @param array $filters ['status','priority','assigned_to','creator_id','lead_id','overdue','search']
     */
    public function paginate(array $filters, string $scope, int $userId, int $page = 1, int $perPage = 20, string $sortBy = 'created_at', string $sortDir = 'DESC'): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        if (!in_array($sortBy, $this->sortableColumns, true)) {
            $sortBy = 'created_at';
        }
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        [$where, $params] = $this->buildWhere($filters, $scope, $userId);

        $sql = "SELECT DISTINCT t.*, a.name AS assigned_name, c.name AS creator_name, l.name AS lead_name
                FROM tasks t
                LEFT JOIN users a ON a.id = t.assigned_to
                LEFT JOIN users c ON c.id = t.creator_id
                LEFT JOIN leads l ON l.id = t.lead_id
                LEFT JOIN task_watchers w ON w.task_id = t.id
                {$where}
                ORDER BY t.{$sortBy} {$sortDir}
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        $total = $this->countFiltered($filters, $scope, $userId);

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    public function countFiltered(array $filters, string $scope, int $userId): int
    {
        [$where, $params] = $this->buildWhere($filters, $scope, $userId);
        $sql = "SELECT COUNT(DISTINCT t.id) AS total
                FROM tasks t
                LEFT JOIN task_watchers w ON w.task_id = t.id
                {$where}";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch();
        return (int) ($row['total'] ?? 0);
    }

    /** Contagem simples de tarefas pendentes/em andamento atribuídas a um usuário, com prazo vencido (para badge da sidebar). */
    public function countOverdueForUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM tasks
             WHERE assigned_to = :user_id
               AND status NOT IN ('concluida','cancelada')
               AND due_at IS NOT NULL AND due_at < NOW()"
        );
        $stmt->execute([':user_id' => $userId]);
        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    /** Contagem simples de tarefas pendentes/em andamento atribuídas a um usuário (para badge da sidebar). */
    public function countPendingForUser(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM tasks
             WHERE assigned_to = :user_id
               AND status NOT IN ('concluida','cancelada')"
        );
        $stmt->execute([':user_id' => $userId]);
        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    /**
     * Tarefas do usuário que vencem hoje ou já estão atrasadas (não concluídas/canceladas),
     * usado pela tela "Meu Dia" (TodayController). Ordenadas por prazo (mais urgentes primeiro).
     */
    public function dueTodayOrOverdueForUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, l.name AS lead_name
             FROM tasks t
             LEFT JOIN leads l ON l.id = t.lead_id
             WHERE t.assigned_to = :user_id
               AND t.status NOT IN ('concluida','cancelada')
               AND t.due_at IS NOT NULL
               AND t.due_at <= (CURDATE() + INTERVAL 1 DAY - INTERVAL 1 SECOND)
             ORDER BY t.due_at ASC
             LIMIT " . (int) $limit
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    /** Tarefas vinculadas a um lead específico (seção "Tarefas relacionadas" no perfil do lead). */
    public function forLead(int $leadId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, a.name AS assigned_name
             FROM tasks t
             LEFT JOIN users a ON a.id = t.assigned_to
             WHERE t.lead_id = :lead_id
             ORDER BY t.created_at DESC"
        );
        $stmt->execute([':lead_id' => $leadId]);
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO tasks (title, description, creator_id, assigned_to, lead_id, priority, status, due_at, sla_hours, created_at, updated_at)
             VALUES (:title, :description, :creator_id, :assigned_to, :lead_id, :priority, :status, :due_at, :sla_hours, NOW(), NOW())"
        );
        $stmt->execute([
            ':title'       => $data['title'],
            ':description' => $data['description'] ?? null,
            ':creator_id'  => $data['creator_id'],
            ':assigned_to' => $data['assigned_to'] ?? null,
            ':lead_id'     => $data['lead_id'] ?? null,
            ':priority'    => $data['priority'] ?? 'media',
            ':status'      => $data['status'] ?? 'pendente',
            ':due_at'      => $data['due_at'] ?? null,
            ':sla_hours'   => $data['sla_hours'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Atualização geral dos campos editáveis da tarefa (título, descrição, prazo, prioridade, lead vinculado). */
    public function updateTask(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE tasks SET
                title = :title,
                description = :description,
                priority = :priority,
                due_at = :due_at,
                sla_hours = :sla_hours,
                lead_id = :lead_id,
                updated_at = NOW()
             WHERE id = :id"
        );
        return $stmt->execute([
            ':title'       => $data['title'],
            ':description' => $data['description'] ?? null,
            ':priority'    => $data['priority'] ?? 'media',
            ':due_at'      => $data['due_at'] ?? null,
            ':sla_hours'   => $data['sla_hours'] ?? null,
            ':lead_id'     => $data['lead_id'] ?? null,
            ':id'          => $id,
        ]);
    }

    /** Reatribui o responsável pela tarefa. */
    public function reassign(int $id, ?int $userId): bool
    {
        $stmt = $this->db->prepare("UPDATE tasks SET assigned_to = :assigned_to, updated_at = NOW() WHERE id = :id");
        return $stmt->execute([':assigned_to' => $userId, ':id' => $id]);
    }

    /**
     * Muda o status da tarefa, preenchendo started_at/completed_at conforme
     * a transição (started_at só na primeira vez que entra em 'em_andamento',
     * completed_at ao concluir).
     */
    public function changeStatus(int $id, string $status, array $current): bool
    {
        $sets = ['status = :status', 'updated_at = NOW()'];
        $params = [':status' => $status, ':id' => $id];

        if ($status === 'em_andamento' && empty($current['started_at'])) {
            $sets[] = 'started_at = NOW()';
        }

        if ($status === 'concluida') {
            $sets[] = 'completed_at = NOW()';
        } elseif ($current['status'] === 'concluida' && $status !== 'concluida') {
            // Reabrindo uma tarefa concluída: limpa completed_at
            $sets[] = 'completed_at = NULL';
        }

        $sql = "UPDATE tasks SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Calcula o status do SLA da tarefa em relação a "agora" (ou ao
     * completed_at, se já concluída): compara created_at + sla_hours contra
     * a referência. Retorna array com informações prontas para exibição.
     */
    public function slaStatus(array $task): array
    {
        if (empty($task['sla_hours'])) {
            return ['has_sla' => false, 'within_sla' => null, 'label' => '-', 'class' => 'secondary'];
        }

        $deadline = strtotime((string) $task['created_at']) + ((int) $task['sla_hours'] * 3600);
        $reference = !empty($task['completed_at']) ? strtotime((string) $task['completed_at']) : time();

        $diff = $deadline - $reference;
        $withinSla = $diff >= 0;

        $hours = (int) floor(abs($diff) / 3600);
        $minutes = (int) floor((abs($diff) % 3600) / 60);
        $timeStr = $hours > 0 ? ($hours . 'h' . ($minutes > 0 ? $minutes . 'min' : '')) : ($minutes . 'min');

        if (!empty($task['completed_at'])) {
            $label = $withinSla ? 'Concluída dentro do SLA' : 'Concluída com SLA estourado (' . $timeStr . ' de atraso)';
        } else {
            $label = $withinSla ? $timeStr . ' restantes' : 'Estourado há ' . $timeStr;
        }

        return [
            'has_sla'    => true,
            'within_sla' => $withinSla,
            'label'      => $label,
            'class'      => $withinSla ? 'success' : 'danger',
        ];
    }

    private function buildWhere(array $filters, string $scope, int $userId): array
    {
        $conditions = [];
        $params = [];

        switch ($scope) {
            case 'mine':
                $conditions[] = 't.assigned_to = :scope_user';
                $params[':scope_user'] = $userId;
                break;
            case 'created':
                $conditions[] = 't.creator_id = :scope_user';
                $params[':scope_user'] = $userId;
                break;
            case 'visible':
                $conditions[] = '(t.creator_id = :scope_user OR t.assigned_to = :scope_user2 OR w.user_id = :scope_user3)';
                $params[':scope_user'] = $userId;
                $params[':scope_user2'] = $userId;
                $params[':scope_user3'] = $userId;
                break;
            case 'all':
            default:
                // sem restrição adicional
                break;
        }

        if (!empty($filters['status'])) {
            $conditions[] = 't.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['priority'])) {
            $conditions[] = 't.priority = :priority';
            $params[':priority'] = $filters['priority'];
        }
        if (!empty($filters['assigned_to'])) {
            $conditions[] = 't.assigned_to = :assigned_to';
            $params[':assigned_to'] = $filters['assigned_to'];
        }
        if (!empty($filters['creator_id'])) {
            $conditions[] = 't.creator_id = :creator_id';
            $params[':creator_id'] = $filters['creator_id'];
        }
        if (!empty($filters['lead_id'])) {
            $conditions[] = 't.lead_id = :lead_id';
            $params[':lead_id'] = $filters['lead_id'];
        }
        if (!empty($filters['overdue'])) {
            $conditions[] = "t.due_at IS NOT NULL AND t.due_at < NOW() AND t.status NOT IN ('concluida','cancelada')";
        }
        if (!empty($filters['search'])) {
            $conditions[] = '(t.title LIKE :search OR t.description LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
        return [$where, $params];
    }
}
