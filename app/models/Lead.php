<?php
/**
 * app/models/Lead.php
 * Model de Leads: CRUD, busca, filtros, paginação server-side e checagem de duplicidade.
 */

require_once APP_PATH . '/core/Model.php';

class Lead extends Model
{
    protected string $table = 'leads';

    /** Colunas permitidas para ordenação (evita SQL injection via ORDER BY) */
    private array $sortableColumns = [
        'id', 'name', 'city', 'state', 'source', 'interest', 'status',
        'created_at', 'updated_at', 'last_contact_at', 'next_contact_at', 'lead_score',
    ];

    /**
     * Busca paginada com filtros.
     *
     * @param array $filters ['search','state','source','interest','status','assigned_to']
     * @param int   $page
     * @param int   $perPage
     * @param string $sortBy
     * @param string $sortDir 'ASC'|'DESC'
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 20, string $sortBy = 'created_at', string $sortDir = 'DESC'): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        if (!in_array($sortBy, $this->sortableColumns, true)) {
            $sortBy = 'created_at';
        }
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT l.*, u.name AS assigned_name, lr.name AS loss_reason_name
                FROM leads l
                LEFT JOIN users u ON u.id = l.assigned_to
                LEFT JOIN loss_reasons lr ON lr.id = l.loss_reason_id
                {$where}
                ORDER BY l.{$sortBy} {$sortDir}
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        $total = $this->countFiltered($filters);

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Retorna TODOS os leads que casam com os filtros (sem paginação),
     * usado pelos relatórios exportáveis (CSV/Excel/Impressão).
     */
    public function filtered(array $filters = [], string $sortBy = 'created_at', string $sortDir = 'DESC'): array
    {
        if (!in_array($sortBy, $this->sortableColumns, true)) {
            $sortBy = 'created_at';
        }
        $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

        [$where, $params] = $this->buildWhere($filters);

        $sql = "SELECT l.*, u.name AS assigned_name, lr.name AS loss_reason_name
                FROM leads l
                LEFT JOIN users u ON u.id = l.assigned_to
                LEFT JOIN loss_reasons lr ON lr.id = l.loss_reason_id
                {$where}
                ORDER BY l.{$sortBy} {$sortDir}
                LIMIT 5000";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countFiltered(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = "SELECT COUNT(*) AS total FROM leads l {$where}";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $row = $stmt->fetch();
        return (int) ($row['total'] ?? 0);
    }

    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['search'])) {
            $conditions[] = "(l.name LIKE :search OR l.phone LIKE :search OR l.whatsapp LIKE :search OR l.email LIKE :search OR l.city LIKE :search)";
            $params[':search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['state'])) {
            $conditions[] = "l.state = :state";
            $params[':state'] = $filters['state'];
        }
        if (!empty($filters['source'])) {
            $conditions[] = "l.source = :source";
            $params[':source'] = $filters['source'];
        }
        if (!empty($filters['interest'])) {
            $conditions[] = "l.interest = :interest";
            $params[':interest'] = $filters['interest'];
        }
        if (!empty($filters['status'])) {
            $conditions[] = "l.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['assigned_to'])) {
            $conditions[] = "l.assigned_to = :assigned_to";
            $params[':assigned_to'] = $filters['assigned_to'];
        }
        if (!empty($filters['date_from'])) {
            $conditions[] = "DATE(l.created_at) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $conditions[] = "DATE(l.created_at) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        if (!empty($filters['closed_from'])) {
            $conditions[] = "DATE(l.closed_at) >= :closed_from";
            $params[':closed_from'] = $filters['closed_from'];
        }
        if (!empty($filters['closed_to'])) {
            $conditions[] = "DATE(l.closed_at) <= :closed_to";
            $params[':closed_to'] = $filters['closed_to'];
        }

        // ---- Filtros "acionáveis" usados pelos insights do Dashboard (Fase 5) ----

        // Leads sem contato há N dias: nunca contatados (last_contact_at NULL,
        // cadastrados há mais de N dias) OU contatados pela última vez há mais
        // de N dias, e ainda em andamento (mesmo critério de Lead::countWithoutContact()).
        if (!empty($filters['sem_contato_dias'])) {
            $days = (int) $filters['sem_contato_dias'];
            $conditions[] = "(
                (l.last_contact_at IS NULL AND l.created_at <= DATE_SUB(NOW(), INTERVAL :sem_contato_dias_1 DAY))
                OR (l.last_contact_at IS NOT NULL AND l.last_contact_at <= DATE_SUB(NOW(), INTERVAL :sem_contato_dias_2 DAY))
            )
            AND l.status NOT IN ('fechado','perdido','sem_interesse','sem_entrada','numero_invalido','bloqueou','duplicado')";
            $params[':sem_contato_dias_1'] = $days;
            $params[':sem_contato_dias_2'] = $days;
        }

        // Leads "vencidos": next_contact_at já passou e o lead segue ativo.
        if (!empty($filters['vencidos'])) {
            $conditions[] = "l.next_contact_at IS NOT NULL AND l.next_contact_at < NOW()
                AND l.status NOT IN ('fechado','perdido','sem_interesse','sem_entrada','numero_invalido','bloqueou','duplicado')";
        }

        $where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return [$where, $params];
    }

    /**
     * Busca rápida (Fase 7 - auditoria UX) usada pela busca global do topbar
     * (ver LeadController::quickSearch()): nome, telefone, whatsapp, e-mail
     * ou lead_code, limitada a poucos resultados para um dropdown AJAX.
     */
    public function quickSearch(string $term, ?int $onlyAssignedTo = null, int $limit = 8): array
    {
        $conditions = ["(CAST(l.id AS CHAR) LIKE :term OR l.name LIKE :term OR l.phone LIKE :term OR l.whatsapp LIKE :term OR l.email LIKE :term OR l.lead_code LIKE :term)"];
        $params = [':term' => '%' . $term . '%'];

        if ($onlyAssignedTo !== null) {
            $conditions[] = 'l.assigned_to = :assigned_to';
            $params[':assigned_to'] = $onlyAssignedTo;
        }

        $limit = max(1, min(20, $limit));

        $sql = "SELECT l.id, l.lead_code, l.name, l.phone, l.whatsapp, l.email, l.status
                FROM leads l
                WHERE " . implode(' AND ', $conditions) . "
                ORDER BY CASE WHEN CAST(l.id AS CHAR) = :exact OR l.lead_code = :exact THEN 0 WHEN l.name LIKE :prefix THEN 1 ELSE 2 END, l.updated_at DESC
                LIMIT {$limit}";

        $params[':exact'] = $term;
        $params[':prefix'] = $term . '%';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function findWithAssigned(int $id)
    {
        $stmt = $this->db->prepare(
            "SELECT l.*, u.name AS assigned_name, lr.name AS loss_reason_name
             FROM leads l
             LEFT JOIN users u ON u.id = l.assigned_to
             LEFT JOIN loss_reasons lr ON lr.id = l.loss_reason_id
             WHERE l.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Verifica duplicidade por whatsapp, telefone, cpf ou email.
     * Retorna o primeiro lead encontrado ou null.
     */
    public function findDuplicate(?string $phone, ?string $whatsapp, ?string $cpf, ?string $email, ?int $excludeId = null)
    {
        $conditions = [];
        $params = [];

        if (!empty($phone)) {
            $conditions[] = "phone = :phone";
            $params[':phone'] = $phone;
        }
        if (!empty($whatsapp)) {
            $conditions[] = "whatsapp = :whatsapp";
            $params[':whatsapp'] = $whatsapp;
        }
        if (!empty($cpf)) {
            $conditions[] = "cpf = :cpf";
            $params[':cpf'] = $cpf;
        }
        if (!empty($email)) {
            $conditions[] = "email = :email";
            $params[':email'] = $email;
        }

        if (empty($conditions)) {
            return null;
        }

        $sql = "SELECT * FROM leads WHERE (" . implode(' OR ', $conditions) . ")";
        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $sql .= " LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function create(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($c) => ':' . $c, $columns);

        $sql = "INSERT INTO leads (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);

        foreach ($data as $key => $value) {
            $this->bindTyped($stmt, ':' . $key, $value);
        }
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Gera um código único de lead no formato LEAD-YYYYMMDD-NNNN (Fase 4),
     * onde NNNN é um sequencial de 4 dígitos que reinicia todo dia.
     *
     * Usa uma transação com SELECT ... FOR UPDATE como "lock leve" para
     * reduzir a chance de duas requisições simultâneas calcularem o mesmo
     * sequencial. Isso sozinho não é 100% à prova de concorrência (o MySQL
     * só bloqueia linhas que já existem), então a proteção definitiva fica
     * por conta do retry em createWithLeadCode(), que tenta novamente com
     * um novo código se o INSERT esbarrar na constraint UNIQUE de lead_code.
     */
    public function generateLeadCode(): string
    {
        $prefix = 'LEAD-' . date('Ymd') . '-';
        $count = null;

        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS total FROM leads WHERE DATE(created_at) = CURDATE() FOR UPDATE"
            );
            $stmt->execute();
            $count = (int) $stmt->fetch()['total'];
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Lead::generateLeadCode - fallback sem lock: ' . $e->getMessage());
            $count = $this->countToday();
        }

        $sequential = $count + 1;
        return $prefix . str_pad((string) $sequential, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Cria um lead gerando automaticamente o lead_code (Fase 4).
     * Se o INSERT falhar por violação da constraint UNIQUE (lead_code
     * duplicado por concorrência entre duas requisições simultâneas),
     * gera um novo código e tenta novamente algumas vezes.
     *
     * @return array{id:int, lead_code:string}
     */
    public function createWithLeadCode(array $data): array
    {
        $maxAttempts = 5;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $code = $this->generateLeadCode();
            $data['lead_code'] = $code;

            try {
                $id = $this->create($data);
                return ['id' => $id, 'lead_code' => $code];
            } catch (PDOException $e) {
                $lastError = $e;
                // SQLSTATE 23000 = violação de constraint única (lead_code já usado)
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                usleep(30000 + random_int(0, 70000));
            }
        }

        throw $lastError ?? new RuntimeException('Não foi possível gerar um código de lead único.');
    }

    public function update(int $id, array $data): bool
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = "{$col} = :{$col}";
        }

        $sql = "UPDATE leads SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        foreach ($data as $key => $value) {
            $this->bindTyped($stmt, ':' . $key, $value);
        }
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function updateStatus(int $id, string $status): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE leads
             SET status = :status,
                 closed_at = CASE
                    WHEN :status_for_close = 'fechado' THEN COALESCE(closed_at, NOW())
                    ELSE NULL
                 END,
                 closed_by = CASE
                    WHEN :status_for_close_by = 'fechado' THEN COALESCE(closed_by, assigned_to)
                    ELSE NULL
                 END
             WHERE id = :id"
        );
        return $stmt->execute([
            ':status' => $status,
            ':status_for_close' => $status,
            ':status_for_close_by' => $status,
            ':id' => $id,
        ]);
    }

    /**
     * Faz bind de um valor em um PDOStatement escolhendo o tipo PDO correto,
     * garantindo que valores NULL sejam gravados como SQL NULL (e não como
     * string vazia) e que booleanos/inteiros sejam gravados com o tipo certo.
     */
    private function bindTyped(PDOStatement $stmt, string $param, $value): void
    {
        if ($value === null) {
            $stmt->bindValue($param, null, PDO::PARAM_NULL);
            return;
        }

        if (is_bool($value)) {
            $stmt->bindValue($param, $value ? 1 : 0, PDO::PARAM_INT);
            return;
        }

        if (is_int($value)) {
            $stmt->bindValue($param, $value, PDO::PARAM_INT);
            return;
        }

        $stmt->bindValue($param, $value, PDO::PARAM_STR);
    }

    // ---- Métodos de suporte ao Dashboard (KPIs e gráficos) ----

    public function countToday(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) AS total FROM leads WHERE DATE(created_at) = CURDATE()");
        return (int) $stmt->fetch()['total'];
    }

    public function countThisWeek(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) AS total FROM leads WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)");
        return (int) $stmt->fetch()['total'];
    }

    public function countThisMonth(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) AS total FROM leads WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())");
        return (int) $stmt->fetch()['total'];
    }

    public function countByStatuses(array $statuses): int
    {
        if (empty($statuses)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(*) AS total FROM leads WHERE status IN ({$placeholders})");
        $stmt->execute($statuses);
        return (int) $stmt->fetch()['total'];
    }

    public function countWithoutContact(int $days = 5): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM leads
             WHERE last_contact_at IS NULL
             AND created_at <= DATE_SUB(NOW(), INTERVAL :days DAY)
             AND status NOT IN ('fechado','perdido','sem_interesse','duplicado')"
        );
        $stmt->execute([':days' => $days]);
        return (int) $stmt->fetch()['total'];
    }

    public function leadsPerDay(int $days = 30): array
    {
        $stmt = $this->db->prepare(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM leads
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
             GROUP BY DATE(created_at)
             ORDER BY day ASC"
        );
        $stmt->execute([':days' => $days]);
        return $stmt->fetchAll();
    }

    public function leadsBySource(): array
    {
        $stmt = $this->db->query(
            "SELECT COALESCE(source, 'outros') AS source, COUNT(*) AS total
             FROM leads GROUP BY source ORDER BY total DESC"
        );
        return $stmt->fetchAll();
    }

    public function leadsByState(): array
    {
        $stmt = $this->db->query(
            "SELECT COALESCE(state, 'N/D') AS state, COUNT(*) AS total
             FROM leads GROUP BY state ORDER BY total DESC LIMIT 15"
        );
        return $stmt->fetchAll();
    }

    public function leadsByStatus(): array
    {
        $stmt = $this->db->query(
            "SELECT status, COUNT(*) AS total FROM leads GROUP BY status ORDER BY total DESC"
        );
        return $stmt->fetchAll();
    }

    public function topStateShare(): ?array
    {
        $rows = $this->leadsByState();
        if (empty($rows)) {
            return null;
        }
        $total = array_sum(array_column($rows, 'total'));
        if ($total === 0) {
            return null;
        }
        $top = $rows[0];
        return [
            'state' => $top['state'],
            'total' => (int) $top['total'],
            'percentage' => round(($top['total'] / $total) * 100, 1),
        ];
    }
}
