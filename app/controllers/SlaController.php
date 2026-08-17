<?php
/**
 * app/controllers/SlaController.php
 * Dashboard de SLA (Fase 2): tempo médio até o primeiro contato, % de leads
 * respondidos em menos de 15 minutos, leads aguardando há mais de 24h sem
 * contato e SLA comparativo por consultor.
 *
 * "Primeiro contato"/"primeira resposta" (Fase 5) = primeiro registro em
 * lead_history do tipo 'contato', 'whatsapp' ou 'ligacao' (qualquer contato
 * real feito pela equipe conta como resposta ao lead, não só o registro
 * manual "contato"). Ver bloco explicativo em app/views/sla/index.php.
 * Todas as queries usam PDO com prepared statements.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Database.php';

class SlaController extends Controller
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        $this->requireLogin();

        // Subquery: primeira resposta (MIN created_at) por lead, considerando
        // QUALQUER evento real de contato da equipe: ligação, WhatsApp ou
        // registro manual de "contato" (Fase 5 - ver comentário no topo do
        // arquivo e o card explicativo em app/views/sla/index.php).
        $firstContactSql = "
            SELECT lh.lead_id, MIN(lh.created_at) AS first_contact_at
            FROM lead_history lh
            WHERE lh.type IN ('contato', 'whatsapp', 'ligacao')
            GROUP BY lh.lead_id
        ";

        // ---- KPI: tempo médio até o primeiro contato (em minutos) ----
        $stmt = $this->db->query(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, l.created_at, fc.first_contact_at)) AS avg_minutes,
                    COUNT(*) AS total_respondidos
             FROM leads l
             INNER JOIN ({$firstContactSql}) fc ON fc.lead_id = l.id"
        );
        $row = $stmt->fetch();
        $avgMinutes = $row['avg_minutes'] !== null ? round((float) $row['avg_minutes'], 1) : null;
        $totalRespondidos = (int) ($row['total_respondidos'] ?? 0);

        // ---- KPI: % respondidos em menos de 15 minutos ----
        $stmt = $this->db->query(
            "SELECT
                SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, l.created_at, fc.first_contact_at) <= 15 THEN 1 ELSE 0 END) AS ate_15min,
                COUNT(*) AS total
             FROM leads l
             INNER JOIN ({$firstContactSql}) fc ON fc.lead_id = l.id"
        );
        $row = $stmt->fetch();
        $total = (int) ($row['total'] ?? 0);
        $ate15 = (int) ($row['ate_15min'] ?? 0);
        $pctAte15 = $total > 0 ? round(($ate15 / $total) * 100, 1) : 0.0;

        // ---- KPI: leads aguardando há mais de 24h sem NENHUM contato ----
        $stmt = $this->db->query(
            "SELECT COUNT(*) AS total
             FROM leads l
             LEFT JOIN ({$firstContactSql}) fc ON fc.lead_id = l.id
             WHERE fc.lead_id IS NULL
               AND l.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND l.status NOT IN ('fechado','perdido','sem_interesse','sem_entrada','numero_invalido','bloqueou','duplicado')"
        );
        $waitingOver24h = (int) ($stmt->fetch()['total'] ?? 0);

        // ---- Lista dos leads aguardando (para a tabela) ----
        $stmt = $this->db->query(
            "SELECT l.id, l.name, l.phone, l.whatsapp, l.created_at, u.name AS assigned_name
             FROM leads l
             LEFT JOIN ({$firstContactSql}) fc ON fc.lead_id = l.id
             LEFT JOIN users u ON u.id = l.assigned_to
             WHERE fc.lead_id IS NULL
               AND l.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND l.status NOT IN ('fechado','perdido','sem_interesse','sem_entrada','numero_invalido','bloqueou','duplicado')
             ORDER BY l.created_at ASC
             LIMIT 100"
        );
        $waitingLeads = $stmt->fetchAll();

        // ---- SLA por consultor (tabela comparativa) ----
        $stmt = $this->db->query(
            "SELECT u.id, u.name,
                COUNT(fc.lead_id) AS total_respondidos,
                AVG(TIMESTAMPDIFF(MINUTE, l.created_at, fc.first_contact_at)) AS avg_minutes,
                SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, l.created_at, fc.first_contact_at) <= 15 THEN 1 ELSE 0 END) AS ate_15min,
                (SELECT COUNT(*) FROM leads l2 WHERE l2.assigned_to = u.id) AS total_atribuidos
             FROM users u
             LEFT JOIN leads l ON l.assigned_to = u.id
             LEFT JOIN ({$firstContactSql}) fc ON fc.lead_id = l.id
             WHERE u.active = 1
             GROUP BY u.id, u.name
             ORDER BY AVG(TIMESTAMPDIFF(MINUTE, l.created_at, fc.first_contact_at)) IS NULL,
                      AVG(TIMESTAMPDIFF(MINUTE, l.created_at, fc.first_contact_at)) ASC"
        );
        $byConsultant = $stmt->fetchAll();
        foreach ($byConsultant as &$c) {
            $c['avg_minutes'] = $c['avg_minutes'] !== null ? round((float) $c['avg_minutes'], 1) : null;
            $c['pct_15min'] = ((int) $c['total_respondidos']) > 0
                ? round(((int) $c['ate_15min'] / (int) $c['total_respondidos']) * 100, 1)
                : null;
        }
        unset($c);

        $this->view('sla/index', [
            'pageTitle'        => 'SLA de Atendimento',
            'avgMinutes'       => $avgMinutes,
            'totalRespondidos' => $totalRespondidos,
            'pctAte15'         => $pctAte15,
            'waitingOver24h'   => $waitingOver24h,
            'waitingLeads'     => $waitingLeads,
            'byConsultant'     => $byConsultant,
        ]);
    }
}
