<?php
/**
 * app/models/UserGoal.php
 * Metas mensais por função comercial. Vendedor acompanha fechamentos e
 * valor vendido; SDR acompanha leads trabalhados; supervisor acompanha o
 * valor vendido pela equipe do seu departamento.
 */

require_once APP_PATH . '/core/Model.php';

class UserGoal extends Model
{
    protected string $table = 'user_goals';

    public function forUserMonth(int $userId, int $year, int $month)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM user_goals WHERE user_id = :user_id AND year = :year AND month = :month LIMIT 1"
        );
        $stmt->execute([':user_id' => $userId, ':year' => $year, ':month' => $month]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /** Lista as metas do mês/ano informado, com nome do usuário (para tela de gestão). */
    public function allForMonth(int $year, int $month): array
    {
        $stmt = $this->db->prepare(
            "SELECT u.id AS user_id, u.name AS user_name, u.role, u.commercial_function, u.department_id,
                    g.id AS goal_id, g.target_closed_deals, g.target_new_leads, g.target_sales_value
             FROM users u
             LEFT JOIN user_goals g ON g.user_id = u.id AND g.year = :year AND g.month = :month
             WHERE u.active = 1
             ORDER BY u.name ASC"
        );
        $stmt->execute([':year' => $year, ':month' => $month]);
        return $stmt->fetchAll();
    }

    /** Cria ou atualiza a meta do usuário para o mês/ano (upsert via UNIQUE KEY). */
    public function upsert(int $userId, int $year, int $month, int $targetClosedDeals, ?int $targetNewLeads, ?float $targetSalesValue): bool
    {
        $stmt = $this->db->prepare(
            "INSERT INTO user_goals (user_id, year, month, target_closed_deals, target_new_leads, target_sales_value, created_at, updated_at)
             VALUES (:user_id, :year, :month, :target_closed_deals, :target_new_leads, :target_sales_value, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                target_closed_deals = VALUES(target_closed_deals),
                target_new_leads = VALUES(target_new_leads),
                target_sales_value = VALUES(target_sales_value),
                updated_at = NOW()"
        );
        return $stmt->execute([
            ':user_id'             => $userId,
            ':year'                => $year,
            ':month'               => $month,
            ':target_closed_deals' => $targetClosedDeals,
            ':target_new_leads'    => $targetNewLeads,
            ':target_sales_value'  => $targetSalesValue,
        ]);
    }

    /**
     * Quantidade de leads fechados pelo usuário no mês/ano informado.
     * Usa closed_at como referência. COALESCE mantém compatibilidade com
     * registros antigos criados antes da migration de valor de fechamento.
     */
    public function closedDealsForUser(int $userId, int $year, int $month): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM leads
             WHERE COALESCE(closed_by, assigned_to) = :user_id
               AND status = 'fechado'
               AND YEAR(COALESCE(closed_at, updated_at)) = :year
               AND MONTH(COALESCE(closed_at, updated_at)) = :month"
        );
        $stmt->execute([':user_id' => $userId, ':year' => $year, ':month' => $month]);
        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    /** Quantidade de novos leads atribuídos ao usuário e criados no mês/ano informado. */
    public function newLeadsForUser(int $userId, int $year, int $month): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM leads
             WHERE assigned_to = :user_id
               AND YEAR(created_at) = :year
               AND MONTH(created_at) = :month"
        );
        $stmt->execute([':user_id' => $userId, ':year' => $year, ':month' => $month]);
        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    /** Leads distintos em que o SDR registrou um atendimento no mês. */
    public function workedLeadsForUser(int $userId, int $year, int $month): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT lead_id) AS total
             FROM lead_history
             WHERE user_id = :user_id
               AND type IN ('contato', 'whatsapp', 'ligacao')
               AND YEAR(created_at) = :year
               AND MONTH(created_at) = :month"
        );
        $stmt->execute([':user_id' => $userId, ':year' => $year, ':month' => $month]);
        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    /** Valor fechado pelo próprio vendedor no mês. */
    public function salesValueForUser(int $userId, int $year, int $month): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(COALESCE(closed_value, 0)), 0) AS total
             FROM leads
             WHERE COALESCE(closed_by, assigned_to) = :user_id
               AND status = 'fechado'
               AND YEAR(COALESCE(closed_at, updated_at)) = :year
               AND MONTH(COALESCE(closed_at, updated_at)) = :month"
        );
        $stmt->execute([':user_id' => $userId, ':year' => $year, ':month' => $month]);
        return (float) ($stmt->fetch()['total'] ?? 0);
    }

    /** Valor fechado pela equipe do supervisor (usuários do mesmo departamento). */
    public function teamSalesValueForUser(int $userId, int $year, int $month): float
    {
        $profile = $this->userProfile($userId);
        $departmentId = (int) ($profile['department_id'] ?? 0);
        if ($departmentId <= 0) {
            return $this->salesValueForUser($userId, $year, $month);
        }

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(COALESCE(l.closed_value, 0)), 0) AS total
             FROM leads l
             INNER JOIN users u ON u.id = COALESCE(l.closed_by, l.assigned_to)
             WHERE u.department_id = :department_id
               AND l.status = 'fechado'
               AND YEAR(COALESCE(l.closed_at, l.updated_at)) = :year
               AND MONTH(COALESCE(l.closed_at, l.updated_at)) = :month"
        );
        $stmt->execute([':department_id' => $departmentId, ':year' => $year, ':month' => $month]);
        return (float) ($stmt->fetch()['total'] ?? 0);
    }

    /**
     * Retorna os indicadores aplicáveis à função comercial do usuário, já
     * formatados para a tela Meu Dia. Uma função pode ter mais de um alvo.
     */
    public function progressForUser(int $userId, int $year, int $month): array
    {
        $goal = $this->forUserMonth($userId, $year, $month);
        if (!$goal) {
            return [];
        }

        $profile = $this->userProfile($userId);
        $function = $profile['commercial_function'] ?? 'vendedor';
        $progress = [];

        if ($function === 'sdr' && !empty($goal['target_new_leads'])) {
            $progress[] = $this->progressItem(
                'Leads trabalhados',
                $this->workedLeadsForUser($userId, $year, $month),
                (int) $goal['target_new_leads'],
                'quantidade',
                'fa-headset'
            );
        }

        if ($function === 'supervisor' && !empty($goal['target_sales_value'])) {
            $progress[] = $this->progressItem(
                'Vendas da equipe',
                $this->teamSalesValueForUser($userId, $year, $month),
                (float) $goal['target_sales_value'],
                'moeda',
                'fa-people-group'
            );
        }

        if ($function === 'vendedor') {
            if (!empty($goal['target_closed_deals'])) {
                $progress[] = $this->progressItem(
                    'Fechamentos',
                    $this->closedDealsForUser($userId, $year, $month),
                    (int) $goal['target_closed_deals'],
                    'quantidade',
                    'fa-handshake'
                );
            }
            if (!empty($goal['target_sales_value'])) {
                $progress[] = $this->progressItem(
                    'Valor vendido',
                    $this->salesValueForUser($userId, $year, $month),
                    (float) $goal['target_sales_value'],
                    'moeda',
                    'fa-sack-dollar'
                );
            }
        }

        return $progress;
    }

    private function userProfile(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT commercial_function, department_id FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch() ?: [];
    }

    private function progressItem(string $label, float $current, float $target, string $format, string $icon): array
    {
        return [
            'label'      => $label,
            'current'    => $current,
            'target'     => $target,
            'percentage' => min(100, (int) round(($current / $target) * 100)),
            'format'     => $format,
            'icon'       => $icon,
        ];
    }
}
