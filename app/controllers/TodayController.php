<?php
/**
 * app/controllers/TodayController.php
 * "Meu Dia" (Fase 7): visão unificada e pessoal do que o usuário logado
 * precisa fazer agora, combinando três fontes que já existem no sistema
 * (sem duplicar lógica de negócio, só reaproveitando os mesmos critérios):
 *
 *  - Leads atrasados: mesmo critério de "atrasados" da Agenda
 *    (AgendaController::index) - next_contact_at no passado.
 *  - Tarefas de hoje/atrasadas: Task::dueTodayOrOverdueForUser(), atribuídas
 *    ao usuário logado.
 *  - Leads sem primeiro contato: mesmo critério do SLA (SlaController::index)
 *    - nenhum registro em lead_history dos tipos 'contato'/'whatsapp'/
 *    'ligacao', criado há mais de 24h - restrito aos leads do usuário logado.
 *
 * Também exibe o progresso da meta pessoal do mês (Fase 7), se cadastrada
 * em Configurações > Metas (ver GoalController/UserGoal).
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/models/Task.php';
require_once APP_PATH . '/models/UserGoal.php';

class TodayController extends Controller
{
    /** Mesma lista de status "encerrados" usada na Agenda e no SLA. */
    private const CLOSED_STATUSES = ['fechado', 'perdido', 'sem_interesse', 'sem_entrada', 'numero_invalido', 'bloqueou', 'duplicado'];

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function index(): void
    {
        $this->requireLogin();

        $userId = (int) Auth::id();

        // ---- Leads atrasados (mesmo critério da Agenda: next_contact_at no passado) ----
        $stmt = $this->db->prepare(
            "SELECT l.id, l.name, l.phone, l.whatsapp, l.status, l.interest, l.next_contact_at
             FROM leads l
             WHERE l.next_contact_at IS NOT NULL
               AND l.next_contact_at < NOW()
               AND l.assigned_to = :user_id
             ORDER BY l.next_contact_at ASC"
        );
        $stmt->execute([':user_id' => $userId]);
        $overdueLeads = $stmt->fetchAll();

        // ---- Tarefas de hoje/atrasadas (Task model) ----
        $taskModel = new Task();
        $overdueTasks = $taskModel->dueTodayOrOverdueForUser($userId);

        // ---- Leads sem primeiro contato (mesmo critério do SLA: sem NENHUM
        // registro em lead_history dos tipos 'contato'/'whatsapp'/'ligacao',
        // criado há mais de 24h), restrito ao usuário logado ----
        $closedPlaceholders = implode(',', array_fill(0, count(self::CLOSED_STATUSES), '?'));
        $firstContactSql = "
            SELECT lh.lead_id, MIN(lh.created_at) AS first_contact_at
            FROM lead_history lh
            WHERE lh.type IN ('contato', 'whatsapp', 'ligacao')
            GROUP BY lh.lead_id
        ";
        $sql = "SELECT l.id, l.name, l.phone, l.whatsapp, l.status, l.interest, l.created_at
                FROM leads l
                LEFT JOIN ({$firstContactSql}) fc ON fc.lead_id = l.id
                WHERE fc.lead_id IS NULL
                  AND l.created_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  AND l.status NOT IN ({$closedPlaceholders})
                  AND l.assigned_to = ?
                ORDER BY l.created_at ASC
                LIMIT 100";
        $params = self::CLOSED_STATUSES;
        $params[] = $userId;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $leadsWithoutContact = $stmt->fetchAll();

        // ---- Meta pessoal do mês (Fase 7) ----
        $goalModel = new UserGoal();
        $year = (int) date('Y');
        $month = (int) date('n');
        $goalProgress = $goalModel->progressForUser($userId, $year, $month);

        // ---- Lista combinada, ordenada por urgência ----
        $combined = [];
        foreach ($overdueLeads as $lead) {
            $combined[] = [
                'kind'     => 'lead_overdue',
                'urgency'  => strtotime((string) $lead['next_contact_at']),
                'title'    => $lead['name'] ?: ('Lead #' . $lead['id']),
                'subtitle' => 'Contato agendado para ' . format_date($lead['next_contact_at'], true),
                'data'     => $lead,
            ];
        }
        foreach ($overdueTasks as $task) {
            $combined[] = [
                'kind'     => 'task',
                'urgency'  => strtotime((string) $task['due_at']),
                'title'    => $task['title'],
                'subtitle' => 'Prazo: ' . format_date($task['due_at'], true) . (!empty($task['lead_name']) ? ' · Lead: ' . $task['lead_name'] : ''),
                'data'     => $task,
            ];
        }
        foreach ($leadsWithoutContact as $lead) {
            $combined[] = [
                'kind'     => 'lead_no_contact',
                'urgency'  => strtotime((string) $lead['created_at']),
                'title'    => $lead['name'] ?: ('Lead #' . $lead['id']),
                'subtitle' => 'Cadastrado em ' . format_date($lead['created_at'], true) . ' - ainda sem contato',
                'data'     => $lead,
            ];
        }
        usort($combined, fn($a, $b) => $a['urgency'] <=> $b['urgency']);

        $this->view('today/index', [
            'pageTitle'           => 'Meu Dia',
            'overdueLeads'        => $overdueLeads,
            'overdueTasks'        => $overdueTasks,
            'leadsWithoutContact' => $leadsWithoutContact,
            'combined'            => $combined,
            'goalProgress'        => $goalProgress,
        ]);
    }
}
