<?php
/**
 * app/models/Notification.php
 * Notificações internas (sino do topbar), exibidas via polling AJAX
 * (ver NotificationController + public/assets/js/app.js). Tabela
 * `notifications` criada em database/sql/migration_fase3.sql.
 */

require_once APP_PATH . '/core/Model.php';
require_once APP_PATH . '/models/UserGoal.php';

class Notification extends Model
{
    protected string $table = 'notifications';

    public function create(int $userId, string $title, ?string $message = null, ?string $link = null): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO notifications (user_id, title, message, link, created_at)
             VALUES (:user_id, :title, :message, :link, NOW())"
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':title'   => $title,
            ':message' => $message,
            ':link'    => $link,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /** Últimas notificações do usuário (lidas e não lidas), mais recentes primeiro. */
    public function forUser(int $userId, int $limit = 15): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT :limit"
        );
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function unreadCount(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM notifications WHERE user_id = :user_id AND read_at IS NULL"
        );
        $stmt->execute([':user_id' => $userId]);
        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    public function markRead(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE notifications SET read_at = NOW() WHERE id = :id AND user_id = :user_id"
        );
        return $stmt->execute([':id' => $id, ':user_id' => $userId]);
    }

    public function markAllRead(int $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE notifications SET read_at = NOW() WHERE user_id = :user_id AND read_at IS NULL"
        );
        return $stmt->execute([':user_id' => $userId]);
    }

    /**
     * Evita notificação duplicada: retorna true se já existe notificação
     * NÃO LIDA para o mesmo usuário com o mesmo link.
     */
    public function hasUnreadForLink(int $userId, string $link): bool
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM notifications WHERE user_id = :user_id AND link = :link AND read_at IS NULL LIMIT 1"
        );
        $stmt->execute([':user_id' => $userId, ':link' => $link]);
        return (bool) $stmt->fetch();
    }

    /**
     * Gera (sob demanda, sem cron) notificações de "lead parado sem contato"
     * para os responsáveis, evitando duplicidade (só cria se ainda não houver
     * notificação não lida para aquele mesmo lead). Chamado a partir do
     * Dashboard e da Agenda ao carregar a tela.
     *
     * @return int Quantidade de notificações novas criadas
     */
    public function generateStaleLeadAlerts(int $days = 5): int
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, assigned_to FROM leads
             WHERE assigned_to IS NOT NULL
             AND last_contact_at IS NULL
             AND created_at <= DATE_SUB(NOW(), INTERVAL :days DAY)
             AND status NOT IN ('fechado','perdido','sem_interesse','duplicado')"
        );
        $stmt->execute([':days' => $days]);
        $leads = $stmt->fetchAll();

        $created = 0;
        foreach ($leads as $lead) {
            $userId = (int) $lead['assigned_to'];
            $link = 'leads/' . $lead['id'];

            if ($this->hasUnreadForLink($userId, $link)) {
                continue;
            }

            $this->create(
                $userId,
                'Lead sem contato',
                'O lead "' . ($lead['name'] ?: 'sem nome') . '" está há ' . $days . ' dia(s) ou mais sem nenhum contato registrado.',
                $link
            );
            $created++;
        }

        return $created;
    }

    /** Gera uma única celebração para cada meta aplicável à função comercial. */
    public function generateGoalReachedAlerts(): int
    {
        try {
            $year = (int) date('Y');
            $month = (int) date('n');
            $stmt = $this->db->prepare(
                "SELECT g.*, u.commercial_function
                 FROM user_goals g
                 INNER JOIN users u ON u.id = g.user_id
                 WHERE g.year = :year AND g.month = :month AND u.active = 1"
            );
            $stmt->execute([':year' => $year, ':month' => $month]);

            $goalModel = new UserGoal();
            $created = 0;
            foreach ($stmt->fetchAll() as $goal) {
                $userId = (int) $goal['user_id'];
                $function = $goal['commercial_function'] ?? 'vendedor';
                $items = [];

                if ($function === 'sdr' && !empty($goal['target_new_leads'])) {
                    $items[] = ['key' => 'leads', 'label' => 'leads trabalhados', 'current' => $goalModel->workedLeadsForUser($userId, $year, $month), 'target' => (int) $goal['target_new_leads'], 'money' => false];
                } elseif ($function === 'supervisor' && !empty($goal['target_sales_value'])) {
                    $items[] = ['key' => 'equipe_valor', 'label' => 'meta de vendas da equipe', 'current' => $goalModel->teamSalesValueForUser($userId, $year, $month), 'target' => (float) $goal['target_sales_value'], 'money' => true];
                } elseif ($function === 'vendedor') {
                    if (!empty($goal['target_closed_deals'])) {
                        $items[] = ['key' => 'fechamentos', 'label' => 'fechamentos', 'current' => $goalModel->closedDealsForUser($userId, $year, $month), 'target' => (int) $goal['target_closed_deals'], 'money' => false];
                    }
                    if (!empty($goal['target_sales_value'])) {
                        $items[] = ['key' => 'valor', 'label' => 'meta de valor vendido', 'current' => $goalModel->salesValueForUser($userId, $year, $month), 'target' => (float) $goal['target_sales_value'], 'money' => true];
                    }
                }

                foreach ($items as $item) {
                    if ($item['current'] < $item['target']) {
                        continue;
                    }
                    $key = 'goal:' . $userId . ':' . $year . '-' . $month . ':' . $item['key'];
                    $insert = $this->db->prepare("INSERT IGNORE INTO notification_events(event_key,user_id) VALUES(:key,:uid)");
                    $insert->execute([':key' => $key, ':uid' => $userId]);
                    if ($insert->rowCount() > 0) {
                        $current = $item['money'] ? format_money($item['current']) : (int) $item['current'];
                        $this->create($userId, 'Meta atingida!', 'Parabéns! Você alcançou ' . $current . ' na meta de ' . $item['label'] . '.', 'metas');
                        $created++;
                    }
                }
            }
            return $created;
        } catch (Throwable $e) {
            return 0;
        }
    }
}
